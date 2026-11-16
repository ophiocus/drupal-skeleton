<?php

declare(strict_types=1);

namespace Drupal\ad_inventory\Coverage;

use Drupal\ad_inventory\Entity\AdBooking;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreInterface;

/**
 * The coverage each approved booking locked, by booking id.
 *
 * Kept in a key-value collection, so it needs no new column on the booking.
 * A booking with no stored coverage (approved before coverage existed, or
 * saved by code) covers its scope as before.
 */
final class CoverageStore {

  /**
   * Every stored coverage, loaded once per request.
   *
   * @var array<string, \Drupal\ad_inventory\Coverage\Coverage>|null
   */
  private ?array $all = NULL;

  public function __construct(
    private readonly KeyValueFactoryInterface $keyValue,
  ) {}

  /**
   * A booking's coverage, or NULL when none was stored.
   */
  public function get(AdBooking $booking): ?Coverage {
    if ($booking->isNew()) {
      return NULL;
    }
    if ($this->all === NULL) {
      $this->all = array_map(
        static fn ($values): Coverage => Coverage::fromArray((array) $values),
        $this->collection()->getAll(),
      );
    }
    return $this->all[(string) $booking->id()] ?? NULL;
  }

  /**
   * Stores what a booking locked.
   */
  public function set(AdBooking $booking, Coverage $coverage): void {
    $this->collection()->set((string) $booking->id(), $coverage->toArray());
    $this->all = NULL;
  }

  /**
   * Forgets a booking's coverage: its pages are free again.
   */
  public function delete(string $booking_id): void {
    $this->collection()->delete($booking_id);
    $this->all = NULL;
  }

  /**
   * The collection.
   */
  private function collection(): KeyValueStoreInterface {
    return $this->keyValue->get('ad_inventory.coverage');
  }

}
