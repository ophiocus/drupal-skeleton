<?php

declare(strict_types=1);

namespace Drupal\ad_inventory_report\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The booked-value field and the rollup window.
 */
final class SettingsForm extends ConfigFormBase {

  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typed_config_manager,
    protected EntityFieldManagerInterface $entityFieldManager,
  ) {
    parent::__construct($config_factory, $typed_config_manager);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('entity_field.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'ad_inventory_report_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['ad_inventory_report.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('ad_inventory_report.settings');
    $options = [];
    foreach ($this->entityFieldManager->getFieldStorageDefinitions('ad_booking') as $name => $definition) {
      if (in_array($definition->getType(), ['decimal', 'float', 'integer', 'commerce_price'], TRUE)) {
        $options[$name] = $name . ' (' . $definition->getType() . ')';
      }
    }
    $form['amount_field'] = [
      '#type' => 'select',
      '#title' => $this->t('Booked value from'),
      '#description' => $this->t('A number or price field the site added to bookings. Without one the dashboard shows no booked value; it never invents a price.'),
      '#options' => $options,
      '#empty_option' => $this->t('- No booked value -'),
      '#default_value' => $config->get('amount_field'),
    ];
    $form['rollup_days'] = [
      '#type' => 'number',
      '#title' => $this->t('Days each rollup recomputes'),
      '#description' => $this->t('Cron recomputes this many days, up to today, from the raw counts. The first run computes every day.'),
      '#min' => 1,
      '#max' => 31,
      '#default_value' => $config->get('rollup_days'),
    ];
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('ad_inventory_report.settings')
      ->set('amount_field', (string) $form_state->getValue('amount_field'))
      ->set('rollup_days', max(1, (int) $form_state->getValue('rollup_days')))
      ->save();
    parent::submitForm($form, $form_state);
  }

}
