<?php namespace Firework\Cart;

use Illuminate\Session\Store;
use Illuminate\Support\Collection;
use Illuminate\Config\Repository;

class Cart {

	protected $config;

	protected $session;

	protected $sessionKey;

	protected $autoSave = false;

	protected $items;

	protected $discount;

	protected $tax;

	public function __construct(Store $session, Repository $config)
	{
		$this->config = $config;
		$this->session = $session;

		// Make items a collection
		$this->items = new Collection;

		// Get items from session
		if ($items = $this->session->get($this->config->get('cart::sessionKey')))
		{
			// Set items
			$this->setItems($items);
		}

		// After construct, we can make it autosave
		$this->setAutoSave($this->config->get('cart::autoSave'));
	}

	/**
	 * Add Item to Cart.
	 *
	 * @param  mixed  $items Array of items, attributes of a item or a Item object
	 *
	 * @return \Firework\Cart
	 */
	public function add($items)
	{
		// Array of items
		if (isset($items[0]) and (is_array($items[0]) or $items[0] instanceof Item))
		{
			foreach ($items as $item)
			{
				$this->add($item);
			}
		}
		// An Item instance
		elseif ($items instanceof Item)
		{
			if ($this->items->has($items->rowId))
			{
				throw new \Exception('This item already exists, dumbass');
			}

			$this->items->put($items->rowId, $item);
		}
		// An array of attributes
		else
		{
			if(empty($items['rowId']))
			{
				$items['rowId'] = $this->createRowId($items['id']);
			}
			elseif ($this->items->has($items['rowId']))
			{
				throw new \Exception('This item already exists, dumbass');
			}

			$item = with(new Item($this))->fill($items);

			$this->items->put($item->rowId, $item);
		}

		// Save it
		$this->autoSave();

		return $this;
	}

	/**
	 * Update items cart.
	 *
	 * @param  mixed  $items Array of items, attributes of a item or a Item object
	 *
	 * @return \Firework\Cart
	 */
	public function update($items)
	{
		// Array of items
		if (isset($items[0]) and (is_array($items[0]) or $items[0] instanceof Item))
		{
			foreach ($items as $item)
			{
				$this->update($item);
			}
		}
		// An Item instance
		elseif ($items instanceof Item)
		{
			if ( ! $this->items->has($items->rowId))
			{
				throw new \Exception('This item already exists, dumbass');
			}

			$this->items->put($items->rowId, $item);
		}
		// An array of attributes
		else
		{
			if (empty($items['rowId']) or ! $this->items->has($items['rowId']))
			{
				throw new \Exception('Baaaaaaaahhhh, something wrong');
			}

			$this->items->get($items['rowId'])->fill($items);
		}

		// Save it
		$this->autoSave();

		return $this;
	}

	/**
	 * Remove specific item from cart.
	 *
	 * @param  string $id
	 * @return bool
	 */
	public function remove($items)
	{
		// Array of items
		if (isset($items[0]))
		{
			foreach ($items as $item)
			{
				$this->remove($item);
			}
		}
		// An instance of Item or array of attributes or rowId
		else
		{
			if ($items instanceof Item)
			{
				$rowId = $items->rowId;
			}
			else
			{
				$rowId = ! empty($items['rowId']) ? $items['rowId'] : $items;
			}

			if ( ! $this->items->has($rowId))
			{
				throw new \Exception('Item not found.');
			}

			$this->items->forget($rowId);
		}

		// Save it
		$this->autoSave();

		return $this;
	}

	/**
	 * Create a new identifier.
	 *
	 * @param  int  $id
	 * @return string
	 */
	protected function createRowId($id)
	{
		return md5(uniqid(rand(), true));
	}

	public function hasItems()
	{
		return ! $this->items->isEmpty();
	}

	/**
	 * Get all items from cart.
	 *
	 * @return mixed
	 */
	public function items()
	{
		return $this->items;
	}

	/**
	 * Get specific item from cart.
	 *
	 * @param  string $id
	 * @return mixed
	 */
	public function item($id)
	{
		return $this->items->get($id);
	}

	/**
	 * Save update.
	 *
	 * @return bool
	 */
	public function save()
	{
		$this->session->put($this->config->get('cart::sessionKey'), $this->items->toArray());

		return true;
	}

	/**
	 * Set auto save.
	 *
	 * @param  bool   $autoSave
	 */
	public function setAutoSave($autoSave)
	{
		$this->autoSave = (boolean) $autoSave;

		return $this;
	}

	/**
	 * Get auto save.
	 *
	 * @return bool
	 */
	public function isAutoSave()
	{
		return $this->autoSave;
	}

	protected function autoSave()
	{
		if ($this->isAutoSave() === true)
		{
			$this->save();
		}
	}

	/**
	 * Clear the cart.
	 *
	 */
	public function destroy()
	{
		$this->session->forget($this->config->get('cart::sessionKey'));

		return $this;
	}

	public function totalPrice()
	{
		$total = 0;

		foreach($this->getItems() as $item)
		{
			$total += $item->calculatePrice();
		}

		return $total;
	}

	/**
	 * Get total price of cart.
	 *
	 * @return float
	 */
	public function total()
	{
		$total = $this->totalPrice();

		if (isset($this->discount))
		{
			$total -= $this->calculatePercentualOrFixed($this->discount);
		}

		if (isset($this->tax))
		{
			$total += $this->calculatePercentualOrFixed($this->tax);
		}

		return $total;
	}

	public function calculatePercentualOrFixed($value)
	{
		if (ends_with($value, '%'))
		{
			return $this->calculatePercentual($value);
		}

		return (float) $value;
	}

	protected function calculatePercentual($percent)
	{
		$percent = (float) substr($percent, 0, -1);

		return $this->totalPrice() / 100 * $percent;
	}

	/**
	 * Get total qty of cart.
	 *
	 * @return int
	 */
	public function totalQty()
	{
		$total = 0;

		foreach($this->getItems() as $item)
		{
			$total += $item->qty;
		}

		return $total;
	}

	/**
	 * Convert to its string representation.
	 *
	 * @return string
	 */
	public function __toString()
	{
		return $this->items->toJson();
	}
}