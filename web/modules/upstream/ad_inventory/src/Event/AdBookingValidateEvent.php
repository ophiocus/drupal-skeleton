<?php

declare(strict_types=1);

namespace Drupal\ad_inventory\Event;

use Drupal\ad_inventory\Entity\AdBooking;
use Drupal\Component\EventDispatcher\Event;

/**
 * Lets a site refuse a booking, or stop one that is running.
 *
 * Dispatched when staff save a booking (phase 'save': an error keeps it from
 * being approved) and by cron each day for approved bookings (phase 'daily':
 * a stop takes it off every page at the next render). A site uses it for the
 * rules only it knows, such as a show's legal registration code.
 */
final class AdBookingValidateEvent extends Event {

  public const NAME = 'ad_inventory.booking_validate';

  public const SAVE = 'save';
  public const DAILY = 'daily';

  /**
   * Reasons the booking cannot be approved.
   *
   * @var string[]
   */
  private array $errors = [];

  /**
   * Why a running booking must stop, or ''.
   */
  private string $stop = '';

  public function __construct(
    public readonly AdBooking $booking,
    public readonly string $phase,
  ) {}

  /**
   * Refuses approval, with a reason staff will read.
   */
  public function refuse(string $reason): void {
    $this->errors[] = $reason;
  }

  /**
   * Stops a running booking, with the reason.
   */
  public function stop(string $reason): void {
    $this->stop = $reason;
  }

  /**
   * Reasons given.
   *
   * @return string[]
   *   Reasons.
   */
  public function errors(): array {
    return $this->errors;
  }

  /**
   * The stop reason, or ''.
   */
  public function stopReason(): string {
    return $this->stop;
  }

}
