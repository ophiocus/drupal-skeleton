<?php

declare(strict_types=1);

namespace Drupal\ad_inventory;

use Drupal\Core\Database\Connection;

/**
 * Adds views and clicks to the daily totals, and reads them back.
 */
final class AdCounter {

  /**
   * A source is 'booking', 'house', 'email' or 'provider:<machine name>'.
   */
  public const SOURCE = '/^(booking|house|email|provider:[a-z0-9_]{1,64})$/';

  public function __construct(
    private readonly Connection $database,
    private readonly SiteDay $siteDay,
  ) {}

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
   *
   * @return array<int, array{day: string, slot: string, source: string, views: int, clicks: int}>
   *   Rows.
   */
  public function rows(int $creative, array $slots = [], string $from = '', string $to = ''): array {
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
