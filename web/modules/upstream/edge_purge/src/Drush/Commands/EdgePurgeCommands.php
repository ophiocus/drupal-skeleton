<?php

declare(strict_types=1);

namespace Drupal\edge_purge\Drush\Commands;

use Drupal\Core\State\StateInterface;
use Drupal\edge_purge\EdgeTags;
use Drupal\edge_purge\PurgeQueue;
use Drupal\edge_purge\RenderFingerprint;
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
    private readonly StateInterface $state,
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
   * Says whether this release changed render code; purges nothing itself.
   *
   * Run once per deploy, after "drush deploy". Compares the files that can
   * change a page (RenderFingerprint) with the last release's and prints
   * "rendered …" when any changed, "unchanged …" when none did. On
   * "rendered" the host verifies every page by its X-Edge-Hash and purges by
   * URL only those whose HTML changed (Carlos, 2026-10-20: "kill all purge
   * that is not targeted"); it no longer queues "rendered", which purged
   * every page. --purge-all still does, by hand. What the release changed
   * in config or content was queued by its own tags. The first run records
   * the files. Prints one line, then the changed files.
   */
  #[Command(name: 'edge-purge:release')]
  #[Option(name: 'dry-run', description: 'Report, record nothing, queue nothing.')]
  #[Option(name: 'purge-all', description: 'On a render change, queue "rendered" (every page) as well.')]
  public function release(array $options = ['dry-run' => FALSE, 'purge-all' => FALSE]): void {
    $before = $this->state->get(RenderFingerprint::STATE);
    $after = RenderFingerprint::files(DRUPAL_ROOT);
    $changed = is_array($before) ? RenderFingerprint::changed($before, $after) : [];
    $dry = !empty($options['dry-run']);
    if (!is_array($before)) {
      $line = sprintf('baseline %d files, nothing purged', count($after));
    }
    elseif (!$changed) {
      $line = sprintf('unchanged %d files, nothing purged', count($after));
    }
    else {
      $all = !empty($options['purge-all']);
      $line = sprintf('rendered %d of %d files changed, %s', count($changed), count($after),
        $all ? 'every page purged' : 'pages to verify by hash');
      if (!$dry && $all) {
        $this->queue->add(['rendered']);
      }
    }
    if (!$dry) {
      $this->state->set(RenderFingerprint::STATE, $after);
    }
    $this->output()->writeln(($dry ? '(dry run) ' : '') . $line);
    foreach (array_slice($changed, 0, 20) as $path) {
      $this->output()->writeln('  ' . $path);
    }
    if (count($changed) > 20) {
      $this->output()->writeln(sprintf('  … and %d more', count($changed) - 20));
    }
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
