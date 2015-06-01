<?php
/**
 * Cart.php
 *
 * Part of Overtrue\LaravelShoppingCart.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @author    overtrue <i@overtrue.me>
 * @copyright 2015 overtrue <i@overtrue.me>
 * @link      https://github.com/overtrue
 * @link      http://overtrue.me
 */

namespace Overtrue\LaravelShoppingCart;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Session\SessionManager;
use Illuminate\Support\Collection;

/**
 * Main class of Overtrue\LaravelShoppingCart package.
 */
class Cart
{

    /**
     * Session manager
     *
     * @var \Illuminate\Session\SessionManager
     */
    protected $session;

    /**
     * Event dispatcher
     *
     * @var \Illuminate\Events\Dispatcher
     */
    protected $event;

    /**
     * Current cart name
     *
     * @var string
     */
    protected $name = 'cart.default';

    /**
     * The Eloquent model a cart is associated with
     *
     * @var string
     */
    protected $model;

    /**
     * Constructor
     *
     * @param \Illuminate\Session\SessionManager      $session Session class name
     * @param \Illuminate\Contracts\Events\Dispatcher $event   Event class name
     */
    public function __construct(SessionManager $session, Dispatcher $event)
    {
        $this->session = $session;
        $this->event   = $event;
    }

    /**
     * Set the current cart name
     *
     * @param string $name Cart name name
     *
     * @return Cart
     */
    public function name($name)
    {
        $this->name = 'cart.' . $name;

        return $this;
    }

    /**
     * associated model
     *
     * @param string $modelName The name of the model
     *
     * @return Cart
     */
    public function associate($modelName)
    {
        $this->associatedModel = $modelName;

        if (!class_exists($modelName)) {
            throw new Exception('Invalid model name.');
        }

        return $this;
    }

    /**
     * Get all items.
     *
     * @return \Illuminate\Support\Collection
     */
    public function all()
    {
        return $this->getCart();
    }

    /**
     * Add a row to the cart
     *
     * @param string|array $id      Unique ID of the item|Item formated as array|Array of items
     * @param string       $name    Name of the item
     * @param int          $qty     Item qty to add to the cart
     * @param float        $price   Price of one item
     * @param array        $options Array of additional options, such as 'size' or 'color'
     *
     * @return mixed
     */
    public function add($id, $name = null, $qty = null, $price = null, array $options = [])
    {
        // If the first parameter is an array we need to call the add() function again
        if (is_array($id)) {
            // And if it's not only an array, but a multidimensional array, we need to
            // recursively call the add function
            if (is_array(head($id))) {
                $this->event->fire('cart.batch', $id);

                foreach ($id as $item) {
                    $options = array_get($item, 'options', []);
                    $this->addRow($item['id'], $item['name'], $item['qty'], $item['price'], $options);
                }

                $this->event->fire('cart.batched', $id);

                return;
            }

            $options = array_get($id, 'options', []);

            $this->event->fire('cart.add', array_merge($id, ['options' => $options]));

            $row = $this->addRow($id['id'], $id['name'], $id['qty'], $id['price'], $options);

            $this->event->fire('cart.added', array_merge($id, ['options' => $options]));

            return $raw->id;
        }


        $this->event->fire('cart.add', compact('id', 'name', 'qty', 'price', 'options'));

        $rawId = $this->addRow($id, $name, $qty, $price, $options);

        $this->event->fire('cart.added', compact('id', 'name', 'qty', 'price', 'options'));

        return $rawId;
    }

    /**
     * Update the quantity of one row of the cart
     *
     * @param string    $rowId     The __raw_id of the item you want to update
     * @param int|array $attribute New quantity of the item|Array of attributes to update
     *
     * @return Item
     */
    public function update($rowId, $attribute)
    {
        if (!$this->hasRow($rowId)) {
            throw new Exception('Item not found.');
        }

        $this->event->fire('cart.update', $rowId);

        if (is_array($attribute)) {
            $raw = $this->updateAttribute($rowId, $attribute);
        } else {
            $raw = $this->updateQty($rowId, $attribute);
        }

        $this->event->fire('cart.updated', $rowId);

        return $raw;
    }

    /**
     * Remove a row from the cart
     *
     * @param string $rowId The __raw_id of the item
     *
     * @return boolean
     */
    public function remove($rowId)
    {
        if (!$this->hasRow($rowId)) {
            return true;
        }

        $cart = $this->getCart();

        $this->event->fire('cart.remove', $rowId);

        $cart->forget($rowId);

        $this->event->fire('cart.removed', $rowId);

        return $this->syncCart($cart);
    }

    /**
     * Get a row of the cart by its ID
     *
     * @param string $rowId The ID of the row to fetch
     *
     * @return Item
     */
    public function get($rowId)
    {
        return new Item($this->getCart()->get($rowId));
    }

    /**
     * Clean the cart
     *
     * @return boolean
     */
    public function destroy()
    {
        $this->event->fire('cart.destroy');

        $result = $this->syncCart(null);

        $this->event->fire('cart.destroyed');

        return $result;
    }

    /**
     * Get the price total
     *
     * @return float
     */
    public function total()
    {
        $total = 0;

        $cart = $this->getCart();

        if (empty($cart)) {
            return $total;
        }

        foreach ($cart as $row) {
            $total += $row->qty * $row->price;
        }

        return $total;
    }

