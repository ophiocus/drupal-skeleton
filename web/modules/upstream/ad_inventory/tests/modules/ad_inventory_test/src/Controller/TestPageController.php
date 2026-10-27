<?php

declare(strict_types=1);

namespace Drupal\ad_inventory_test\Controller;

use Drupal\ad_inventory\Element\AdSlotElement;

/**
 * A page that prints three slots between items of its own list.
 */
final class TestPageController {

  /**
   * The page.
   */
  public function page(): array {
    $build = ['intro' => ['#markup' => '<p>Listing</p>']];
    foreach (['slot_a', 'slot_b', 'slot_c'] as $i => $slot) {
      $build['item_' . $i] = ['#markup' => '<p>Item ' . $i . '</p>'];
      $build['ad_' . $i] = AdSlotElement::build($slot);
    }
    return $build;
  }

}
