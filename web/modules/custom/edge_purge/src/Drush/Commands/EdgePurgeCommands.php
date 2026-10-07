<?php

declare(strict_types=1);

namespace Drupal\edge_purge\Drush\Commands;

use Drupal\edge_purge\EdgeTags;
use Drupal\edge_purge\PurgeQueue;
use Drush\Attributes\Argument;
use Drush\Attributes\Command;
use Drush\Attributes\Option;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;

/**
 * The host purger's side of the queue: claim, purge at the CDN, ack.
 *
 * Output is for a shell script, not a person: claim prints one header line
 * "through <seq> remaining <n>" and then one hash per line.
 */
final class EdgePurgeCommands extends DrushCommands {
  use AutowireTrait;

  public function __construct(
    private readonly PurgeQueue $queue,
  ) {
    parent::__construct();
  }

  /**
   * Prints the oldest queued tags for the purger to send to the CDN.
   */
  #[Command(name: 'edge-purge:claim')]
  #[Option(name: 'limit', description: 'Most tags in one purge request (Cloudflare: 100).')]
  public function claim(array $options = ['limit' => 100]): void {
    $claim = $this->queue->claim(max(1, (int) $options['limit']));
    $remaining = $this->queue->count() - count($claim['hashes']);
    $this->output()->writeln(sprintf('through %d remaining %d', $claim['through'], $remaining));
    foreach ($claim['hashes'] as $hash) {
      $this->output()->writeln($hash);
    }
  }

  /**
   * Removes tags the CDN purged, unless invalidated again since the claim.
   */
  #[Command(name: 'edge-purge:ack')]
  #[Argument(name: 'hashes', description: 'Comma-separated hashes from edge-purge:claim.')]
  #[Option(name: 'through', description: 'The "through" value edge-purge:claim printed.')]
  public function ack(string $hashes, array $options = ['through' => 0]): void {
    $list = array_values(array_filter(array_map('trim', explode(',', $hashes))));
    $removed = $this->queue->ack($list, (int) $options['through']);
    $this->output()->writeln(sprintf('acked %d', $removed));
  }

  /**
   * How many tags wait for the purger.
   */
  #[Command(name: 'edge-purge:status')]
  public function status(): void {
    $this->output()->writeln(sprintf('queued %d', $this->queue->count()));
  }

  /**
   * Empties the queue, after the whole site was purged by hostname.
   */
  #[Command(name: 'edge-purge:clear')]
  public function clear(): void {
    $this->queue->clear();
    $this->output()->writeln('cleared');
  }

  /**
   * Prints the CDN name of a Drupal cache tag (for checking a header by eye).
   */
  #[Command(name: 'edge-purge:hash')]
  #[Argument(name: 'tag', description: 'A Drupal cache tag, e.g. node:1.')]
  public function hash(string $tag): void {
    $this->output()->writeln(EdgeTags::hash($tag));
  }

}
