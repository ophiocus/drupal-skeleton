<?php

declare(strict_types=1);

namespace Drupal\ad_inventory;

use Drupal\ad_inventory\Attribute\AdProvider;
use Drupal\ad_inventory\Plugin\AdProvider\AdProviderPluginInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;

/**
 * Discovers ad network provider plugins (src/Plugin/AdProvider).
 */
final class AdProviderManager extends DefaultPluginManager {

  public function __construct(\Traversable $namespaces, CacheBackendInterface $cache_backend, ModuleHandlerInterface $module_handler) {
    parent::__construct('Plugin/AdProvider', $namespaces, $module_handler, AdProviderPluginInterface::class, AdProvider::class);
    $this->alterInfo('ad_inventory_provider_info');
    $this->setCacheBackend($cache_backend, 'ad_inventory_provider_plugins');
  }

}
