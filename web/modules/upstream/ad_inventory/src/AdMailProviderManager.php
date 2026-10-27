<?php

declare(strict_types=1);

namespace Drupal\ad_inventory;

use Drupal\ad_inventory\Attribute\AdMailProvider;
use Drupal\ad_inventory\Plugin\AdMailProvider\AdMailProviderPluginInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;

/**
 * Discovers mail provider plugins (src/Plugin/AdMailProvider).
 */
final class AdMailProviderManager extends DefaultPluginManager {

  public function __construct(\Traversable $namespaces, CacheBackendInterface $cache_backend, ModuleHandlerInterface $module_handler) {
    parent::__construct('Plugin/AdMailProvider', $namespaces, $module_handler, AdMailProviderPluginInterface::class, AdMailProvider::class);
    $this->alterInfo('ad_inventory_mail_provider_info');
    $this->setCacheBackend($cache_backend, 'ad_inventory_mail_provider_plugins');
  }

}
