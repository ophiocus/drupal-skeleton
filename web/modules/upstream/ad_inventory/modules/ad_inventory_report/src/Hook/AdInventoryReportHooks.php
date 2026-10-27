<?php

declare(strict_types=1);

namespace Drupal\ad_inventory_report\Hook;

use Drupal\ad_inventory_report\Rollup;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\user\Entity\Role;

/**
 * Hook implementations for ad_inventory_report.
 */
final class AdInventoryReportHooks {

  /**
   * The role the module creates.
   */
  public const ROLE = 'ad_inventory_overwatch';

  public function __construct(
    private readonly Rollup $rollup,
  ) {}

  /**
   * Implements hook_cron().
   *
   * Rolls the parent's counts into the dashboard's table.
   */
  #[Hook('cron')]
  public function cron(): void {
    $this->rollup->run();
  }

  /**
   * Implements hook_modules_installed().
   */
  #[Hook('modules_installed')]
  public function modulesInstalled(array $modules, bool $is_syncing): void {
    if (!$is_syncing && in_array('toolbar', $modules, TRUE)) {
      self::grantToolbar();
    }
  }

  /**
   * Gives the Marketeer overwatch role "access toolbar".
   */
  public static function grantToolbar(): void {
    $role = Role::load(self::ROLE);
    if ($role !== NULL && !$role->hasPermission('access toolbar')) {
      $role->grantPermission('access toolbar')->save();
    }
  }

}
