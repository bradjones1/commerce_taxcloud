<?php

namespace Drupal\commerce_taxcloud\Plugin\Commerce\TaxType;

/**
 * Defines the interface for tax cloud plugin.
 */
interface TaxCloudInterface {

  /**
   * Gets whether tax should be rounded at the order item level.
   *
   * @return bool
   *   TRUE if tax should be rounded at the order item level, FALSE otherwise.
   */
  public function shouldRound();

}
