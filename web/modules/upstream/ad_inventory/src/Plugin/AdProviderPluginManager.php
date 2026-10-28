<?php

declare(strict_types=1);

namespace Drupal\ad_inventory\Plugin;

use Drupal\ad_inventory\Attribute\AdProvider;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;

/**
 * Finds ad provider plugins in any module's src/Plugin/AdProvider.
 */
final class AdProviderPluginManager extends DefaultPluginManager {

  /**
   * Formats a provider may declare that make it refuse or be refused.
   */
  public const OVERLAYS = ['popunder', 'push', 'vignette', 'interstitial', 'exit', 'slide_in'];

  public function __construct(\Traversable $namespaces, CacheBackendInterface $cache_backend, ModuleHandlerInterface $module_handler) {
    parent::__construct('Plugin/AdProvider', $namespaces, $module_handler, AdProviderInterface::class, AdProvider::class);
    $this->alterInfo('ad_inventory_provider_info');
    $this->setCacheBackend($cache_backend, 'ad_inventory_provider_plugins');
  }

  /**
   * Why two providers cannot run on one site, or NULL when they can.
   *
   * A provider that serves a format another one forbids (Google, for one,
   * refuses sites with pop-unders) is refused beside it.
   */
  public function conflict(string $a, string $b): ?string {
    $da = $this->getDefinition($a, FALSE);
    $db = $this->getDefinition($b, FALSE);
    if ($da === NULL || $db === NULL) {
      return NULL;
    }
    foreach ([[$da, $db], [$db, $da]] as [$one, $two]) {
      $clash = array_intersect($one['excludes'] ?? [], $two['forbids'] ?? []);
      if ($clash !== []) {
        return sprintf('%s serves %s, which %s forbids on the same site.', $one['id'], implode(', ', $clash), $two['id']);
      }
    }
    return NULL;
  }

}
