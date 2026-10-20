<?php

declare(strict_types=1);

namespace Drupal\Tests\ga4_report\Traits;

/**
 * A small invented site's settings and the reports Google would send for it.
 *
 * Thirty visits over two days, a third of them from search; internal traffic
 * is a desktop in Springfield.
 */
trait ReportFixtureTrait {

  /**
   * The dimensions of the channels report.
   */
  protected const CHANNEL_DIMENSIONS = ['sessionDefaultChannelGroup', 'sessionSource', 'deviceCategory'];

  /**
   * Settings with every list filled.
   */
  protected static function fixtureSettings(): array {
    return [
      'property_id' => '123456789',
      'start_date' => '2026-01-01',
      'end_date' => 'today',
      'refresh_interval' => 24,
      'internal_traffic' => [
        ['dimension' => 'city', 'values' => ['Springfield']],
        ['dimension' => 'deviceCategory', 'values' => ['desktop']],
      ],
      'page_kinds' => [
        ['id' => 'article', 'label' => 'Articles', 'pattern' => '/(en/)?articles/.+'],
        ['id' => 'home', 'label' => 'Home', 'pattern' => '/(en/?)?'],
      ],
      'markers' => [['date' => '2026-01-02', 'label' => 'Launch']],
      'limits' => ['pages' => 80, 'landing_pages' => 100],
    ];
  }

  /**
   * The reports for fixtureSettings(), keyed like ReportDefinitions::build().
   */
  protected static function fixtureReports(): array {
    $daily = ['sessions', 'totalUsers', 'newUsers', 'screenPageViews', 'engagedSessions', 'averageSessionDuration'];
    $places = ['countryId', 'country', 'region', 'city', 'deviceCategory'];
    $minute = ['sessions', 'screenPageViews', 'activeUsers'];
    return [
      'daily' => self::gaReport(['date'], $daily, [
        ['20260102', 20, 15, 10, 50, 12, 40],
        ['20260101', 10, 8, 6, 30, 7, 61.54],
      ]),
      'daily_external' => self::gaReport(['date'], ['sessions', 'totalUsers', 'screenPageViews'], [
        ['20260101', 6, 5, 20],
        ['20260102', 18, 14, 45],
      ]),
      'channels' => self::gaReport(self::CHANNEL_DIMENSIONS, ['sessions', 'engagedSessions', 'totalUsers'], [
        ['Organic Search', 'google', 'mobile', 12, 9, 10],
        ['Direct', '(direct)', 'desktop', 10, 5, 8],
        ['Referral', 'example.org', 'mobile', 8, 4, 7],
      ]),
      'places' => self::gaReport($places, ['sessions', 'totalUsers', 'screenPageViews'], [
        ['CO', 'Colombia', 'Bogota', 'Bogota', 'mobile', 15, 12, 40],
        ['US', 'United States', 'Illinois', 'Springfield', 'desktop', 8, 6, 20],
        ['US', 'United States', 'Illinois', 'Springfield', 'mobile', 4, 3, 10],
        ['US', 'United States', '(not set)', '(not set)', 'mobile', 3, 3, 5],
        ['XA', 'Nowhere', 'Sea', 'Atlantis', 'mobile', 2, 2, 2],
      ]),
      'places_internal' => self::gaReport(['countryId', 'city'], ['sessions'], [
        ['US', 'Springfield', 6],
      ]),
      'tech' => self::gaReport(['deviceCategory', 'operatingSystem', 'browser'], ['sessions', 'totalUsers'], [
        ['mobile', 'Android', 'Chrome', 20, 15],
        ['desktop', 'Windows', 'Edge', 10, 8],
      ]),
      'pages' => self::gaReport(['pagePath'], ['screenPageViews', 'totalUsers', 'userEngagementDuration'], [
        ['/articles/one', 25, 10, 300.4],
        ['/', 20, 15, 100],
        ['/en/articles/two', 10, 5, 50],
        ['/about', 5, 4, 20],
      ]),
      'week_hours' => self::gaReport(['dayOfWeek', 'hour'], ['sessions'], [
        ['0', '09', 3],
        ['1', '23', 5],
      ]),
      'events' => self::gaReport(['eventName'], ['eventCount', 'totalUsers'], [
        ['page_view', 80, 25],
        ['session_start', 30, 25],
      ]),
      'audience' => self::gaReport(['newVsReturning', 'language'], ['sessions', 'totalUsers'], [
        ['new', 'Spanish', 18, 15],
        ['returning', 'Spanish', 6, 4],
        ['new', 'English', 6, 6],
      ]),
      'landing' => self::gaReport(['landingPage', 'sessionDefaultChannelGroup'], ['sessions', 'engagedSessions'], [
        ['/articles/one?utm_source=x', 'Organic Search', 12, 9],
        ['/', 'Direct', 10, 5],
        ['/about', 'Referral', 8, 4],
      ]),
      'minutes' => ['metadata' => ['timeZone' => 'America/Bogota']] + self::gaReport(['dateHourMinute'], $minute, [
        ['202601011955', 1, 2, 1],
        ['202601011930', 2, 3, 2],
      ]),
      'kind:article' => self::gaReport([], ['screenPageViews', 'totalUsers'], [[35, 14]]),
      'kind:home' => self::gaReport([], ['screenPageViews', 'totalUsers'], [[20, 15]]),
    ];
  }

