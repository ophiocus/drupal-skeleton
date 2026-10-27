<?php

declare(strict_types=1);

namespace Drupal\Tests\ad_inventory_report\Unit;

use Drupal\ad_inventory\Csv;
use Drupal\ad_inventory_report\Controller\DashboardController;
use Drupal\ad_inventory_report\DateRange;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * CSV safety, date ranges and sparklines.
 */
#[Group('ad_inventory')]
#[RunTestsInSeparateProcesses]
final class ReportLogicTest extends UnitTestCase {

  /**
   * Cells starting with = + - @ are prefixed with a quote.
   */
  public function testCsvFormulaInjection(): void {
    foreach (['=SUM(A1)', '+1', '-2+3', '@cmd', "\tx"] as $value) {
      $this->assertSame("'" . $value, Csv::cell($value));
    }
    $this->assertSame('plain', Csv::cell('plain'));
    $this->assertSame('-5', Csv::cell(-5), 'A number stays a number.');
    $csv = Csv::build(['name', 'views'], [['=HYPERLINK("x")', 3], ['Shop, "Ltd"', 0]]);
    $lines = explode("\n", trim($csv));
    $this->assertSame('name,views', $lines[0]);
    $this->assertSame('"\'=HYPERLINK(""x"")",3', $lines[1]);
    $this->assertSame('"Shop, ""Ltd""",0', $lines[2]);
  }

  /**
   * Each range and the one before it.
   */
  public function testRanges(): void {
    $today = '2026-10-26';
    $cases = [
      ['today', '2026-10-26', '2026-10-26', '2026-10-25', '2026-10-25'],
      ['yesterday', '2026-10-25', '2026-10-25', '2026-10-24', '2026-10-24'],
      ['7d', '2026-10-20', '2026-10-26', '2026-10-13', '2026-10-19'],
      ['28d', '2026-09-29', '2026-10-26', '2026-09-01', '2026-09-28'],
      ['month', '2026-10-01', '2026-10-26', '2026-09-01', '2026-09-30'],
      ['last_month', '2026-09-01', '2026-09-30', '2026-08-01', '2026-08-31'],
    ];
    foreach ($cases as [$key, $from, $to, $pfrom, $pto]) {
      $range = DateRange::fromQuery(['range' => $key], $today);
      $this->assertSame([$from, $to, $pfrom, $pto], [$range->from, $range->to, $range->previousFrom, $range->previousTo], $key);
    }
    $custom = DateRange::fromQuery(['range' => 'custom', 'from' => '2026-10-10', 'to' => '2026-10-01'], $today);
    $this->assertSame(['2026-10-01', '2026-10-10', '2026-09-21', '2026-09-30'], [
      $custom->from,
      $custom->to,
      $custom->previousFrom,
      $custom->previousTo,
    ]);
    $this->assertCount(10, $custom->days());
    $this->assertSame(['range' => 'custom', 'from' => '2026-10-01', 'to' => '2026-10-10'], $custom->query());
    $this->assertSame('28d', DateRange::fromQuery(['range' => 'custom', 'from' => 'nope'], $today)->key);
    $this->assertSame('28d', DateRange::fromQuery(['range' => 'forever'], $today)->key);
    $long = DateRange::fromQuery(['range' => 'custom', 'from' => '2020-01-01', 'to' => '2026-10-26'], $today);
    $this->assertCount(DateRange::MAX_DAYS, $long->days());
  }

  /**
   * Sparklines are plain SVG, with the numbers for screen readers.
   */
  public function testSparkline(): void {
    $svg = DashboardController::sparkline([0, 5, 10]);
    $this->assertStringContainsString('<svg class="air-spark"', $svg);
    $this->assertStringContainsString('aria-label="0, 5, 10"', $svg);
    $this->assertStringContainsString('points="0.0,19.0 50.0,10.0 100.0,1.0"', $svg);
    $this->assertSame('', DashboardController::sparkline([3]));
  }

}
