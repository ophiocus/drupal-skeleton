<?php

declare(strict_types=1);

namespace Drupal\ad_inventory;

use Drupal\ad_inventory\Entity\AdBooking;
use Drupal\ad_inventory\Entity\AdCreative;
use Drupal\ad_inventory\Entity\AdProvider;

/**
 * What fills a space on a page: a booking, a provider or a house banner.
 */
final class Fill {

  public const BOOKING = 'booking';
  public const PROVIDER = 'provider';
  public const HOUSE = 'house';

  /**
   * Records what fills a space.
   *
   * @param string $source
   *   One of 'booking', 'provider' or 'house'.
   * @param string $desktop
   *   Format id on wide screens (after the page's own settings).
   * @param string $phone
   *   Format id on phones.
   * @param bool $draft
   *   TRUE when only a draft fills it (seen by staff who preview drafts).
   * @param \Drupal\ad_inventory\Entity\AdCreative|null $creative
   *   The creative, for bookings and house banners.
   * @param \Drupal\ad_inventory\Entity\AdBooking|null $booking
   *   The booking.
   * @param \Drupal\ad_inventory\Entity\AdProvider|null $provider
   *   The provider.
   * @param \Drupal\ad_inventory\Entity\AdCreative[] $candidates
   *   For a house fill: every house banner that may fill the space (the
   *   first is $creative). The browser picks one of them at random.
   */
  public function __construct(
    public readonly string $source,
    public readonly string $desktop,
    public readonly string $phone,
    public readonly bool $draft = FALSE,
    public readonly ?AdCreative $creative = NULL,
    public readonly ?AdBooking $booking = NULL,
    public readonly ?AdProvider $provider = NULL,
    public readonly array $candidates = [],
  ) {}

  /**
   * The counting key of the source: 'booking', 'house' or 'provider:<id>'.
   */
  public function countSource(): string {
    return $this->source === self::PROVIDER && $this->provider !== NULL
      ? 'provider:' . $this->provider->id()
      : $this->source;
  }

}
