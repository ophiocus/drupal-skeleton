<?php

declare(strict_types=1);

namespace Drupal\appointments\Entity;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityChangedInterface;

/**
 * One booked appointment on the calendar.
 */
interface AppointmentInterface extends ContentEntityInterface, EntityChangedInterface {

  /**
   * Booked and waiting for its online payment; holds the slot until expiry.
   */
  public const PENDING = 'pending';

  /**
   * Booked and confirmed.
   */
  public const CONFIRMED = 'confirmed';

  /**
   * Took place.
   */
  public const COMPLETED = 'completed';

  /**
   * The client did not come.
   */
  public const NO_SHOW = 'no_show';

  /**
   * Cancelled, by the client, the staff, or an unpaid hold running out.
   */
  public const CANCELLED = 'cancelled';

  /**
   * The service booked, or NULL if it was deleted.
   */
  public function getService(): ?AppointmentServiceInterface;

  /**
   * Start, as a Unix timestamp.
   */
  public function getStart(): int;

  /**
   * End of the appointment itself, as a Unix timestamp.
   */
  public function getEnd(): int;

  /**
   * End of the time the appointment keeps the calendar busy (end + buffer).
   */
  public function getBusyUntil(): int;

  /**
   * One of the status constants.
   */
  public function getStatus(): string;

  /**
   * Sets the status.
   */
  public function setStatus(string $status): static;

  /**
   * Whether the appointment occupies its slot at the given time.
   *
   * Confirmed appointments always do; a pending one only until its hold
   * expires.
   */
  public function isHolding(int $now): bool;

  /**
   * The secret that opens the client's manage page.
   */
  public function getToken(): string;

  /**
   * The client's e-mail address.
   */
  public function getEmail(): string;

}
