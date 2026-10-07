<?php

declare(strict_types=1);

namespace Drupal\edge_purge\Cache;

use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\edge_purge\PurgeQueue;

/**
 * Queues every invalidated cache tag for the CDN purger.
 *
 * Written at once, not at the end of the request: an entity save runs in a
 * transaction, so a save that rolls back takes its queued tags with it.
 */
final class PurgeQueueInvalidator implements CacheTagsInvalidatorInterface {

  public function __construct(
    private readonly PurgeQueue $queue,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function invalidateTags(array $tags): void {
    try {
      $this->queue->add($tags);
    }
    catch (\Exception) {
      // The table does not exist while the module is being installed or
      // uninstalled. A missed purge costs one max-age, never the request.
    }
  }

}
