<?php

namespace Drupal\commerce_taxcloud\Plugin\Commerce\TaxType;

use CommerceGuys\Addressing\AddressInterface;
use Drupal\commerce_order\Adjustment;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\commerce_price\RounderInterface;
use Drupal\commerce_tax\Annotation\CommerceTaxType;
use Drupal\commerce_tax\Plugin\Commerce\TaxType\TaxTypeBase;
use Drupal\Core\Annotation\Translation;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\profile\Entity\ProfileInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use TaxCloud\Address;
use TaxCloud\CartItem;
use TaxCloud\Client;
use TaxCloud\Exceptions\LookupException;
use TaxCloud\Request\Lookup;

/**
 * @CommerceTaxType(
 *   id = "taxcloud",
 *   label = @Translation("TaxCloud"),
 * )
 */
class TaxCloud extends TaxTypeBase {

  /**
   * The rounder.
   *
   * @var \Drupal\commerce_price\RounderInterface
   */
  protected $rounder;

  /**
   * Constructs a new TaxTypeBase object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Symfony\Component\EventDispatcher\EventDispatcherInterface $event_dispatcher
   *   The event dispatcher.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_type_manager, EventDispatcherInterface $event_dispatcher, RounderInterface $rounder) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $entity_type_manager, $event_dispatcher);
    $this->rounder = $rounder;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('event_dispatcher'),
      $container->get('commerce_price.rounder')
    );
  }

  /**
   * Helper to convert Drupal's addresses to TaxCloud value objects.
   *
   * @param \CommerceGuys\Addressing\AddressInterface $address
   *
   * @return \TaxCloud\Address
   */
  protected function addressToTaxCloudAddress(AddressInterface $address) {
    return new Address(
      $address->getAddressLine1(),
      $address->getAddressLine2(),
      $address->getLocality(),
      $address->getAdministrativeArea(),
      substr($address->getPostalCode(), 0, 5)
    );
  }

  /**
   * Prepare an array of items for submission to TaxCloud.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *
   * @return \TaxCloud\CartItem[]
   *   The CartItems for TaxCloud.
   */
  protected function prepareTaxCloudItems(OrderInterface $order) {
    $items = [];
    foreach ($order->getItems() as $index => $item) {
      // @todo - TIC configuration and tax-exclusive adjusted total price.
      $items[] = new CartItem(
        $index,
        $item->id(),
        00000,
        $item->getAdjustedTotalPrice(),
        $item->getQuantity()
      );
    }
    return $items;
  }

  /**
   * @inheritDoc
   */
  public function apply(OrderInterface $order) {
    // @see https://dev.taxcloud.com/guides/getting-oriented-with-taxcloud
    if ($order->get('order_items')->isEmpty()) {
      return;
    }
    $prices_include_tax = $order->getStore()->get('prices_include_tax')->value;
    $storeAddress = $order->getStore()->getAddress();
    /** @var \Drupal\address\Plugin\Field\FieldType\AddressItem $destinationAddress */
    $destinationAddress = $this
      ->resolveCustomerProfile($order->get('order_items')->first()->entity)
      ->get('address')->first();
    $request = new Client();
    $items = $this->prepareTaxCloudItems($order);
    // @todo - Configurable api key.
    $lookup = new Lookup(
      'apikey',
      'apikey',
      'customerid',
      $order->id(),
      $items,
      $this->addressToTaxCloudAddress($storeAddress),
      $this->addressToTaxCloudAddress($destinationAddress)
    );
    try {
      $response = $request->Lookup($lookup);
    }
    catch (LookupException $e) {
      // @todo - Log and fail.
    }
    // @todo - Process response.
  }

  /**
   * Get all adjustments for an order item.
   *
   * @param \Drupal\profile\Entity\ProfileInterface $customer_profile
   * @param \Drupal\commerce_order\Entity\OrderItemInterface $order_item
   * @param bool $prices_include_tax Whether order item prices should include tax.
   *
   * @returns \Drupal\commerce_order\Adjustment[] The adjustments.
   */
  protected function getAdjustments(ProfileInterface $customer_profile, OrderItemInterface $order_item, $prices_include_tax = FALSE) {
    $adjustments = [];
    if (!$rate = $this->getRate($customer_profile, $order_item)) {
      return;
    }
    $unit_price = $order_item->getUnitPrice();
    $rate_amount = $rate->getPercentage()->getNumber();
    $adjustment_amount = $unit_price->multiply($rate_amount);
    $adjustment_amount = $this->rounder->round($adjustment_amount);
    if ($prices_include_tax) {
      $divisor = (string) (1 + $rate_amount);
      $adjustment_amount = $adjustment_amount->divide($divisor);
    }
    if ($prices_include_tax && !$this->isDisplayInclusive()) {
      $unit_price = $order_item->getUnitPrice()->subtract($adjustment_amount);
      $order_item->setUnitPrice($unit_price);
    }
    $adjustments[] = new Adjustment([
      'type' => 'tax',
      'label' => $this->getDisplayLabel(),
      'amount' => $adjustment_amount,
      'source_id' => $this->entityId . '|' . $zone->getId() . '|' . $rate->getId(),
      'included' => $this->isDisplayInclusive(),
    ]);
    return $adjustments;
  }

  /**
   * Tax an order item.
   *
   * @param \Drupal\commerce_order\Entity\OrderItemInterface $order_item
   * @param $prices_include_tax
   */
  protected function taxOrderItem(OrderItemInterface $order_item, $prices_include_tax) {
    $customer_profile = $this->resolveCustomerProfile($order_item);
    if (!$customer_profile) {
      return;
    }
    foreach ($this->getAdjustments($customer_profile, $order_item, $prices_include_tax) as $adjustment) {
      $order_item->addAdjustment($adjustment);
    }
  }

}
