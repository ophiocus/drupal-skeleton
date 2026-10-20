<?php

declare(strict_types=1);

namespace Drupal\Tests\ga4_report\Unit;

use Drupal\ga4_report\Report\ReportDefinitions;
use Drupal\Tests\ga4_report\Traits\ReportFixtureTrait;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * The requests a snapshot is made of.
 */
#[Group('ga4_report')]
final class ReportDefinitionsTest extends UnitTestCase {

  use ReportFixtureTrait;

  /**
   * Without internal traffic there is nothing to subtract and nothing filtered.
   */
  public function testWithoutInternalTraffic(): void {
    $settings = ['internal_traffic' => [], 'page_kinds' => []] + self::fixtureSettings();
    $requests = ReportDefinitions::build($settings);
    $this->assertSame(
      ['daily', 'channels', 'places', 'tech', 'pages', 'week_hours', 'events', 'audience', 'landing', 'minutes'],
      array_keys($requests),
    );
    $this->assertArrayNotHasKey('dimensionFilter', $requests['week_hours']);
    $this->assertSame([['startDate' => '2026-01-01', 'endDate' => 'today']], $requests['daily']['dateRanges']);
  }

  /**
   * Internal traffic: all conditions at once, left out or isolated.
   */
  public function testInternalTraffic(): void {
    $requests = ReportDefinitions::build(self::fixtureSettings());
    $in = static fn(string $field, string $value): array => [
      'filter' => [
        'fieldName' => $field,
        'inListFilter' => ['values' => [$value], 'caseSensitive' => FALSE],
      ],
    ];
    $internal = [
      'andGroup' => [
        'expressions' => [$in('city', 'Springfield'), $in('deviceCategory', 'desktop')],
      ],
    ];
    $this->assertSame(['notExpression' => $internal], $requests['daily_external']['dimensionFilter']);
    $this->assertSame(['notExpression' => $internal], $requests['week_hours']['dimensionFilter']);
    $this->assertSame($internal, $requests['places_internal']['dimensionFilter']);
    $this->assertArrayNotHasKey('dimensionFilter', $requests['daily']);
  }

  /**
   * One condition needs no group; an empty one is ignored.
   */
  public function testSingleCondition(): void {
    $expression = ReportDefinitions::internalExpression([
      ['dimension' => 'city', 'values' => ['Springfield']],
      ['dimension' => 'browser', 'values' => []],
    ]);
    $this->assertSame('city', $expression['filter']['fieldName']);
    $this->assertNull(ReportDefinitions::internalExpression([]));
  }

  /**
   * Every kind of page is one filtered report; the limits reach their reports.
   */
  public function testKindsAndLimits(): void {
    $settings = ['limits' => ['pages' => 7, 'landing_pages' => 9]] + self::fixtureSettings();
    $requests = ReportDefinitions::build($settings);
    $this->assertSame(
      ['fieldName' => 'pagePath', 'stringFilter' => ['matchType' => 'FULL_REGEXP', 'value' => '/(en/)?articles/.+']],
      $requests['kind:article']['dimensionFilter']['filter'],
    );
    $this->assertArrayNotHasKey('dimensions', $requests['kind:home']);
    $this->assertSame(7, $requests['pages']['limit']);
    $this->assertSame(9, $requests['landing']['limit']);
    $this->assertSame(10000, $requests['events']['limit']);
  }

}
