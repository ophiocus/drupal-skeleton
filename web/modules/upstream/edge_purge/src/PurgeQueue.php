<?php

declare(strict_types=1);

namespace Drupal\edge_purge;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;

/**
 * Invalidated tags waiting for the CDN purger.
 *
 * Drupal never calls the CDN itself: the API token stays on the host, and a
 * host timer drains this queue (claim, purge, ack) at the CDN's rate limit.
 * One row per tag, so a tag invalidated a thousand times is purged once.
 */
final class PurgeQueue {

  private const TABLE = 'edge_purge_queue';

  public function __construct(
    private readonly Connection $database,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Queues tags for purging.
   *
   * @param string[] $tags
   *   Drupal cache tags.
   */
  public function add(array $tags): void {
    if (!$tags) {
      return;
    }
    $seq = (int) ($this->time->getCurrentMicroTime() * 1000000);
    $upsert = $this->database->upsert(self::TABLE)
      ->key('hash')
      ->fields(['hash', 'tag', 'seq']);
    foreach (array_unique($tags) as $tag) {
      $upsert->values([EdgeTags::hash($tag), mb_substr($tag, 0, 255), $seq]);
    }
    $upsert->execute();
  }

  /**
   * The oldest queued tags, and the sequence number that covers them.
   *
   * @return array{through: int, hashes: string[]}
   *   Pass "through" back to ack(), so a tag invalidated again after this
   *   claim stays queued.
   */
  public function claim(int $limit): array {
    $rows = $this->database->select(self::TABLE, 'q')
      ->fields('q', ['hash', 'seq'])
      ->orderBy('seq')
      ->range(0, $limit)
      ->execute()
      ->fetchAllKeyed();
    return [
      'through' => $rows ? max(array_map('intval', $rows)) : 0,
      'hashes' => array_map('strval', array_keys($rows)),
    ];
  }

  /**
   * Removes purged tags not invalidated again since they were claimed.
   *
   * @param string[] $hashes
   *   Hashes returned by claim().
   * @param int $through
   *   The "through" value returned with them.
   */
  public function ack(array $hashes, int $through): int {
    if (!$hashes) {
      return 0;
    }
    return (int) $this->database->delete(self::TABLE)
      ->condition('hash', $hashes, 'IN')
      ->condition('seq', $through, '<=')
      ->execute();
  }

  /**
   * How many tags are waiting.
   */
  public function count(): int {
    return (int) $this->database->select(self::TABLE)->countQuery()->execute()->fetchField();
  }

  /**
   * Empties the queue, after the whole site was purged by hostname.
   */
  public function clear(): void {
    $this->database->truncate(self::TABLE)->execute();
  }

}
