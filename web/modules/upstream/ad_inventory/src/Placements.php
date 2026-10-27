<?php

declare(strict_types=1);

namespace Drupal\ad_inventory;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreInterface;

/**
 * Where slots were rendered: slot id, route name and how, nothing else.
 *
 * Block placements can be read from block config; a slot a template prints
 * through `#type => 'ad_slot'` cannot. So the render element records, on a
 * render-cache miss only, the slot id and the route name (no URL, no entity
 * id, no reader data). Rows not seen in 90 days are removed at cron.
 */
final class Placements {

  /**
   * The key-value collection.
   */
  public const COLLECTION = 'ad_inventory.placements';

  /**
   * How long an unseen placement is kept, in seconds.
   */
  public const MAX_AGE = 90 * 86400;

  /**
   * The collection.
   */
  private KeyValueStoreInterface $store;

  public function __construct(
    KeyValueFactoryInterface $keyValue,
    private readonly TimeInterface $time,
  ) {
    $this->store = $keyValue->get(self::COLLECTION);
  }

  /**
   * Records that a slot was rendered on a route.
   *
   * @param string $slot
   *   The slot id.
   * @param string $route
   *   The route name.
   * @param string $via
   *   How it was printed: "block" or "template".
   */
  public function record(string $slot, string $route, string $via): void {
    $key = $slot . '|' . $route . '|' . $via;
    $now = $this->time->getRequestTime();
    $seen = $this->store->get($key);
    // One write a day per placement is enough for a 90-day horizon.
    if (is_array($seen) && ($seen['seen'] ?? 0) > $now - 86400) {
      return;
    }
    $this->store->set($key, ['slot' => $slot, 'route' => $route, 'via' => $via, 'seen' => $now]);
  }

  /**
   * Every recorded placement.
   *
   * @return array<int, array{slot: string, route: string, via: string, seen: int}>
   *   Placements.
   */
  public function all(): array {
    $all = array_values(array_filter($this->store->getAll(), 'is_array'));
    usort($all, static fn(array $a, array $b): int => [$a['slot'], $a['route']] <=> [$b['slot'], $b['route']]);
    return $all;
  }

  /**
   * Removes placements not seen for MAX_AGE.
   *
   * @return int
   *   How many were removed.
   */
  public function prune(): int {
    $limit = $this->time->getRequestTime() - self::MAX_AGE;
    $old = [];
    foreach ($this->store->getAll() as $key => $value) {
      if (!is_array($value) || ($value['seen'] ?? 0) < $limit) {
        $old[] = $key;
      }
    }
    if ($old) {
      $this->store->deleteMultiple($old);
    }
    return count($old);
  }

}
