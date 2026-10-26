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
 * The seed step, and a seed file run by hand.
 *
 * `property:seed` is the alias the platform's deploy runs on every release, so
 * a site that enables site_seed must not define it again in its own code.
 */
final class SiteSeedCommands extends DrushCommands {
  use AutowireTrait;

  public function __construct(
    private readonly SeedRunner $runner,
  ) {
    parent::__construct();
  }

  /**
   * Converges every seed file of the site (data/seeds/*.yml of its modules).
   */
  #[Command(name: 'site-seed:site', aliases: ['property:seed'])]
  #[Option(name: 'check', description: 'Report what would change; change nothing.')]
  public function site(array $options = ['check' => FALSE]): void {
    $result = $this->runner->runSite((bool) $options['check']);
    $this->output()->writeln(($options['check'] ? 'seed (check, nothing written): ' : 'seed: ') . self::format($result['tally']));
    if ($result['unhandled']) {
      $this->logger()->warning('not handled by any seed handler: @s', ['@s' => implode(', ', $result['unhandled'])]);
    }
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
