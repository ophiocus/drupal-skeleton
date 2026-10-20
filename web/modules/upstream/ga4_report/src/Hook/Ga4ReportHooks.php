<?php

declare(strict_types=1);

namespace Drupal\ga4_report\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\ga4_report\Refresher;
use Drupal\ga4_report\ReportException;

/**
 * Hook implementations for ga4_report.
 */
final class Ga4ReportHooks {

  public function __construct(
    private readonly Refresher $refresher,
  ) {}

  /**
   * Implements hook_cron().
   *
   * Refreshes every configured source when the interval has passed since the
   * last run, failed ones included, so a broken credential is not retried on
   * every cron run. A site that prefers system cron sets the interval to 0
   * and runs `drush ga4-report:refresh` itself.
   */
  #[Hook('cron')]
  public function cron(): void {
    if (!$this->refresher->isDue()) {
      return;
    }
    try {
      // Each source's outcome is recorded and logged by the refresher.
      $this->refresher->refresh();
    }
    catch (ReportException) {
      // Another refresh is running; the next cron run will try again.
    }
  }

}
