<?php

declare(strict_types=1);

namespace Drupal\ad_inventory\Plugin;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\PluginBase;

/**
 * Defaults for providers.
 */
abstract class AdProviderBase extends PluginBase implements AdProviderInterface {

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $configuration
   *   Settings.
   * @param string $plugin_id
   *   Plugin id.
   * @param mixed $plugin_definition
   *   Definition.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->setConfiguration($configuration);
  }

  /**
   * {@inheritdoc}
   */
  public function getConfiguration(): array {
    return $this->configuration;
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $configuration
   *   Settings.
   */
  public function setConfiguration(array $configuration): void {
    $this->configuration = $configuration + $this->defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   *
   * @return array<string, mixed>
   *   Defaults.
   */
  public function defaultConfiguration(): array {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function loaders(): array {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function adsTxtLines(): array {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function verificationTag(): string {
    return '';
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function settingsFromForm(array $values): array {
    return $values;
  }

}
