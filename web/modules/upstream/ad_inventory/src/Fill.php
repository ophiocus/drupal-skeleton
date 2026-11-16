<?php

declare(strict_types=1);

namespace Drupal\ad_inventory;

use Drupal\ad_inventory\Entity\AdBooking;
use Drupal\ad_inventory\Entity\AdCreative;
use Drupal\ad_inventory\Entity\AdProvider;

/**
 * What fills a space on a page: bookings, a provider or a house banner.
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
   *   The first creative, for bookings and house banners.
   * @param \Drupal\ad_inventory\Entity\AdBooking|null $booking
   *   The first booking.
   * @param \Drupal\ad_inventory\Entity\AdProvider|null $provider
   *   The provider.
   * @param \Drupal\ad_inventory\Entity\AdCreative[] $candidates
   *   For a house fill: every house banner that may fill the space (the
   *   first is $creative). The browser picks one of them at random.
   * @param \Drupal\ad_inventory\Placement[] $placements
   *   For a booking fill: every placement the space shows this week (the
   *   first holds $booking). One exclusive booking stands alone. With more
   *   than one placement or creative the browser asks which goes next.
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
    public readonly array $placements = [],
  ) {}

  /**
   * The counting key of the source.
   *
   * Either 'booking:<id>' (the first placement's), 'house' or
   * 'provider:<id>'.
   */
  public function countSource(): string {
    if ($this->source === self::PROVIDER && $this->provider !== NULL) {
      return 'provider:' . $this->provider->id();
    }
    if ($this->source === self::BOOKING && $this->booking !== NULL) {
      return AdCounter::bookingSource((int) $this->booking->id());
    }
    return $this->source;
  }

}
