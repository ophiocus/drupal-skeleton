<?php

declare(strict_types=1);

namespace Drupal\ad_inventory_test\Hook;

use Drupal\Core\Cache\RefinableCacheableDependencyInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\State\StateInterface;

/**
 * Hook implementations for ad_inventory_test.
 */
final class AdInventoryTestHooks {

  public function __construct(
    private readonly StateInterface $state,
  ) {}

  /**
   * Implements hook_ad_inventory_scope_alter().
   *
   * Adds the page context values in state "ad_inventory_test.scopes".
   */
  #[Hook('ad_inventory_scope_alter')]
  public function scopeAlter(array &$scopes, array $context, RefinableCacheableDependencyInterface $cacheability): void {
    foreach ((array) $this->state->get('ad_inventory_test.scopes', []) as $scope) {
      $scopes[] = (string) $scope;
    }
  }

}
