<?php

declare(strict_types=1);

namespace Drupal\ad_inventory;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Days in the site's time zone: bookings and counts are by day, never hour.
 */
final class Calendar {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly TimeInterface $time,
  ) {}

  /**
   * The time zone days are counted in.
   *
   * The module's setting, else the site's default time zone, else PHP's.
   */
  public function timezone(): string {
    $zone = (string) $this->configFactory->get('ad_inventory.settings')->get('timezone');
    if ($zone === '') {
      $zone = (string) $this->configFactory->get('system.date')->get('timezone.default');
    }
    if ($zone === '' || !in_array($zone, \DateTimeZone::listIdentifiers(), TRUE)) {
      $zone = date_default_timezone_get();
    }
    return $zone;
  }

  /**
   * Today, Y-m-d.
   */
  public function today(): string {
    return $this->day($this->time->getCurrentTime());
  }

  /**
   * The day of a timestamp, Y-m-d.
   */
  public function day(int $timestamp): string {
    return (new \DateTimeImmutable('@' . $timestamp))
      ->setTimezone(new \DateTimeZone($this->timezone()))
      ->format('Y-m-d');
  }

  /**
   * A day moved by a number of days, Y-m-d.
   */
  public static function shift(string $day, int $days): string {
    return (new \DateTimeImmutable($day . ' 12:00:00', new \DateTimeZone('UTC')))
      ->modify(sprintf('%+d days', $days))
      ->format('Y-m-d');
  }

  /**
   * Whether a string is a real Y-m-d day.
   */
  public static function isDay(string $value): bool {
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) {
      return FALSE;
    }
    return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
  }

}
