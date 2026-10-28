<?php

declare(strict_types=1);

namespace Drupal\ad_inventory_test\Controller;

/**
 * A public, cacheable page the space blocks are placed on.
 */
final class TestPage {

  /**
   * The page.
   *
   * @return array<string, mixed>
   *   Render array.
   */
  public function page(): array {
    return ['#markup' => 'A page with banner spaces.'];
  }

}
