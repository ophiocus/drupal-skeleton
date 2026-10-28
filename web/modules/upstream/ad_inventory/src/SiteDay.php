<?php

declare(strict_types=1);

namespace Drupal\ad_inventory;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Today, as the site counts days (its own time zone), YYYY-MM-DD.
 *
 * Bookings start and end on days, not hours; a day is the site's, whatever
 * the reader's clock says.
 */
final class SiteDay {

  public function __construct(
    private readonly TimeInterface $time,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Today.
   */
  public function today(): string {
    return $this->at($this->time->getRequestTime());
  }

  /**
   * The day of a timestamp.
   */
  public function at(int $timestamp): string {
    $zone = (string) ($this->configFactory->get('system.date')->get('timezone.default') ?: 'UTC');
    return (new \DateTimeImmutable('@' . $timestamp))->setTimezone(new \DateTimeZone($zone))->format('Y-m-d');
  }

}
