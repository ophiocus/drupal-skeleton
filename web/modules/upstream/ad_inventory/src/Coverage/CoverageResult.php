<?php

declare(strict_types=1);

namespace Drupal\ad_inventory\Coverage;

/**
 * What a booking would lock, and what other bookings already hold of it.
 */
final class CoverageResult {

  /**
   * Builds a result.
   *
   * @param \Drupal\ad_inventory\Coverage\Coverage $coverage
   *   What the booking would lock.
   * @param array<int, array{key: string, booking: \Drupal\ad_inventory\Entity\AdBooking}> $taken
   *   Pages of the scope other bookings hold: their key and the booking.
   * @param array<int, array{keys: string[], booking: \Drupal\ad_inventory\Entity\AdBooking}> $kept
   *   Wider bookings that hold the scope except these keys, left to this one.
   * @param \Drupal\ad_inventory\Entity\AdBooking[] $holders
   *   Bookings that hold every page of the scope: the booking can't be bought.
   */
  public function __construct(
    public readonly Coverage $coverage,
    public readonly array $taken,
    public readonly array $kept,
    public readonly array $holders,
  ) {}

  /**
   * Whether nothing is left to buy.
   */
  public function refused(): bool {
    return $this->holders !== [];
  }

  /**
   * A fingerprint of what the booking would lock, for the confirmation.
   *
   * @param string[] $lines
   *   The sites' summary lines shown with it.
   */
  public function fingerprint(array $lines = []): string {
    return hash('sha256', json_encode([$this->coverage->toArray(), $lines], JSON_THROW_ON_ERROR));
  }

}
