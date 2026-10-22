<?php

declare(strict_types=1);

namespace Drupal\site_seed\Drush\Commands;

use Drupal\site_seed\SeedRunner;
use Drush\Attributes\Argument;
use Drush\Attributes\Command;
use Drush\Attributes\Option;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;

/**
 * Runs a seed file from the shell.
 *
 * A site's own `property:seed` (the alias every deploy runs) calls the runner
 * with its seed file; this command is for running or checking any seed file
 * by hand.
 */
final class SiteSeedCommands extends DrushCommands {
  use AutowireTrait;

  public function __construct(
    private readonly SeedRunner $runner,
  ) {
    parent::__construct();
  }

  /**
   * Converges a seed file onto the database, or reports what it would change.
   */
  #[Command(name: 'site-seed:run')]
  #[Argument(name: 'file', description: 'The seed file, relative to the Drupal root or absolute.')]
  #[Option(name: 'check', description: 'Report what would change; change nothing.')]
  public function run(string $file, array $options = ['check' => FALSE]): void {
    $path = str_starts_with($file, '/') ? $file : DRUPAL_ROOT . '/' . $file;
    $result = $this->runner->run($path, (bool) $options['check']);
    $this->output()->writeln(($options['check'] ? 'seed (check, nothing written): ' : 'seed: ') . self::format($result['tally']));
    if ($result['unhandled']) {
      $this->output()->writeln('not handled by site_seed: ' . implode(', ', $result['unhandled']));
    }
  }

  /**
   * The tally as the seed step has always printed it.
   *
   * @param array<string, int> $tally
   *   What changed, by kind.
   */
  public static function format(array $tally): string {
    return http_build_query($tally, '', ', ');
  }

}
