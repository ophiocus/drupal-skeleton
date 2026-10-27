<?php

declare(strict_types=1);

namespace Drupal\ad_inventory_report;

use Drupal\ad_inventory\Calendar;

/**
 * A dashboard's date range and the range before it, from the query string.
 *
 * ?range=today|yesterday|7d|28d|month|last_month|custom, and for custom
 * ?from=Y-m-d&to=Y-m-d. The previous range has the same length and ends the
 * day before (last month for "this month": the whole previous month).
 */
final class DateRange {

  /**
   * The ranges and their labels.
   */
  public const RANGES = [
    'today' => 'Today',
    'yesterday' => 'Yesterday',
    '7d' => '7 days',
    '28d' => '28 days',
    'month' => 'This month',
    'last_month' => 'Last month',
    'custom' => 'Custom',
  ];

  /**
   * The longest custom range, in days.
   */
  public const MAX_DAYS = 366;

  private function __construct(
    public readonly string $key,
    public readonly string $from,
    public readonly string $to,
    public readonly string $previousFrom,
    public readonly string $previousTo,
  ) {}

  /**
   * The range a query asks for, relative to today.
   *
   * @param array $query
   *   The query parameters.
   * @param string $today
   *   Y-m-d.
   */
  public static function fromQuery(array $query, string $today): self {
    $key = is_string($query['range'] ?? NULL) && isset(self::RANGES[$query['range']]) ? $query['range'] : '28d';
    switch ($key) {
      case 'today':
        return self::span($key, $today, $today);

      case 'yesterday':
        $day = Calendar::shift($today, -1);
        return self::span($key, $day, $day);

      case '7d':
        return self::span($key, Calendar::shift($today, -6), $today);

      case 'month':
        $first = substr($today, 0, 8) . '01';
        $previous_first = (new \DateTimeImmutable($first . ' 12:00', new \DateTimeZone('UTC')))->modify('-1 month')->format('Y-m-d');
        return new self($key, $first, $today, $previous_first, Calendar::shift($first, -1));

      case 'last_month':
        $first = (new \DateTimeImmutable(substr($today, 0, 8) . '01 12:00', new \DateTimeZone('UTC')))->modify('-1 month');
        $from = $first->format('Y-m-d');
        $to = $first->modify('last day of this month')->format('Y-m-d');
        $previous = $first->modify('-1 month');
        return new self($key, $from, $to, $previous->format('Y-m-d'), $previous->modify('last day of this month')->format('Y-m-d'));

      case 'custom':
        $from = (string) ($query['from'] ?? '');
        $to = (string) ($query['to'] ?? '');
        if (Calendar::isDay($from) && Calendar::isDay($to)) {
          if ($to < $from) {
            [$from, $to] = [$to, $from];
          }
          if (self::length($from, $to) > self::MAX_DAYS) {
            $from = Calendar::shift($to, -(self::MAX_DAYS - 1));
          }
          return self::span($key, $from, $to);
        }
        return self::span('28d', Calendar::shift($today, -27), $today);

      default:
        return self::span('28d', Calendar::shift($today, -27), $today);
    }
  }

  /**
   * A range and the one of equal length before it.
   */
  private static function span(string $key, string $from, string $to): self {
    $days = self::length($from, $to);
    return new self($key, $from, $to, Calendar::shift($from, -$days), Calendar::shift($from, -1));
  }

  /**
   * Days from one day to another, both included.
   */
  public static function length(string $from, string $to): int {
    $a = new \DateTimeImmutable($from . ' 12:00', new \DateTimeZone('UTC'));
    $b = new \DateTimeImmutable($to . ' 12:00', new \DateTimeZone('UTC'));
    return (int) $a->diff($b)->days + 1;
  }

  /**
   * Every day of the range.
   *
   * @return string[]
   *   Y-m-d days.
   */
  public function days(): array {
    $days = [];
    for ($day = $this->from; $day <= $this->to; $day = Calendar::shift($day, 1)) {
      $days[] = $day;
    }
    return $days;
  }

  /**
   * The query that names this range.
   */
  public function query(): array {
    return $this->key === 'custom' ? ['range' => 'custom', 'from' => $this->from, 'to' => $this->to] : ['range' => $this->key];
  }

}
