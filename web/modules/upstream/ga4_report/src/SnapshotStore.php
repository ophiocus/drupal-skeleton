<?php

declare(strict_types=1);

namespace Drupal\ga4_report;

use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreInterface;

/**
 * Each source's latest snapshot and what happened at its last refresh.
 *
 * A key-value collection, not State: State values read in a request are
 * cached together and loaded on every request, and a snapshot is a few
 * hundred kilobytes only the report page needs. Not config either: it is
 * data fetched from outside, different in every environment.
 *
 * Per source: "<source>.snapshot" (replaced only by a refresh that got all of
 * that source's data) and "<source>.meta" (its last attempt, the outcome, and
 * the snapshot's provenance). "run" records the last refresh of any kind,
 * which the cron interval counts from.
 */
final class SnapshotStore {

  /**
   * The cache tag of everything drawn from a snapshot.
   */
  public const CACHE_TAG = 'ga4_report_snapshot';

  /**
   * The collection.
   */
  private KeyValueStoreInterface $store;

  public function __construct(
    KeyValueFactoryInterface $keyValue,
    private readonly CacheTagsInvalidatorInterface $invalidator,
  ) {
    $this->store = $keyValue->get('ga4_report');
  }

  /**
   * A source's latest complete snapshot, or NULL before its first one.
   */
  public function snapshot(string $source): ?array {
    $snapshot = $this->store->get($source . '.snapshot');
    return is_array($snapshot) ? $snapshot : NULL;
  }

  /**
   * A source's last attempt and its snapshot's provenance.
   *
   * @return array
   *   Keys (each optional): attempted (timestamp), error (set when the last
   *   attempt failed), fetched (timestamp of the snapshot), duration
   *   (seconds), and whatever the source records about its data.
   */
  public function meta(string $source): array {
    return (array) $this->store->get($source . '.meta', []);
  }

  /**
   * When any refresh last ran.
   */
  public function lastRun(): int {
    return (int) $this->store->get('run', 0);
  }

  /**
   * Records that a refresh ran.
   */
  public function recordRun(int $time): void {
    $this->store->set('run', $time);
  }

  /**
   * Stores a source's new snapshot.
   */
  public function save(string $source, array $snapshot, array $meta): void {
    $this->store->setMultiple([
      $source . '.snapshot' => $snapshot,
      $source . '.meta' => ['error' => NULL] + $meta,
    ]);
    $this->invalidator->invalidateTags([self::CACHE_TAG]);
  }

  /**
   * Records a source's failed attempt; its previous snapshot stays.
   */
  public function recordFailure(string $source, string $error, int $attempted): void {
    $this->store->set($source . '.meta', ['attempted' => $attempted, 'error' => $error] + $this->meta($source));
    $this->invalidator->invalidateTags([self::CACHE_TAG]);
  }

}
