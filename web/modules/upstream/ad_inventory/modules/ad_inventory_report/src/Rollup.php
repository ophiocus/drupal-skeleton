<?php

declare(strict_types=1);

namespace Drupal\ad_inventory_report;

use Drupal\ad_inventory\Calendar;
use Drupal\ad_inventory\Entity\AdBookingInterface;
use Drupal\ad_inventory\Inventory;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\State\StateInterface;

/**
 * Rolls the parent's counts into one row per day, slot, source and creative.
 *
 * Source: "network" for a provider fill (creative "provider:<id>"),
 * "booking" when an approved or stopped booking of that creative covered
 * that slot on that day (its id is kept), "house" for a house creative, and
 * "unknown" for a creative that is neither any more (a deleted booking).
 * Each run recomputes whole days (every day on the first run, then the last
 * few) by deleting and re-inserting them, so a second run changes nothing
 * and the totals always equal the raw counts. The dashboard reads only this
 * table.
 */
final class Rollup {

  /**
   * The table.
   */
  public const TABLE = 'ad_inventory_report_daily';

  /**
   * The cache tag of everything drawn from the rollups.
   */
  public const CACHE_TAG = 'ad_inventory_report:daily';

  /**
   * The state key of the last day rolled.
   */
  public const STATE = 'ad_inventory_report.rolled_through';

  public function __construct(
    private readonly Connection $database,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly Inventory $inventory,
    private readonly Calendar $calendar,
    private readonly StateInterface $state,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly CacheTagsInvalidatorInterface $invalidator,
  ) {}

  /**
   * Rolls the days that may have changed.
   *
   * @return string[]
   *   The days rolled.
   */
  public function run(): array {
    $today = $this->calendar->today();
    $last = $this->state->get(self::STATE);
    if (is_string($last) && Calendar::isDay($last)) {
      $window = max(1, (int) $this->configFactory->get('ad_inventory_report.settings')->get('rollup_days'));
      $from = min(Calendar::shift($last, -($window - 1)), Calendar::shift($today, -($window - 1)));
    }
    else {
      $from = (string) $this->database->query('SELECT MIN([day]) FROM {ad_inventory_count}')->fetchField();
      if ($from === '') {
        $this->state->set(self::STATE, $today);
        return [];
      }
    }
    $days = $this->roll($from, $today);
    $this->state->set(self::STATE, $today);
    return $days;
  }

  /**
   * Recomputes every day from one day to another.
   *
   * @return string[]
   *   The days rolled.
   */
  public function roll(string $from, string $to): array {
    $bookings = $this->bookings($from, $to);
    $house = $this->houseIds();
    $rows = [];
    $result = $this->database->select('ad_inventory_count', 'c')
      ->fields('c', ['day', 'slot', 'creative', 'views', 'clicks', 'unfilled'])
      ->condition('day', [$from, $to], 'BETWEEN')
      ->execute();
    foreach ($result as $raw) {
      [$source, $booking] = $this->source((string) $raw->day, (string) $raw->slot, (string) $raw->creative, $bookings, $house);
      $key = implode('|', [$raw->day, $raw->slot, $source, $raw->creative, $booking]);
      $rows[$key] ??= [
        'day' => (string) $raw->day,
        'slot' => (string) $raw->slot,
        'source' => $source,
        'creative' => (string) $raw->creative,
        'booking' => $booking,
        'views' => 0,
        'clicks' => 0,
        'unfilled' => 0,
      ];
      $rows[$key]['views'] += (int) $raw->views;
      $rows[$key]['clicks'] += (int) $raw->clicks;
      $rows[$key]['unfilled'] += (int) $raw->unfilled;
    }

    $transaction = $this->database->startTransaction();
    try {
      $this->database->delete(self::TABLE)->condition('day', [$from, $to], 'BETWEEN')->execute();
      if ($rows) {
        $insert = $this->database->insert(self::TABLE)->fields([
          'day',
          'slot',
          'source',
          'creative',
          'booking',
          'views',
          'clicks',
          'unfilled',
        ]);
        foreach ($rows as $row) {
          $insert->values($row);
        }
        $insert->execute();
      }
    }
    catch (\Throwable $e) {
      $transaction->rollBack();
      throw $e;
    }
    unset($transaction);
    $this->invalidator->invalidateTags([self::CACHE_TAG]);
    $days = array_values(array_unique(array_column($rows, 'day')));
    sort($days);
    return $days;
  }

  /**
   * The source and booking of one raw row.
   *
   * @return array{0: string, 1: int}
   *   The source, and the booking id or 0.
   */
  private function source(string $day, string $slot, string $creative, array $bookings, array $house): array {
    if (str_starts_with($creative, 'provider:')) {
      return ['network', 0];
    }
    foreach ($bookings[$creative] ?? [] as $booking) {
      if ($booking['start'] <= $day && $booking['end'] >= $day && ($slot === '' || in_array($slot, $booking['slots'], TRUE))) {
        return ['booking', $booking['id']];
      }
    }
    return [isset($house[$creative]) ? 'house' : 'unknown', 0];
  }

  /**
   * Bookings that may have run in a period, by creative id.
   */
  private function bookings(string $from, string $to): array {
    $storage = $this->entityTypeManager->getStorage('ad_booking');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('state', ['approved', 'stopped'], 'IN')
      ->condition('start_day', $to, '<=')
      ->condition('end_day', $from, '>=')
      ->sort('id')
      ->execute();
    $by = [];
    foreach ($storage->loadMultiple($ids) as $booking) {
      if (!$booking instanceof AdBookingInterface || $booking->creative() === NULL) {
        continue;
      }
      $by[(string) $booking->creative()->id()][] = [
        'id' => (int) $booking->id(),
        'start' => $booking->start(),
        'end' => $booking->end(),
        'slots' => $this->inventory->expandSlots($booking->slotValues()),
      ];
    }
    return $by;
  }

  /**
   * Ids of house creatives, as keys.
   */
  private function houseIds(): array {
    $ids = $this->entityTypeManager->getStorage('ad_creative')->getQuery()
      ->accessCheck(FALSE)
      ->condition('house', 1)
      ->execute();
    return array_fill_keys(array_map('strval', $ids), TRUE);
  }

}
