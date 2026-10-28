<?php

declare(strict_types=1);

namespace Drupal\appointments\Drush\Commands;

use Drupal\appointments\EntitySchema;
use Drush\Attributes\Command;
use Drush\Attributes\Option;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;

/**
 * The one-time entity-definition step of a site installed on an older version.
 */
final class AppointmentsCommands extends DrushCommands {
  use AutowireTrait;

  public function __construct(
    private readonly EntitySchema $schema,
  ) {
    parent::__construct();
  }

  /**
   * Brings the appointment entity tables up to this version of the code.
   *
   * Run once after the deploy that brings a new version, before the seed.
   * Safe to run again: it does only what the database still lacks.
   */
  #[Command(name: 'appointments:entity-updates')]
  #[Option(name: 'check', description: 'Report what is pending; change nothing.')]
  public function entityUpdates(array $options = ['check' => FALSE]): void {
    $steps = $options['check'] ? $this->schema->pending() : $this->schema->apply();
    if (!$steps) {
      $this->output()->writeln('appointments: entity tables up to date');
      return;
    }
    foreach ($steps as $step) {
      $this->output()->writeln(($options['check'] ? 'appointments: pending: ' : 'appointments: ') . $step);
    }
  }

}
