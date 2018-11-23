<?php

namespace Drupal\commerce_taxcloud\Plugin\Commerce\TaxType;

use CommerceGuys\Addressing\AddressInterface;
use CommerceGuys\Addressing\Subdivision\SubdivisionRepositoryInterface;
use Drupal\commerce_order\Adjustment;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\commerce_price\Price;
use Drupal\commerce_price\RounderInterface;
use Drupal\commerce_tax\Annotation\CommerceTaxType;
use Drupal\commerce_tax\Plugin\Commerce\TaxType\TaxTypeBase;
use Drupal\commerce_taxcloud\Events\CommerceTaxCloudEvents;
use Drupal\commerce_taxcloud\Events\PrepareLookupDataEvent;
use Drupal\Core\Annotation\Translation;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
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
class TaxCloud extends TaxTypeBase implements TaxCloudInterface {

  /**
   * Rounding method per line.
   *
   * @var string
   */
  const ROUNDING_PER_LINE = 'per_line';

  /**
   * Rounding method globally.
   *
   * @var string
   */
  const ROUNDING_GLOBALLY = 'globally';

  /**
   * The rounder.
   *
   * @var \Drupal\commerce_price\RounderInterface
   */
  protected $rounder;

  /**
   * The config.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected $config;

  /**
   * The subdivision repository.
   *
   * @var \CommerceGuys\Addressing\Subdivision\SubdivisionRepositoryInterface
   */
  protected $subdivisionRepository;

  /**
   * Default display label for tax row in checkout form.
   *
   * @var string
   */
  public $defaultDisplayLabel = 'SALES TAXES';

  /**
   * Default rounding method for tax.
   *
   * @var string
   */
  public $defaultRoundingMethod = self::ROUNDING_GLOBALLY;

