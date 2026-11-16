<?php

declare(strict_types=1);

namespace Drupal\ad_inventory;

use Drupal\ad_inventory\Entity\AdBooking;

/**
 * A booking in force in a space, with the creatives it may show there.
 *
 * In the vocabulary of the sales desk: the spot is the space, the placement is
 * the client's booking tying one or more creatives to it, and the roster is
 * the list of placements a spot shows this week. The spot shows one placement
 * at a time (outer rotation) and the placement one of its creatives (inner
 * rotation); in both, the one with the fewest views this week goes next.
 */
final class Placement {

  /**
   * Records a placement.
   *
   * @param \Drupal\ad_inventory\Entity\AdBooking $booking
   *   The booking.
   * @param \Drupal\ad_inventory\Entity\AdCreative[] $creatives
   *   Its creatives that fit the space, in the client's order (never empty).
   */
  public function __construct(
    public readonly AdBooking $booking,
    public readonly array $creatives,
  ) {}

  /**
   * The counting source of this placement.
   */
  public function source(): string {
    return AdCounter::bookingSource((int) $this->booking->id());
  }

}
