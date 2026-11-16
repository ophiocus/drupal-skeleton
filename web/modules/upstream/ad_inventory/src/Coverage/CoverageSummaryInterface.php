<?php

declare(strict_types=1);

namespace Drupal\ad_inventory\Coverage;

use Drupal\ad_inventory\Entity\AdBooking;

/**
 * A site's lines about what a booking would lock: pages, volume, price.
 *
 * Tag a service `ad_inventory.coverage_summary`. When any summary has lines,
 * the booking form shows them before approval and staff confirm them, as
 * they confirm a partial coverage; what was shown is what is saved.
 */
interface CoverageSummaryInterface {

  /**
   * Lines staff read before approving the booking.
   *
   * @param \Drupal\ad_inventory\Entity\AdBooking $booking
   *   The booking as the form would save it.
   * @param \Drupal\ad_inventory\Coverage\Coverage $coverage
   *   What it would lock.
   *
   * @return string[]
   *   Lines, or [] with nothing to say.
   */
  public function summary(AdBooking $booking, Coverage $coverage): array;

}
