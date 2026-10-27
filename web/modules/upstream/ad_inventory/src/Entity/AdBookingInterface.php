<?php

declare(strict_types=1);

namespace Drupal\ad_inventory\Entity;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\user\EntityOwnerInterface;

/**
 * A booking: a creative in slots between two dates, in a state.
 */
interface AdBookingInterface extends ContentEntityInterface, EntityChangedInterface, EntityOwnerInterface {

  /**
   * The states.
   */
  public const STATES = ['draft', 'approved', 'stopped'];

  /**
   * The booked creative, or NULL.
   */
  public function creative(): ?AdCreativeInterface;

  /**
   * The slots as entered: slot ids and "group:<name>" values.
   *
   * @return string[]
   *   The raw values.
   */
  public function slotValues(): array;

  /**
   * The pages: '' for every page of the slots, or a page context value.
   */
  public function scope(): string;

  /**
   * The first day, Y-m-d, in the site's time zone.
   */
  public function start(): string;

  /**
   * The last day, Y-m-d, in the site's time zone.
   */
  public function end(): string;

  /**
   * Whether the booking holds its slots alone while in force.
   */
  public function isExclusive(): bool;

  /**
   * The state: "draft", "approved" or "stopped".
   */
  public function state(): string;

  /**
   * Sets the state.
   */
  public function setState(string $state): static;

  /**
   * Why the booking was refused or stopped, or ''.
   */
  public function reason(): string;

  /**
   * Sets the reason.
   */
  public function setReason(string $reason): static;

  /**
   * Whether the booking is approved and covers the day.
   *
   * @param string $day
   *   Y-m-d.
   */
  public function inForceOn(string $day): bool;

}
