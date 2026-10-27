<?php

declare(strict_types=1);

namespace Drupal\ad_inventory\Entity;

use Drupal\Core\Config\Entity\ConfigEntityInterface;

/**
 * A reserved, labelled box on pages (web) or in mail templates (email).
 */
interface AdSlotInterface extends ConfigEntityInterface {

  /**
   * Fill sources in the order the browser tries them.
   */
  public const SOURCES = ['booking', 'network', 'house'];

  /**
   * The channels.
   */
  public const CHANNELS = ['web', 'email'];

  /**
   * The channel: "web" or "email".
   */
  public function channel(): string;

  /**
   * The format above the breakpoint (the only one for email).
   */
  public function desktopFormat(): string;

  /**
   * The format under the breakpoint (the desktop one for email).
   */
  public function phoneFormat(): string;

  /**
   * Formats per-page controls may pick instead of the two above.
   *
   * @return string[]
   *   Format ids.
   */
  public function alternativeFormats(): array;

  /**
   * The allowed fill sources, in fill order.
   *
   * @return string[]
   *   A subset of SOURCES; email never includes "network".
   */
  public function sources(): array;

  /**
   * Whether a source may fill this slot.
   */
  public function allows(string $source): bool;

  /**
   * The creative kinds a booking may carry here; empty allows every kind.
   *
   * @return string[]
   *   Kind ids.
   */
  public function bookingKinds(): array;

  /**
   * The slot group a booking may name instead of the slot, or ''.
   */
  public function group(): string;

  /**
   * Whether a template may print this slot several times on one page.
   */
  public function isRepeatable(): bool;

  /**
   * The network unit of every provider, keyed by provider id.
   *
   * @return array<string, array<string, string>>
   *   Unit field values per provider.
   */
  public function units(): array;

  /**
   * The cache tag of everything printed from this slot's bookings.
   */
  public static function fillTag(string $slot_id): string;

}
