<?php

declare(strict_types=1);

namespace Drupal\ad_inventory\Scope;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Routing\RouteMatchInterface;

/**
 * A site's way of saying what a page is about.
 *
 * Tag a service `ad_inventory.scope_resolver`; every resolver adds its keys.
 * Keys must come from the URL only.
 */
interface ScopeResolverInterface {

  /**
   * Scope keys for a route.
   *
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The page's route.
   * @param \Drupal\Core\Cache\CacheableMetadata $cacheability
   *   Add what the keys depend on.
   *
   * @return string[]
   *   Keys, for example "city:12".
   */
  public function keys(RouteMatchInterface $route_match, CacheableMetadata $cacheability): array;

  /**
   * Keys staff may book, for the booking form.
   *
   * @return array<string, string>
   *   Key → label, grouped by the label's prefix.
   */
  public function options(): array;

}
