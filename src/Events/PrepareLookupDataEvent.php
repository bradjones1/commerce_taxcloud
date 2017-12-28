<?php

namespace Drupal\commerce_taxcloud\Events;

use CommerceGuys\Addressing\AddressInterface;
use Drupal\commerce_order\Entity\OrderInterface;
use Symfony\Component\EventDispatcher\Event;

class PrepareLookupDataEvent extends Event {

  /**
   * The order.
   *
   * @var \Drupal\commerce_order\Entity\OrderInterface
   */
  protected $order;

  /**
   * Cart items, already prepared from the order.
   *
   * @var \TaxCloud\CartItem[]
   */
  protected $items;

  /**
   * Origin address to use for tax calculation.
   *
   * @var \CommerceGuys\Addressing\AddressInterface
   */
  protected $origin;

  /**
   * Destination address to use for tax calculation.
   *
   * @var \CommerceGuys\Addressing\AddressInterface
   */
  protected $destination;

  /**
   * @inheritDoc
   */
  public function __construct(OrderInterface $order, $items, AddressInterface $origin, AddressInterface $destination) {
    $this->order = $order;
    $this->items = $items;
    $this->origin = $origin;
    $this->destination = $destination;
  }

  /**
   * Order getter.
   *
   * @return \Drupal\commerce_order\Entity\OrderInterface
   */
  public function getOrder() {
    return $this->order;
  }

  /**
   * Cart items getter.
   *
   * @return \TaxCloud\CartItem[]
   */
  public function getItems() {
    return $this->items;
  }

  /**
   * Cart items setter.
   *
   * @param \TaxCloud\CartItem[] $items
   */
  public function setItems(array $items) {
    $this->items = $items;
  }

  /**
   * Origin address getter.
   *
   * @return \CommerceGuys\Addressing\AddressInterface
   */
  public function getOrigin() {
    return $this->origin;
  }

  /**
   * Origin address setter.
   *
   * @param \CommerceGuys\Addressing\AddressInterface $origin
   */
  public function setOrigin(AddressInterface $origin) {
    $this->origin = $origin;
  }

  /**
   * Destination address getter.
   *
   * @return \CommerceGuys\Addressing\AddressInterface
   */
  public function getDestination() {
    return $this->destination;
  }

  /**
   * Destination address setter.
   *
   * @param \CommerceGuys\Addressing\AddressInterface $destination
   */
  public function setDestination(AddressInterface $destination) {
    $this->destination = $destination;
  }

}
