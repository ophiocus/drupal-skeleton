<?php

declare(strict_types=1);

namespace Drupal\edge_purge\Drush\Commands;

use Drupal\Core\State\StateInterface;
use Drupal\edge_purge\RenderFingerprint;
use Drupal\edge_purge\SoftRebuild;
use Drush\Attributes\Command;
use Drush\Attributes\Option;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;

/**
 * The deploy's cache rebuild, keeping the pages when it can (SoftRebuild).
 *
 * The host's deploy runs it in place of "drush cr", before and after the
 * config import, and falls back to "drush cr" if it fails. Run it before
 * "edge-purge:release", which records the files this compares against.
 */
final class SoftRebuildCommands extends DrushCommands {
  use AutowireTrait;

  public function __construct(
    private readonly StateInterface $state,
  ) {
    parent::__construct();
  }

  /**
   * Rebuilds every cache; keeps finished pages if no render code changed.
   */
  #[Command(name: 'edge-purge:rebuild')]
  #[Option(name: 'all', description: 'Empty the page bins too, as "drush cr" does.')]
  public function rebuild(array $options = ['all' => FALSE]): void {
    $changed = SoftRebuild::changed(
      $this->state->get(RenderFingerprint::STATE),
      RenderFingerprint::files(DRUPAL_ROOT),
    );
    $keep = empty($options['all']) && $changed === [];
    SoftRebuild::flush($keep);
    if ($keep) {
      $line = 'rebuilt; pages kept (render code unchanged since the last release)';
    }
    elseif (!empty($options['all'])) {
      $line = 'rebuilt; pages emptied (--all)';
    }
    elseif ($changed === NULL) {
      $line = 'rebuilt; pages emptied (no release recorded yet)';
    }
    else {
      $line = sprintf('rebuilt; pages emptied (%d render files changed)', count($changed));
    }
    $this->output()->writeln($line);
  }

}
