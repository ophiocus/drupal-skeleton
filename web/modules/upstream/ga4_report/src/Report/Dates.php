<?php

declare(strict_types=1);

namespace Drupal\ga4_report\Report;

/**
 * Turns the settings' dates into calendar days.
 *
 * Google Analytics takes "90daysAgo", "yesterday" and "today" as they are;
 * AdSense and Cloudflare want calendar days, so the same settings are
 * resolved here, in the site's time zone, against a given moment.
 */
final class Dates {

  /**
   * One setting as a calendar day (Y-m-d).
   *
   * @param string $value
   *   A date (2026-01-31), NdaysAgo, today or yesterday.
   * @param int $now
   *   The moment "today" means, as a timestamp.
   * @param string $timezone
   *   The time zone the day is counted in.
   */
  public static function day(string $value, int $now, string $timezone): string {
    $today = (new \DateTimeImmutable('@' . $now))->setTimezone(new \DateTimeZone($timezone ?: 'UTC'));
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
      return $value;
    }
    if ($value === 'yesterday') {
      return $today->modify('-1 day')->format('Y-m-d');
    }
    if (preg_match('/^(\d+)daysAgo$/', $value, $m)) {
      return $today->modify('-' . (int) $m[1] . ' days')->format('Y-m-d');
    }
    return $today->format('Y-m-d');
  }

}