  /**
   * US states list.
   *
   * @var array
   */
  protected static $statesList;

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
   * @param \Drupal\commerce_price\RounderInterface $rounder
   *   The rounder.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   Config factory.
   * @param \CommerceGuys\Addressing\Subdivision\SubdivisionRepositoryInterface $subdivision_repository
   *   The subdivision repository.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    EntityTypeManagerInterface $entity_type_manager,
    EventDispatcherInterface $event_dispatcher,
    RounderInterface $rounder,
    ConfigFactoryInterface $configFactory,
    SubdivisionRepositoryInterface $subdivision_repository
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $entity_type_manager, $event_dispatcher);
    $this->rounder = $rounder;
    $this->config = $configFactory->get('commerce_taxcloud.settings');
    $this->subdivisionRepository = $subdivision_repository;

    if (!isset(self::$statesList)) {
      foreach ($this->subdivisionRepository->getAll(['US']) as $state) {
        self::$statesList[$state->getCode()] = $state->getName();
      }
    }
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
      $container->get('commerce_price.rounder'),
      $container->get('config.factory'),
      $container->get('address.subdivision_repository')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'display_label' => $this->defaultDisplayLabel,
      'allowed_states' => [],
      'tax_rounding_method' => $this->defaultRoundingMethod,
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    $form['display_label'] = [
      '#type' => 'textfield',
      '#title' => t('Display label'),
      '#description' => t('Used to identify the applied tax in order summaries. Ex: "Tax", "VAT", "GST".'),
      '#default_value' => $this->configuration['display_label'],
    ];

    $form['allowed_states'] = [
      '#type' => 'select',
      '#title' => t('Allowed States'),
      '#description' => t('Allow tax calculation for selected states. If none is chosen then all are allowed.'),
      '#options' => self::$statesList,
      '#default_value' => $this->configuration['allowed_states'],
      '#multiple' => TRUE,
    ];

    $form['tax_rounding_method'] = [
      '#type' => 'radios',
      '#title' => t('Rounding method'),
      '#default_value' => $this->configuration['tax_rounding_method'],
      '#options' => [
        self::ROUNDING_PER_LINE => t('Round per Line'),
        self::ROUNDING_GLOBALLY => t('Round Globally'),
      ],
      '#required' => TRUE,
      '#description' => t('How total tax amount is computed in orders.'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);

    if (!$form_state->getErrors()) {
      $values = $form_state->getValue($form['#parents']);
      $this->configuration['display_label'] = $values['display_label'];
      $this->configuration['allowed_states'] = $values['allowed_states'];
      $this->configuration['tax_rounding_method'] = $values['tax_rounding_method'];
    }
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
      $items[] = new CartItem(
        $index,
        $item->id(),
        $this->config->get('tax_code'),
        $item->getAdjustedUnitPrice()->getNumber(),
        $item->getQuantity()
      );
    }
    return $items;
  }

  /**
   * {@inheritdoc}
   */
  public function applies(OrderInterface $order) {
    return empty($order->getItems()[0]) ? FALSE : parent::applies($order);
  }

  /**
   * @inheritDoc
   */
  public function apply(OrderInterface $order) {
    $store = $order->getStore();
    $prices_include_tax = $store->get('prices_include_tax')->value;

    $order_items = $order->getItems();

    // @see https://dev.taxcloud.com/guides/getting-oriented-with-taxcloud
    if (empty($order_items[0])) {
      return;
    }

    $storeAddress = $store->getAddress();
    if ($customerProfile = $this->resolveCustomerProfile($order_items[0])) {
      /** @var \Drupal\address\Plugin\Field\FieldType\AddressItem $destinationAddress */
      $destinationAddress = $customerProfile->get('address')->first();

      if (!$this->assertDestinationAddress($destinationAddress)) {
        return;
      }
    }
    else {
      return;
    }
    $request = new Client();
    $items = $this->prepareTaxCloudItems($order);
    $event = new PrepareLookupDataEvent($order, $items, $storeAddress, $destinationAddress);
    $this->eventDispatcher->dispatch(CommerceTaxCloudEvents::PREPARE_LOOKUP_DATA, $event);
    // Allow subscribers to indicate no lookup should be performed.
    if ($event->isPropagationStopped()) {
      return;
    }
    $lookup = new Lookup(
      $this->config->get('api_id'),
      $this->config->get('api_key'),
      $order->getCustomerId(),
      $order->id(),
      $event->getItems(),
      $this->addressToTaxCloudAddress($event->getOrigin()),
      $this->addressToTaxCloudAddress($event->getDestination())
    );
    try {
      // Response is the order ID with rates keyed by line item index.
      // @see https://api.taxcloud.net/1.0/taxcloud.asmx?op=Lookup
      $response = $request->Lookup($lookup);

      foreach ($order->getItems() as $order_item_index => $order_item) {
        if (!empty($response[$order->id()][$order_item_index])) {
          $order_item_total_tax_amount_rounded = $response[$order->id()][$order_item_index];
          $percentage = $order_item_total_tax_amount_rounded / $order_item->getAdjustedTotalPrice()->getNumber();
          $percentage = (string) round($percentage, 3);

          $order_item_total_tax_amount = $order_item->getAdjustedTotalPrice()->multiply($percentage);
          $order_item_tax_amount = $order_item->getAdjustedUnitPrice()->multiply($percentage);

          if ($this->shouldRound()) {
            // Round tax amount with local rounder.
            $order_item_total_tax_amount = $this->rounder->round($order_item_total_tax_amount);
          }

          $tax_source_id = [
            $this->entityId,
            $storeAddress->getAdministrativeArea(),
            $storeAddress->getPostalCode(),
            $destinationAddress->getAdministrativeArea(),
            $destinationAddress->getPostalCode(),
          ];

          $unit_price = $order_item->getUnitPrice();
          if ($prices_include_tax && !$this->isDisplayInclusive()) {
            $unit_price = $unit_price->subtract($order_item_tax_amount);
            $order_item->setUnitPrice($unit_price);
          }
          elseif (!$prices_include_tax && $this->isDisplayInclusive()) {
            $unit_price = $unit_price->add($order_item_tax_amount);
            $order_item->setUnitPrice($unit_price);
          }

          $order_item->addAdjustment(new Adjustment([
            'type' => 'tax',
            'label' => $this->getDisplayLabel(),
            // New in Commerce 2.8: order item adjustment amount is now per
            // order item *total* price and not per unit price.
            'amount' => $order_item_total_tax_amount,
            'percentage' => $percentage,
            'source_id' => implode('|', $tax_source_id),
            'included' => $this->isDisplayInclusive(),
          ]));
        }
      }
    }
    catch (LookupException $e) {
      // @todo - Log and fail.
    }
  }

  /**
   * Gets the configured display label.
   *
   * @return string
   *   The configured display label.
   */
  protected function getDisplayLabel() {
    return isset($this->configuration['display_label']) ? $this->configuration['display_label'] : $this->defaultDisplayLabel;
  }

  /**
   * Check if it needs to calculate tax.
   *
   * @param \CommerceGuys\Addressing\AddressInterface $destinationAddress
   *   Order destination address.
   *
   * @return bool
   *   TRUE - tax needs to be calculated.
   */
  protected function assertDestinationAddress(AddressInterface $destinationAddress) {
    return empty($this->configuration['allowed_states']) || in_array($destinationAddress->getAdministrativeArea(), $this->configuration['allowed_states']);
  }

  /**
   * Get all adjustments for an order item.
   *
   * @param \Drupal\profile\Entity\ProfileInterface $customer_profile
   * @param \Drupal\commerce_order\Entity\OrderItemInterface $order_item
   * @param bool $prices_include_tax Whether order item prices should include
   *   tax.
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

  /**
   * {@inheritdoc}
   */
  public function shouldRound() {
    return $this->configuration['tax_rounding_method'] == self::ROUNDING_PER_LINE;
  }

}
