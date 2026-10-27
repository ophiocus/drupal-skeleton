<?php

declare(strict_types=1);

namespace Drupal\Tests\ad_inventory_report\Kernel;

use Drupal\ad_inventory_report\Rollup;
use Drupal\Tests\ad_inventory\Kernel\AdInventoryKernelTestBase;
use Drupal\user\Entity\Role;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The rollup equals the raw counts and is idempotent; the role comes and goes.
 */
#[Group('ad_inventory')]
#[RunTestsInSeparateProcesses]
final class RollupAndRoleTest extends AdInventoryKernelTestBase {

  /**
   * The rollup table holds the raw counts, split by source.
   */
  public function testRollup(): void {
    $this->container->get('module_installer')->install(['ad_inventory_report']);
    $this->slot('top');
    $this->slot('side', ['desktop_format' => 'rectangle', 'phone_format' => 'rectangle']);
    $booked = $this->creative(['leaderboard', 'mobile_banner'], ['label' => 'Booked']);
    $house = $this->creative(['leaderboard', 'mobile_banner', 'rectangle'], ['label' => 'House', 'house' => TRUE]);
    $booking = $this->booking($booked, ['top'], '2026-10-24', '2026-10-25');
    $counter = $this->container->get('ad_inventory.counter');
    $b = (string) $booked->id();
    $h = (string) $house->id();
    $fixture = [
      ['2026-10-24', 'top', $b, 'views', 10],
      ['2026-10-24', 'top', $b, 'clicks', 2],
      ['2026-10-25', 'top', $b, 'views', 7],
      // The same creative the day after its booking ended: not booked.
      ['2026-10-26', 'top', $b, 'views', 1],
      ['2026-10-24', 'side', $h, 'views', 5],
      ['2026-10-25', 'side', 'provider:google', 'views', 4],
      ['2026-10-25', 'side', 'provider:google', 'unfilled', 1],
      ['2026-10-26', '', $b, 'clicks', 1],
    ];
    foreach ($fixture as [$day, $slot, $creative, $column, $n]) {
      $counter->add($day, $slot, $creative, $column, $n);
    }

    $rollup = $this->container->get('ad_inventory_report.rollup');
    $this->assertSame(['2026-10-24', '2026-10-25', '2026-10-26'], $rollup->run());
    $first = $this->rows();
    $this->assertSame($this->rawTotals(), $this->rollupTotals());
    $this->assertSame([
      '2026-10-24|side|house|' . $h . '|0' => [5, 0, 0],
      '2026-10-24|top|booking|' . $b . '|' . $booking->id() => [10, 2, 0],
      '2026-10-25|side|network|provider:google|0' => [4, 0, 1],
      '2026-10-25|top|booking|' . $b . '|' . $booking->id() => [7, 0, 0],
      '2026-10-26|top|unknown|' . $b . '|0' => [1, 0, 0],
      '2026-10-26||unknown|' . $b . '|0' => [0, 1, 0],
    ], $first);

    // Twice changes nothing.
    $this->container->get('ad_inventory_report.rollup')->roll('2026-10-01', '2026-10-31');
    $this->assertSame($first, $this->rows());

    $data = $this->container->get('ad_inventory_report.data');
    $this->assertSame(['views' => 27, 'clicks' => 3, 'unfilled' => 1], $data->totals('2026-10-24', '2026-10-26'));
    $by_source = array_column($data->sum('2026-10-24', '2026-10-26', ['source']), 'views', 'source');
    ksort($by_source);
    $this->assertSame(['booking' => 17, 'house' => 5, 'network' => 4, 'unknown' => 1], $by_source);
    $this->assertSame(['' => [10, 7, 1]], $data->series(['2026-10-24', '2026-10-25', '2026-10-26'], '', 'views', ['slot' => 'top']));
  }

  /**
   * The role: exactly its permissions on install, gone on uninstall.
   */
  public function testRole(): void {
    // Uninstalling a module clears its user data.
    $this->installSchema('user', ['users_data']);
    $installer = $this->container->get('module_installer');
    $installer->install(['toolbar']);
    $installer->install(['ad_inventory_report']);
    $role = Role::load('ad_inventory_overwatch');
    $this->assertNotNull($role);
    $this->assertSame('Marketeer overwatch', $role->label());
    $permissions = $role->getPermissions();
    sort($permissions);
    $this->assertSame([
      'access toolbar',
      'export ad inventory report',
      'view ad inventory report',
      'view the administration theme',
    ], $permissions);
    $this->assertFalse($role->isAdmin());

    $installer->uninstall(['ad_inventory_report']);
    $this->assertNull(Role::load('ad_inventory_overwatch'));
  }

  /**
   * The rollup rows, keyed.
   */
  private function rows(): array {
    $rows = [];
    $result = $this->container->get('database')->select(Rollup::TABLE, 'd')->fields('d')->orderBy('day')->orderBy('slot')->orderBy('source')->execute();
    foreach ($result as $row) {
      $rows[implode('|', [$row->day, $row->slot, $row->source, $row->creative, $row->booking])] = [
        (int) $row->views,
        (int) $row->clicks,
        (int) $row->unfilled,
      ];
    }
    ksort($rows);
    return $rows;
  }

  /**
   * Sums of the raw table.
   */
  private function rawTotals(): array {
    return array_map('intval', (array) $this->container->get('database')->query('SELECT SUM([views]) AS v, SUM([clicks]) AS c, SUM([unfilled]) AS u FROM {ad_inventory_count}')->fetchAssoc());
  }

  /**
   * Sums of the rollup table.
   */
  private function rollupTotals(): array {
    return array_map('intval', (array) $this->container->get('database')->query('SELECT SUM([views]) AS v, SUM([clicks]) AS c, SUM([unfilled]) AS u FROM {ad_inventory_report_daily}')->fetchAssoc());
  }

}
