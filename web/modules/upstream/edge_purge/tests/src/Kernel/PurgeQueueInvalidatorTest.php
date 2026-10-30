<?php

declare(strict_types=1);

namespace Drupal\Tests\edge_purge\Kernel;

use Drupal\Core\Cache\Cache;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * What reaches the purge queue, and what a router rebuild leaves out.
 */
#[Group('edge_purge')]
#[RunTestsInSeparateProcesses]
final class PurgeQueueInvalidatorTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'edge_purge'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('edge_purge', ['edge_purge_queue']);
  }

  /**
   * The tags queued now.
   *
   * @return string[]
   *   Sorted.
   */
  private function queued(): array {
    $tags = $this->container->get('database')
      ->query('SELECT tag FROM {edge_purge_queue}')
      ->fetchCol();
    sort($tags);
    return $tags;
  }

  /**
   * An entity's tag goes; its storage-cache tags do not.
   */
  public function testInternalTagsNeverQueued(): void {
    Cache::invalidateTags(['node:1', 'node:1:revisions', 'node_values', 'http_response']);
    $this->assertSame(['node:1'], $this->queued());
  }

  /**
   * A router rebuild ("drush cr") queues nothing a reader could see.
   */
  public function testRouterRebuildQueuesOnlyNotFoundPages(): void {
    $this->container->get('router.builder')->rebuild();
    $this->assertSame(['4xx-response'], $this->queued());

    // The hold ends with the rebuild: the next save is purged again.
    Cache::invalidateTags(['node:2']);
    $this->assertSame(['4xx-response', 'node:2'], $this->queued());
  }

  /**
   * Clearing the render cache queues nothing: "rendered" is on every page.
   */
  public function testRenderedNeverQueuedByInvalidation(): void {
    Cache::invalidateTags(['rendered', 'node:3']);
    $this->assertSame(['node:3'], $this->queued());

    // A deliberate full purge still goes, by the queue itself.
    $this->container->get('edge_purge.queue')->add(['rendered']);
    $this->assertSame(['node:3', 'rendered'], $this->queued());
  }

  /**
   * A claim names each hash's tag, for the purger's log.
   */
  public function testClaimNamesTags(): void {
    Cache::invalidateTags(['node:4', 'config:block_list']);
    $claim = $this->container->get('edge_purge.queue')->claim(10);
    $this->assertCount(2, $claim['hashes']);
    $this->assertEqualsCanonicalizing(['node:4', 'config:block_list'], array_values($claim['tags']));
    $this->assertSame($claim['hashes'], array_keys($claim['tags']));
    $this->assertGreaterThan(0, $claim['through']);
  }

}