  /**
   * Cloudflare's GraphQL answer for two minutes and two days.
   */
  protected static function fixtureCloudflare(): array {
    $minute = static fn(string $at, string $status, int $count): array => [
      'count' => $count,
      'dimensions' => ['datetimeMinute' => $at, 'cacheStatus' => $status],
    ];
    $day = static fn(string $date, int $requests, int $cached): array => [
      'dimensions' => ['date' => $date],
      'sum' => [
        'requests' => $requests,
        'cachedRequests' => $cached,
        'bytes' => 2000000,
        'cachedBytes' => 1000000,
        'pageViews' => 40,
      ],
      'uniq' => ['uniques' => 12],
    ];
    return [
      'viewer' => [
        'zones' => [
          [
            'minutes' => [
              $minute('2026-01-02T00:00:00Z', 'hit', 6),
              $minute('2026-01-02T00:00:00Z', 'miss', 3),
              $minute('2026-01-02T00:00:00Z', 'dynamic', 1),
              $minute('2026-01-02T00:00:00Z', 'none', 5),
              $minute('2026-01-02T00:01:00Z', 'expired', 2),
              $minute('2026-01-03T00:00:00Z', 'hit', 99),
            ],
            'days' => [$day('2026-01-01', 100, 25), $day('2026-01-02', 200, 100)],
          ],
        ],
      ],
    ];
  }

  /**
   * AdSense's answer to the daily report, rows out of order.
   */
  protected static function fixtureAdSense(): array {
    $cells = static fn(array $values): array => ['cells' => array_map(static fn($v): array => ['value' => (string) $v], $values)];
    return [
      'headers' => [
        ['name' => 'DATE', 'type' => 'DIMENSION'],
        ['name' => 'ESTIMATED_EARNINGS', 'type' => 'METRIC_CURRENCY', 'currencyCode' => 'COP'],
        ['name' => 'PAGE_VIEWS', 'type' => 'METRIC_TALLY'],
        ['name' => 'PAGE_VIEWS_RPM', 'type' => 'METRIC_CURRENCY', 'currencyCode' => 'COP'],
        ['name' => 'IMPRESSIONS', 'type' => 'METRIC_TALLY'],
        ['name' => 'CLICKS', 'type' => 'METRIC_TALLY'],
      ],
      'rows' => [
        $cells(['2026-01-02', '3000.50', 200, '15002.5', 600, 3]),
        $cells(['2026-01-01', '1000', 100, '10000', 300, 1]),
      ],
    ];
  }

  /**
   * A city table holding Bogota and Springfield (Illinois).
   */
  protected static function fixtureCityTable(): string {
    $path = tempnam(sys_get_temp_dir(), 'ga4r');
    file_put_contents($path, json_encode([
      'CO' => ['bogota' => [4.61, -74.08]],
      'US' => ['springfield' => [39.8, -89.64]],
    ]));
    return $path;
  }

  /**
   * One report in the Data API's response shape; values become strings.
   */
  protected static function gaReport(array $dimensions, array $metrics, array $rows): array {
    $n = count($dimensions);
    return [
      'dimensionHeaders' => array_map(static fn(string $d): array => ['name' => $d], $dimensions),
      'metricHeaders' => array_map(static fn(string $m): array => ['name' => $m, 'type' => 'TYPE_INTEGER'], $metrics),
      'rows' => array_map(static fn(array $r): array => [
        'dimensionValues' => array_map(static fn($v): array => ['value' => (string) $v], array_slice($r, 0, $n)),
        'metricValues' => array_map(static fn($v): array => ['value' => (string) $v], array_slice($r, $n)),
      ], $rows),
      'rowCount' => count($rows),
      'kind' => 'analyticsData#runReport',
    ];
  }

}
