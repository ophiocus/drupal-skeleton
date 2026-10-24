<?php

declare(strict_types=1);

namespace Drupal\Tests\ga4_report\Unit;

use Drupal\ga4_report\Report\Dates;
use Drupal\ga4_report\ReportException;
use Drupal\ga4_report\Source\AdSenseSource;
use Drupal\ga4_report\Source\CloudflareSource;
use Drupal\Tests\ga4_report\Traits\ReportFixtureTrait;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Cloudflare's and AdSense's answers, and the dates they are asked for.
 */
#[Group('ga4_report')]
final class EdgeAndAdsTest extends UnitTestCase {

  use ReportFixtureTrait;

  /**
   * The moment the tests run at: 2026-01-02 00:05 UTC.
   */
  private const NOW = 1767312300;

  /**
   * Settings' dates as calendar days in the site's time zone.
   */
  public function testDates(): void {
    $this->assertSame('2026-01-02', Dates::day('today', self::NOW, 'UTC'));
    // Five past midnight in UTC is still the evening before in Bogotá.
    $this->assertSame('2026-01-01', Dates::day('today', self::NOW, 'America/Bogota'));
    $this->assertSame('2026-01-01', Dates::day('yesterday', self::NOW, 'UTC'));
    $this->assertSame('2025-12-03', Dates::day('30daysAgo', self::NOW, 'UTC'));
    $this->assertSame('2025-06-30', Dates::day('2025-06-30', self::NOW, 'UTC'));
  }

  /**
   * Days are the report's dates, never more than the last 30.
   */
  public function testCloudflareDays(): void {
    $days = static fn(string $first, string $last): array => CloudflareSource::days(
      ['start_date' => $first, 'end_date' => $last],
      self::NOW,
      'UTC',
    );
    $this->assertSame(['2025-12-04', '2026-01-02'], $days('90daysAgo', 'today'));
    $this->assertSame(['2025-12-30', '2026-01-01'], $days('2025-12-30', 'yesterday'));
  }

  /**
   * The query names the zone and the window, and both datasets.
   */
  public function testCloudflareQuery(): void {
    $query = CloudflareSource::query(str_repeat('a', 32), 1767225600, 1767312000, '2025-12-04', '2026-01-02');
    $this->assertStringContainsString('zoneTag: "' . str_repeat('a', 32) . '"', $query);
    $this->assertStringContainsString('datetime_geq: "2026-01-01T00:00:00Z", datetime_lt: "2026-01-02T00:00:00Z"', $query);
    $this->assertStringContainsString('httpRequests1dGroups', $query);
    // Readers' minutes leave the cache warmer out; the warmer is asked apart.
    $this->assertStringContainsString('userAgent_neq: "webrunners-cache-warm/1"', $query);
    $this->assertStringContainsString('warm: httpRequestsAdaptiveGroups', $query);
    $this->assertStringContainsString('userAgent: "webrunners-cache-warm/1"', $query);
  }

  /**
   * The cache warmer is totalled apart and leaves readers' figures alone.
   */
  public function testCloudflareWarmerApart(): void {
    $from = 1767312000;
    $data = self::fixtureCloudflare();
    $data['viewer']['zones'][0]['warm'] = [
      ['count' => 30, 'dimensions' => ['cacheStatus' => 'hit']],
      ['count' => 60, 'dimensions' => ['cacheStatus' => 'miss']],
      ['count' => 10, 'dimensions' => ['cacheStatus' => 'expired']],
      ['count' => 7, 'dimensions' => ['cacheStatus' => 'none']],
    ];
    $snapshot = CloudflareSource::normalize($data, $from, $from + 180);
    $this->assertSame(100, $snapshot['totals']['warmRequests']);
    $this->assertSame(0.3, $snapshot['totals']['warmHitRate']);
    $this->assertSame(12, $snapshot['totals']['minuteRequests']);
    $this->assertSame(0.5, $snapshot['totals']['minuteHitRate']);

    $none = CloudflareSource::normalize(self::fixtureCloudflare(), $from, $from + 180);
    $this->assertSame(0, $none['totals']['warmRequests']);
    $this->assertNull($none['totals']['warmHitRate']);
  }

  /**
   * Minutes: every one of the window, statuses grouped, beacons apart.
   */
  public function testCloudflareNormalize(): void {
    $from = 1767312000;
    $snapshot = CloudflareSource::normalize(self::fixtureCloudflare(), $from, $from + 180);
    $this->assertSame([
      ['2026-01-02T00:00', 6, 3, 1, 5],
      ['2026-01-02T00:01', 0, 2, 0, 0],
      ['2026-01-02T00:02', 0, 0, 0, 0],
    ], $snapshot['minutes']);
    $this->assertSame(12, $snapshot['totals']['minuteRequests']);
    $this->assertSame(0.5, $snapshot['totals']['minuteHitRate']);
    $this->assertSame(['2026-01-02', 200, 100, 2000000, 1000000, 40, 12], $snapshot['days'][1]);
    $this->assertSame(125 / 300, $snapshot['totals']['dayHitRate']);

    $empty = CloudflareSource::normalize([], $from, $from + 60);
    $this->assertNull($empty['totals']['minuteHitRate']);
  }

  /**
   * Every way of writing a publisher ID names one account.
   */
  public function testAdSenseAccount(): void {
    foreach (['pub-1234567890', 'ca-pub-1234567890', 'accounts/pub-1234567890'] as $written) {
      $this->assertSame('accounts/pub-1234567890', AdSenseSource::account($written));
    }
    $this->expectException(ReportException::class);
    AdSenseSource::account('G-ABC');
  }

  /**
   * The daily report's query repeats dimensions and metrics.
   */
  public function testAdSenseParams(): void {
    $params = AdSenseSource::params('2025-12-04', '2026-01-02');
    $this->assertSame('CUSTOM', $params['dateRange']);
    $this->assertSame('2025', $params['startDate.year']);
    $this->assertSame('12', $params['startDate.month']);
    $this->assertSame('4', $params['startDate.day']);
    $this->assertSame('2', $params['endDate.day']);
    $this->assertSame(AdSenseSource::METRICS, $params['metrics']);
  }

  /**
   * Days in order, money rounded, totals and RPM computed.
   */
  public function testAdSenseNormalize(): void {
    $snapshot = AdSenseSource::normalize(self::fixtureAdSense());
    $this->assertSame('COP', $snapshot['currency']);
    $this->assertSame(['2026-01-01', 1000.0, 100, 10000.0, 300, 1], $snapshot['days'][0]);
    $this->assertSame(['2026-01-02', 3000.5, 200, 15002.5, 600, 3], $snapshot['days'][1]);
    $this->assertSame(4000.5, $snapshot['totals']['earnings']);
    $this->assertSame(13335.0, $snapshot['totals']['rpm']);
    $this->assertSame(900, $snapshot['totals']['impressions']);
  }

}
