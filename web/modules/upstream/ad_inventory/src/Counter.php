<?php

declare(strict_types=1);

namespace Drupal\ad_inventory;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * First-party counts: views, clicks and unfilled units per day.
 *
 * One row per (day, slot, creative) in `ad_inventory_count`. Nothing about
 * the reader is stored: no IP, user agent, URL or identifier. Unknown slot,
 * creative or provider ids are rejected, so a forged beacon can only add to
 * rows that exist.
 */
final class Counter {

  /**
   * The table.
   */
  public const TABLE = 'ad_inventory_count';

  /**
   * Ids already checked in this request.
   *
   * @var array<string, bool>
   */
  private array $known = [];

  public function __construct(
    private readonly Connection $database,
    private readonly Calendar $calendar,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Counts a beacon's pairs.
   *
   * @param array $payload
   *   As BeaconPayload::parse() returns it.
   *
   * @return int
   *   How many pairs were counted.
   */
  public function beacon(array $payload): int {
    $day = $this->calendar->today();
    $counted = 0;
    foreach ($payload['views'] ?? [] as [$slot, $creative]) {
      if ($this->knownSlot($slot) && $this->knownCreative($creative)) {
        $this->add($day, $slot, $creative, 'views');
        $counted++;
      }
    }
    foreach ($payload['unfilled'] ?? [] as [$slot, $creative]) {
      if ($this->knownSlot($slot) && $this->knownCreative($creative)) {
        $this->add($day, $slot, $creative, 'unfilled');
        $counted++;
      }
    }
    return $counted;
  }

  /**
   * Counts one click.
   *
   * @param string $slot
   *   The slot id, or '' when the link did not say.
   * @param string $creative
   *   The creative id.
   */
  public function click(string $slot, string $creative): void {
    if ($slot !== '' && !$this->knownSlot($slot)) {
      $slot = '';
    }
    if ($this->knownCreative($creative)) {
      $this->add($this->calendar->today(), $slot, $creative, 'clicks');
    }
  }

  /**
   * Adds one to a counter of a row, creating the row.
   */
  public function add(string $day, string $slot, string $creative, string $column, int $by = 1): void {
    $fields = ['views' => 0, 'clicks' => 0, 'unfilled' => 0];
    $fields[$column] = $by;
    $this->database->merge(self::TABLE)
      ->keys(['day' => $day, 'slot' => $slot, 'creative' => $creative])
      ->insertFields(['day' => $day, 'slot' => $slot, 'creative' => $creative] + $fields)
      ->expression($column, '[' . $column . '] + :by', [':by' => $by])
      ->execute();
  }

  /**
   * Rows of a period.
   *
   * @return array<int, array{day: string, slot: string, creative: string, views: int, clicks: int, unfilled: int}>
   *   Rows ordered by day.
   */
  public function rows(string $from, string $to): array {
    $result = $this->database->select(self::TABLE, 'c')
      ->fields('c', ['day', 'slot', 'creative', 'views', 'clicks', 'unfilled'])
      ->condition('day', [$from, $to], 'BETWEEN')
      ->orderBy('day')
      ->orderBy('slot')
      ->orderBy('creative')
      ->execute();
    $rows = [];
    foreach ($result as $row) {
      $rows[] = [
        'day' => (string) $row->day,
        'slot' => (string) $row->slot,
        'creative' => (string) $row->creative,
        'views' => (int) $row->views,
        'clicks' => (int) $row->clicks,
        'unfilled' => (int) $row->unfilled,
      ];
    }
    return $rows;
  }

  /**
   * Whether a slot id exists.
   */
  private function knownSlot(string $slot): bool {
    return $this->known['slot:' . $slot] ??= $this->entityTypeManager->getStorage('ad_slot')->load($slot) !== NULL;
  }

  /**
   * Whether a creative id or "provider:<id>" exists.
   */
  private function knownCreative(string $creative): bool {
    if (!BeaconPayload::isCreative($creative)) {
      return FALSE;
    }
    if (str_starts_with($creative, 'provider:')) {
      return $this->known['provider:' . $creative] ??= $this->entityTypeManager->getStorage('ad_provider')->load(substr($creative, 9)) !== NULL;
    }
    return $this->known['creative:' . $creative] ??= (bool) $this->entityTypeManager->getStorage('ad_creative')->getQuery()
      ->accessCheck(FALSE)
      ->condition('id', (int) $creative)
      ->count()
      ->execute();
  }

}
