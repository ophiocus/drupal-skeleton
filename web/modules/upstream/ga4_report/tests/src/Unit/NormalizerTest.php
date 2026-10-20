<?php

declare(strict_types=1);

namespace Drupal\Tests\ga4_report\Unit;

use Drupal\ga4_report\Report\CityLocator;
use Drupal\ga4_report\Report\Normalizer;
use Drupal\Tests\ga4_report\Traits\ReportFixtureTrait;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * From Google's reports to the snapshot the page draws.
 */
#[Group('ga4_report')]
final class NormalizerTest extends UnitTestCase {

  use ReportFixtureTrait;

  /**
   * The snapshot of the fixture site.
   */
  private array $snapshot;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $table = self::fixtureCityTable();
    $normalizer = new Normalizer(new CityLocator($table));
    $this->snapshot = $normalizer->normalize(self::fixtureReports(), self::fixtureSettings());
    unlink($table);
  }

  /**
   * The headline numbers.
   */
  public function testTotals(): void {
    $this->assertSame([
      'sessions' => 30,
      'externalSessions' => 24,
      'views' => 80,
      'pageViewUsers' => 25,
      'searchShare' => 0.4,
      'internalShare' => 0.2,
    ], $this->snapshot['totals']);
    $this->assertTrue($this->snapshot['internal']);
    $this->assertSame(Normalizer::VERSION, $this->snapshot['version']);
  }

  /**
   * Days in order, ISO dates, the external part beside each.
   */
  public function testDaily(): void {
    $this->assertSame(['2026-01-01', '2026-01-02'], array_column($this->snapshot['daily'], 'date'));
    $this->assertSame(6, $this->snapshot['daily'][0]['externalSessions']);
    $this->assertSame(61.5, $this->snapshot['daily'][0]['duration']);
  }

  /**
   * Cities: devices summed, internal visits beside, positions or none.
   */
  public function testCities(): void {
    $cities = array_column($this->snapshot['cities'], NULL, 'city');
    $this->assertSame(['Bogota', 'Springfield', 'Atlantis'], array_keys($cities));
    $this->assertSame(12, $cities['Springfield']['sessions']);
    $this->assertSame(6, $cities['Springfield']['internalSessions']);
    $this->assertSame(39.8, $cities['Springfield']['lat']);
    $this->assertNull($cities['Atlantis']['lat']);
    $this->assertSame(['country' => 'United States', 'sessions' => 15], $this->snapshot['countries'][1]);
  }

  /**
   * Pages and first pages take the first kind whose pattern matches.
   */
  public function testKinds(): void {
    $this->assertSame(['article', 'home', 'article', 'other'], array_column($this->snapshot['pages'], 'kind'));
    $this->assertSame(['article', 'home', 'other'], array_column($this->snapshot['landing'], 'kind'));
    $this->assertSame(['id' => 'article', 'label' => 'Articles', 'views' => 35, 'users' => 14], $this->snapshot['kinds'][0]);
    $this->assertSame(300, $this->snapshot['pages'][0]['engagement']);
  }

  /**
   * The weekday grid has every cell; missing ones are zero.
   */
  public function testWeekHours(): void {
    $this->assertCount(168, $this->snapshot['weekHours']);
    $this->assertSame([0, 9, 3], $this->snapshot['weekHours'][9]);
    $this->assertSame([1, 23, 5], $this->snapshot['weekHours'][47]);
    $this->assertSame([0, 0, 0], $this->snapshot['weekHours'][0]);
  }

  /**
   * The flow joins channel to source to device, and folds small sources.
   */
  public function testFlow(): void {
    $this->assertContains(['source' => 'c:Organic Search', 'target' => 's:google', 'value' => 12], $this->snapshot['flow']);
    $this->assertContains(['source' => 's:google', 'target' => 'd:mobile', 'value' => 12], $this->snapshot['flow']);

    $rows = [];
    foreach (range(1, 15) as $n) {
      $rows[] = ['Referral', "site$n.example", 'mobile', 100 - $n, 1, 1];
    }
    $reports = [
      'channels' => self::gaReport(self::CHANNEL_DIMENSIONS, ['sessions', 'engagedSessions', 'totalUsers'], $rows),
    ];
    $snapshot = (new Normalizer(new CityLocator('/nonexistent')))->normalize($reports, ['internal_traffic' => []]);
    $sources = array_unique(array_column(array_filter($snapshot['flow'], static fn(array $l): bool => str_starts_with($l['source'], 'c:')), 'target'));
    $this->assertCount(13, $sources);
    $this->assertContains('s:Other sources', $sources);
    $this->assertFalse($snapshot['internal']);
    $this->assertNull($snapshot['totals']['externalSessions']);
  }

  /**
   * Minutes move from the property's time zone to UTC, in order.
   */
  public function testMinutes(): void {
    $this->assertSame([
      ['2026-01-02T00:30', 2, 3, 2],
      ['2026-01-02T00:55', 1, 2, 1],
    ], $this->snapshot['minutes']);
  }

  /**
   * A report without rows is an empty list, not an error.
   */
  public function testEmptyReports(): void {
    $snapshot = (new Normalizer(new CityLocator('/nonexistent')))->normalize([], ['internal_traffic' => []]);
    $this->assertSame(0, $snapshot['totals']['sessions']);
    $this->assertNull($snapshot['totals']['searchShare']);
    $this->assertSame([], $snapshot['cities']);
  }

}
