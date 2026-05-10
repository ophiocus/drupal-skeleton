<?php

declare(strict_types=1);

namespace Drupal\example_module\Drush\Commands;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\example_module\Service\Greeter;
use Drush\Attributes\Argument;
use Drush\Attributes\Command;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;

/**
 * Skeleton Drush command class.
 *
 * Drush 12+ uses attribute-based discovery; the file lives at
 * src/Drush/Commands/<Name>Commands.php. No drush.services.yml
 * is required — AutowireTrait pulls services from the container
 * by their type-hint.
 *
 * Battle scar: DrushCommands inherits a protected writeln() from
 * Symfony Console. Naming a private helper writeln() collides at
 * load time. Use line() instead (or any non-conflicting name).
 */
final class ExampleCommands extends DrushCommands {
  use AutowireTrait;
  use StringTranslationTrait;

  public function __construct(
    private readonly Greeter $greeter,
  ) {
    parent::__construct();
  }

  /**
   * Greet someone (proves the command class is wired up).
   */
  #[Command(name: 'example-module:greet', aliases: ['emg'])]
  #[Argument(name: 'name', description: 'Person to greet.')]
  public function greet(string $name = 'world'): void {
    $this->line($this->greeter->greet($name));
  }

  /**
   * Helper — writeln() collides with the parent's protected method,
   * so we expose a thin alias. Use this from your own commands.
   */
  private function line(string $message): void {
    $this->output()->writeln($message);
  }

}
