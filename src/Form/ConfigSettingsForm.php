<?php

namespace Drupal\commerce_taxcloud\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\ConfigFormBase;
use TaxCloud\Client;
use TaxCloud\Exceptions\PingException;
use TaxCloud\Request\Ping;

/**
 * Configuration form for TaxCloud settings.
 */
class ConfigSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'commerce_taxcloud_config_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['commerce_taxcloud.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('commerce_taxcloud.settings');

    $form['configuration'] = [
      '#type' => 'details',
      '#title' => t('Configuration'),
      '#open' => TRUE,
    ];
    $form['configuration']['account_id'] = [
      '#type' => 'textfield',
      '#title' => t('Account ID:'),
      '#default_value' => $config->get('account_id'),
      '#required' => TRUE,
      '#description' => $this->t('The account ID to use when calculating taxes.'),
    ];
    $form['configuration']['api_id'] = [
      '#type' => 'textfield',
      '#title' => t('API ID:'),
      '#default_value' => $config->get('api_id'),
      '#required' => TRUE,
      '#description' => $this->t('The API id to send to TaxCloud when calculating taxes.'),
    ];
    $form['configuration']['api_key'] = [
      '#type' => 'textfield',
      '#title' => t('API key:'),
      '#default_value' => $config->get('api_key'),
      '#required' => TRUE,
      '#description' => $this->t('The API key to send to TaxCloud when calculating taxes.'),
    ];
    $form['configuration']['tax_code'] = [
      '#type' => 'textfield',
      '#title' => t('Default tax code:'),
      '#default_value' => $config->get('tax_code'),
      '#required' => TRUE,
      '#description' => $this->t('The default tax code to send to TaxCloud when calculating taxes, if company code is not set on the purchased entity of a given order item.'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $config = $this->config('commerce_taxcloud.settings');
    $config
      ->set('account_id', $form_state->getValue('account_id'))
      ->set('api_id', $form_state->getValue('api_id'))
      ->set('api_key', $form_state->getValue('api_key'))
      ->set('tax_code', $form_state->getValue('tax_code'))
      ->save();
    parent::submitForm($form, $form_state);
    $client = new Client();
    try {
      $client->Ping(new Ping(
        $config->get('api_id'),
        $config->get('api_key')
      ));
      drupal_set_message($this->t('Successfully pinged API.'), 'status');
    }
    catch (PingException $e) {
      drupal_set_message($this->t(
        'TaxCloud connection error: @error',
        ['@error' => $e->getMessage()]
      ), 'error');
    }
  }

}
