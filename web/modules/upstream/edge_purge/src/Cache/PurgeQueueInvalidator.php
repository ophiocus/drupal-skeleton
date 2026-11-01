<?php

declare(strict_types=1);

namespace Drupal\edge_purge\Cache;

use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\edge_purge\EdgeTags;
use Drupal\edge_purge\PurgeQueue;

/**
 * Queues every invalidated cache tag worth a CDN purge.
 *
 * Written at once, not at the end of the request: an entity save runs in a
 * transaction, so a save that rolls back takes its queued tags with it.
 *
 * A router rebuild (every "drush cr", every module install) invalidates the
 * tags of every menu and "http_response", which are on every page, without
 * a single page having changed. While one runs (RouterRebuildHold) nothing is
 * queued. A route new in a release that was cached as a 404 at the edge goes
 * with "drush edge-purge:path <path>".
 */
final class PurgeQueueInvalidator implements CacheTagsInvalidatorInterface {

  /**
   * Whether a router rebuild is running in this process.
   */
  private static bool $held = FALSE;

  public function __construct(
    private readonly PurgeQueue $queue,
  ) {}

  /**
   * Holds the queue: a router rebuild started.
   */
  public static function hold(): void {
    self::$held = TRUE;
  }

  /**
   * Releases the queue: the router rebuild finished.
   */
  public static function release(): void {
    self::$held = FALSE;
  }

  /**
   * Whether the queue is held.
   */
  public static function isHeld(): bool {
    return self::$held;
  }

  /**
   * {@inheritdoc}
   */
  public function invalidateTags(array $tags): void {
    $tags = self::$held ? [] : EdgeTags::purgeable($tags);
    if (!$tags) {
      return;
    }
    try {
      $this->queue->add($tags);
    }
    catch (\Exception) {
      // The table does not exist while the module is being installed or
      // uninstalled. A missed purge costs one max-age, never the request.
    }
  }

}
