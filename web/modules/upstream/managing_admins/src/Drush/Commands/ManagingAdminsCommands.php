<?php

declare(strict_types=1);

namespace Drupal\managing_admins\Drush\Commands;

use Drupal\managing_admins\ManagingAdmins;
use Drush\Attributes\Command;
use Drush\Attributes\Option;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;

/**
 * Converges the managing admins the host lists in MANAGING_ADMINS.
 *
 * The host's seed step (tec-seed) runs it on every deploy, after the site's
 * own property:seed. The list never enters the repository or config: it is
 * the container's environment, from the site's .env on the host.
 */
final class ManagingAdminsCommands extends DrushCommands {
  use AutowireTrait;

  public function __construct(
    private readonly ManagingAdmins $admins,
  ) {
    parent::__construct();
  }

  /**
   * Makes every listed account exist with the administrator role.
   */
  #[Command(name: 'managing-admins:converge')]
  #[Option(name: 'check', description: 'Report what would change; change nothing.')]
  public function converge(array $options = ['check' => FALSE]): void {
    $list = (string) getenv('MANAGING_ADMINS');
    if (trim($list) === '') {
      $this->output()->writeln('managing admins: none listed (MANAGING_ADMINS is empty here), nothing to do');
      return;
    }
    $check = (bool) $options['check'];
    $result = $this->admins->converge($this->admins->parse($list), $check);
    $done = [];
    foreach ($result as $name => $did) {
      $done[] = "$name $did";
    }
    $this->output()->writeln('managing admins' . ($check ? ' (check, nothing written)' : '') . ': ' . implode('; ', $done));
  }

}