    /**
     * Get the number of items in the cart
     *
     * @param boolean $totalItems Get all the items (when false, will return the number of rows)
     *
     * @return int
     */
    public function count($totalItems = true)
    {
        $items = $this->getCart();

        if (!$totalItems) {
            return $items->count();
        }

        $count = 0;

        foreach ($items as $row) {
            $count += $row->qty;
        }

        return $count;
    }

    /**
     * Get rows count
     *
     * @return int
     */
    public function countRows()
    {
        return $this->count(false);
    }

    /**
     * Search if the cart has a item
     *
     * @param array $search An array with the item ID and optional options
     *
     * @return array
     */
    public function search(array $search)
    {
        if (empty($search)) {
            return [];
        }

        $rows = new Collection();

        foreach ($this->getCart() as $item) {
            if (count($item->intersect($search)) == count($search)) {
                $rows->put($item->__raw_id, $item);
            }
        }

        return $rows;
    }

    /**
     * Add row to the cart
     *
     * @param string $id      Unique ID of the item
     * @param string $name    Name of the item
     * @param int    $qty     Item qty to add to the cart
     * @param float  $price   Price of one item
     * @param array  $options Array of additional options, such as 'size' or 'color'
     *
     * @return string
     */
    protected function addRow($id, $name, $qty, $price, array $options = [])
    {
        if (!is_numeric($qty)) {
            throw new Exception('Invalid quantity.');
        }

        if (!is_numeric($price)) {
            throw new Exception('Invalid price.');
        }

        $cart = $this->getCart();

        $rowId = $this->generateRawId($id, $options);

        if ($row = $cart->get($rowId)) {
            $row = $this->updateQty($rowId, $row->qty + $qty);
        } else {
            $row = $this->insertRow($rowId, $id, $name, $qty, $price, $options);
        }

        return $row;
    }

    /**
     * Generate a unique id for the new row
     *
     * @param string $id      Unique ID of the item
     * @param array  $options Array of additional options, such as 'size' or 'color'
     *
     * @return string
     */
    protected function generateRawId($id, $options)
    {
        ksort($options);

        return md5($id . serialize($options));
    }

    /**
     * Check if a __raw_id exists in the current cart name
     *
     * @param string $rowId Unique ID of the item
     *
     * @return boolean
     */
    protected function hasRow($rowId)
    {
        return $this->getCart()->has($rowId);
    }

    /**
     * Sync the cart to session.
     *
     * @param array $cart The new cart content
     *
     * @return \Illuminate\Support\Collection
     */
    protected function syncCart($cart)
    {
        $this->session->put($this->name, $cart);

        return $cart;
    }

    /**
     * Get the carts content.
     *
     * @return \Illuminate\Support\Collection
     */
    protected function getCart()
    {
        $cart = $this->session->get($this->name);

        return $cart instanceof Collection ? $cart : new Collection();
    }

    /**
     * Update a row if the rowId already exists.
     *
     * @param string    $rowId      The ID of the row to update
     * @param int|array $attributes The quantity to add to the row
     *
     * @return Item
     */
    protected function updateRow($rowId, $attributes)
    {
        $cart = $this->getCart();

        $row = $cart->get($rowId);

        foreach ($attributes as $key => $value) {
            $row->put($key, $value);
        }

        if (!empty(array_keys($attributes, ['qty', 'price']))) {
            $row->put('total', $row->qty * $row->price);
        }

        $cart->put($rowId, $row);

        return $row;
    }

    /**
     * Create a new row Object.
     *
     * @param string $rowId   The ID of the new row
     * @param string $id      Unique ID of the item
     * @param string $name    Name of the item
     * @param int    $qty     Item qty to add to the cart
     * @param float  $price   Price of one item
     * @param array  $options Array of additional options, such as 'size' or 'color'
     *
     * @return Item
     */
    protected function insertRow($rowId, $id, $name, $qty, $price, $options = [])
    {
        $newRow = $this->makeRow($rowId, $id, $name, $qty, $price, $options);

        $cart = $this->getCart();

        $cart->put($rowId, $newRow);

        $this->syncCart($cart);

        return $newRow;
    }

    /**
     * Make a row item.
     *
     * @param string $rowId   Raw id.
     * @param mixed  $id      Item id.
     * @param string $name    Item name.
     * @param int    $qty     Quantity.
     * @param float  $price   Price.
     * @param array  $options Other attributes.
     *
     * @return Item
     */
    protected function makeRow($rowId, $id, $name, $qty, $price, array $options = [])
    {
        return new Item(array_merge([
                                     '__raw_id' => $rowId,
                                     'id'       => $id,
                                     'name'     => $name,
                                     'qty'      => $qty,
                                     'price'    => $price,
                                     'total'    => $qty * $price,
                                     '__model'  => $this->model,
                                    ], $options));
    }

    /**
     * Update the quantity of a row
     *
     * @param string $rowId The ID of the row
     * @param int    $qty   The qty to add
     *
     * @return Item
     */
    protected function updateQty($rowId, $qty)
    {
        if ($qty <= 0) {
            return $this->remove($rowId);
        }

        return $this->updateRow($rowId, ['qty' => $qty]);
    }

    /**
     * Update an attribute of the row
     *
     * @param string $rowId      The ID of the row
     * @param array  $attributes An array of attributes to update
     *
     * @return Item
     */
    protected function updateAttribute($rowId, $attributes)
    {
        return $this->updateRow($rowId, $attributes);
    }
}//end class