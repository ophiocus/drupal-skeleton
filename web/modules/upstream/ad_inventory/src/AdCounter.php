<?php

declare(strict_types=1);

namespace Drupal\ad_inventory;

use Drupal\Core\Database\Connection;

/**
 * Adds views and clicks to the daily totals, and reads them back.
 *
 * A booking's views are kept under its own source, "booking:<id>", so two
 * bookings sharing a creative and a space each read their own numbers, and
 * the rotation can serve the placement with the fewest views. Rows written
 * before that carry the plain source "booking" (the combined total).
 */
final class AdCounter {

  /**
   * The counting sources.
   *
   * 'booking:<id>', 'booking' (before per-booking counts), 'house', 'email'
   * or 'provider:<machine name>'.
   */
  public const SOURCE = '/^(booking(:[1-9][0-9]{0,9})?|house|email|provider:[a-z0-9_]{1,64})$/';

  public function __construct(
    private readonly Connection $database,
    private readonly SiteDay $siteDay,
  ) {}

  /**
   * The counting source of a booking.
   */
  public static function bookingSource(int $booking): string {
    return 'booking:' . $booking;
  }

  /**
   * The booking id a source names, or NULL.
   */
  public static function sourceBooking(string $source): ?int {
    return preg_match('/^booking:([1-9][0-9]{0,9})$/', $source, $m) === 1 ? (int) $m[1] : NULL;
  }

  /**
   * Adds views.
   */
  public function view(string $slot, int $creative, string $source, int $views = 1): void {
    $this->add($slot, $creative, $source, 'views', $views);
  }

  /**
   * Adds one click.
   */
  public function click(string $slot, int $creative, string $source): void {
    $this->add($slot, $creative, $source, 'clicks', 1);
  }

  /**
   * Totals per day for a creative (optionally only some spaces), newest first.
   *
   * @param int $creative
   *   Creative id.
   * @param string[] $slots
   *   Space ids; empty means all.
   * @param string $from
   *   First day, YYYY-MM-DD, or ''.
   * @param string $to
   *   Last day, YYYY-MM-DD, or ''.
   * @param int|null $booking
   *   Only this booking's own rows (source booking:<id>), or NULL for all.
   *
   * @return array<int, array{day: string, slot: string, source: string, views: int, clicks: int}>
   *   Rows.
   */
  public function rows(int $creative, array $slots = [], string $from = '', string $to = '', ?int $booking = NULL): array {
    $query = $this->database->select('ad_inventory_count', 'c')
      ->fields('c', ['day', 'slot', 'source', 'views', 'clicks'])
      ->condition('creative', $creative)
      ->orderBy('day', 'DESC')
      ->orderBy('slot');
    if ($slots !== []) {
      $query->condition('slot', $slots, 'IN');
    }
    if ($from !== '') {
      $query->condition('day', $from, '>=');
    }
    if ($to !== '') {
      $query->condition('day', $to, '<=');
    }
    if ($booking !== NULL) {
      $query->condition('source', self::bookingSource($booking));
    }
    $rows = [];
    foreach ($query->execute() as $row) {
      $rows[] = [
        'day' => (string) $row->day,
        'slot' => (string) $row->slot,
        'source' => (string) $row->source,
        'views' => (int) $row->views,
        'clicks' => (int) $row->clicks,
      ];
    }
    return $rows;
  }

  /**
   * Views this week in a space, per creative, for some bookings.
   *
   * @param string $slot
   *   Space id.
   * @param int[] $bookings
   *   Booking ids.
   *
   * @return array<int, array<int, int>>
   *   Booking id → creative id → views, Monday to today.
   */
  public function weekViews(string $slot, array $bookings): array {
    $views = [];
    if ($bookings === []) {
      return $views;
    }
    [$monday, $sunday] = $this->week();
    $sources = array_map(self::bookingSource(...), $bookings);
    $query = $this->database->select('ad_inventory_count', 'c')
      ->fields('c', ['source', 'creative'])
      ->condition('slot', $slot)
      ->condition('source', $sources, 'IN')
      ->condition('day', $monday, '>=')
      ->condition('day', $sunday, '<=')
      ->groupBy('source')
      ->groupBy('creative');
    $query->addExpression('SUM([views])', 'views');
    foreach ($query->execute() as $row) {
      $booking = self::sourceBooking((string) $row->source);
      if ($booking !== NULL) {
        $views[$booking][(int) $row->creative] = (int) $row->views;
      }
    }
    return $views;
  }

  /**
   * The site's current week: Monday and Sunday, YYYY-MM-DD.
   *
   * @return array{0: string, 1: string}
   *   First and last day.
   */
  public function week(): array {
    $today = new \DateTimeImmutable($this->siteDay->today());
    $monday = $today->modify('-' . ((int) $today->format('N') - 1) . ' days');
    return [$monday->format('Y-m-d'), $monday->modify('+6 days')->format('Y-m-d')];
  }

  /**
   * Upserts one total.
   */
  private function add(string $slot, int $creative, string $source, string $column, int $amount): void {
    if ($amount < 1 || preg_match(self::SOURCE, $source) !== 1) {
      return;
    }
    $this->database->merge('ad_inventory_count')
      ->keys([
        'day' => $this->siteDay->today(),
        'slot' => mb_substr($slot, 0, 64),
        'creative' => max(0, $creative),
        'source' => $source,
      ])
      ->fields([$column => $amount])
      ->expression($column, "[$column] + :amount", [':amount' => $amount])
      ->execute();
  }

}
