<?php

declare(strict_types=1);

namespace Drupal\ad_inventory\Event;

use Drupal\ad_inventory\Entity\AdBookingInterface;
use Drupal\ad_inventory\Entity\AdCreativeInterface;
use Drupal\Component\EventDispatcher\Event;

/**
 * Asks the site whether a booking's creative may run.
 *
 * Dispatched when an approved booking is saved ("save") and, for every
 * approved booking not yet over, before each day's run ("daily"). A
 * subscriber may refuse (the booking cannot be approved: back to draft, or
 * stopped when found at a daily run) or stop it (it leaves the pages at their
 * next render). Example: a legal registration code that must match a public
 * register, re-checked every day.
 */
final class AdCreativeValidateEvent extends Event {

  /**
   * The event name.
   */
  public const NAME = 'ad_inventory.creative_validate';

  /**
   * Reasons for refusal.
   *
   * @var string[]
   */
  private array $refused = [];

  /**
   * Reasons for stopping.
   *
   * @var string[]
   */
  private array $stopped = [];

  public function __construct(
    public readonly AdBookingInterface $booking,
    public readonly AdCreativeInterface $creative,
    public readonly string $phase,
  ) {}

  /**
   * The booking may not be approved.
   */
  public function refuse(string $reason): void {
    $this->refused[] = $reason;
  }

  /**
   * The booking stops now.
   */
  public function stop(string $reason): void {
    $this->stopped[] = $reason;
  }

  /**
   * The refusal reasons.
   *
   * @return string[]
   *   Reasons.
   */
  public function refusals(): array {
    return $this->refused;
  }

  /**
   * The stop reasons.
   *
   * @return string[]
   *   Reasons.
   */
  public function stops(): array {
    return $this->stopped;
  }

}
