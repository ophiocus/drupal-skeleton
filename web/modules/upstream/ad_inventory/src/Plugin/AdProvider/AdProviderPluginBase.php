<?php

declare(strict_types=1);

namespace Drupal\ad_inventory\Plugin\AdProvider;

use Drupal\ad_inventory\NetworkGate;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Plugin\PluginBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Defaults for provider plugins: no account, no loader, nothing forbidden.
 */
abstract class AdProviderPluginBase extends PluginBase implements AdProviderPluginInterface, ContainerFactoryPluginInterface {

  final public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected NetworkGate $gate,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static($configuration, $plugin_id, $plugin_definition, $container->get('ad_inventory.network_gate'));
  }

  /**
   * The provider instance id.
   */
  protected function providerId(): string {
    return (string) ($this->configuration['provider_id'] ?? $this->getPluginId());
  }

  /**
   * {@inheritdoc}
   */
  public function defaultSettings(): array {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function settings(): array {
    return ((array) ($this->configuration['settings'] ?? [])) + $this->defaultSettings();
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $settings): array {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function requiresAccount(): bool {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function account(): ?string {
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function loaderUrl(): string {
    return '';
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
  public function adsTxtLineRequired(): bool {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function consent(): string {
    return 'Advertising consent.';
  }

  /**
   * {@inheritdoc}
   */
  public function allowedLabels(): array {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function maxUnits(): int {
    return 0;
  }

  /**
   * {@inheritdoc}
   */
  public function houseBehind(): bool {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function kinds(): array {
    return ['banner'];
  }

  /**
   * {@inheritdoc}
   */
  public function forbids(): array {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function verificationTag(): ?array {
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function handler(): string {
    return 'generic';
  }

  /**
   * {@inheritdoc}
   */
  public function reportingChannel(array $unit): string {
    return '';
  }

}
