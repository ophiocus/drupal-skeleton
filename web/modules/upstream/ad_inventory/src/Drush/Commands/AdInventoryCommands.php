<?php

declare(strict_types=1);

namespace Drupal\ad_inventory\Drush\Commands;

use Drupal\ad_inventory\Scheduler;
use Drupal\ad_inventory\StatusChecker;
use Drush\Attributes\Command;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;

/**
 * The ad inventory's status and its daily run, from the shell.
 */
final class AdInventoryCommands extends DrushCommands {
  use AutowireTrait;

  public function __construct(
    private readonly StatusChecker $status,
    private readonly Scheduler $scheduler,
  ) {
    parent::__construct();
  }

  /**
   * Names every closed gate; exits 1 while any is an error.
   */
  #[Command(name: 'ad-inventory:status', aliases: ['adi-status'])]
  public function status(): int {
    $errors = 0;
    foreach ($this->status->gates() as $gate) {
      $errors += $gate['severity'] === 'error' ? 1 : 0;
      $this->output()->writeln(sprintf('%-7s %-24s %s', strtoupper($gate['severity']), $gate['gate'], $gate['message']));
    }
    return $errors > 0 ? self::EXIT_FAILURE : self::EXIT_SUCCESS;
  }

  /**
   * Runs the daily work now (cron does it once a day).
   */
  #[Command(name: 'ad-inventory:day')]
  public function day(): void {
    $report = $this->scheduler->run();
    $this->output()->writeln(sprintf(
      '%s: %s; slots invalidated: %s; bookings stopped: %s',
      $report['day'],
      $report['ran'] ? 'ran' : 'already ran today',
      $report['slots'] ? implode(', ', $report['slots']) : 'none',
      $report['stopped'] ? implode(', ', $report['stopped']) : 'none',
    ));
  }

}
