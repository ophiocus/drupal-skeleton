<?php

declare(strict_types=1);

namespace Drupal\ga4_report\Report;

/**
 * The reports one snapshot is made of, as GA4 Data API runReport requests.
 *
 * Each request is the body of one runReport call without its property; the
 * client sends them through batchRunReports. The set is fixed in this phase;
 * what varies per site comes from the module's settings: the dates, the
 * internal-traffic conditions, the kinds of page and the row limits.
 */
final class ReportDefinitions {

  /**
   * Builds every request a snapshot needs.
   *
   * @param array $settings
   *   The ga4_report.settings values.
   *
   * @return array<string, array>
   *   Request bodies keyed by report id. "kind:<id>" holds one kind of page.
   */
  public static function build(array $settings): array {
    $range = [
      'startDate' => (string) ($settings['start_date'] ?? '90daysAgo'),
      'endDate' => (string) ($settings['end_date'] ?? 'today'),
    ];
    $internal = self::internalExpression($settings['internal_traffic'] ?? []);
    $external = $internal ? ['notExpression' => $internal] : NULL;
    $limits = ($settings['limits'] ?? []) + ['pages' => 80, 'landing_pages' => 100];

    $requests = [];
    $requests['daily'] = self::request($range,
      ['date'],
      ['sessions', 'totalUsers', 'newUsers', 'screenPageViews', 'engagedSessions', 'averageSessionDuration'],
      ['orderBys' => [['dimension' => ['dimensionName' => 'date']]]],
    );
    if ($external) {
      $requests['daily_external'] = self::request($range,
        ['date'],
        ['sessions', 'totalUsers', 'screenPageViews'],
        ['dimensionFilter' => $external, 'orderBys' => [['dimension' => ['dimensionName' => 'date']]]],
      );
    }
    $requests['channels'] = self::request($range,
      ['sessionDefaultChannelGroup', 'sessionSource', 'deviceCategory'],
      ['sessions', 'engagedSessions', 'totalUsers'],
      ['limit' => 1000, 'orderBys' => [self::byMetric('sessions')]],
    );
    $requests['places'] = self::request($range,
      ['countryId', 'country', 'region', 'city', 'deviceCategory'],
      ['sessions', 'totalUsers', 'screenPageViews'],
      ['limit' => 2000, 'orderBys' => [self::byMetric('sessions')]],
    );
    if ($internal) {
      $requests['places_internal'] = self::request($range,
        ['countryId', 'city'],
        ['sessions'],
        ['limit' => 500, 'dimensionFilter' => $internal, 'orderBys' => [self::byMetric('sessions')]],
      );
    }
    $requests['tech'] = self::request($range,
      ['deviceCategory', 'operatingSystem', 'browser'],
      ['sessions', 'totalUsers'],
      ['limit' => 500, 'orderBys' => [self::byMetric('sessions')]],
    );
    $requests['pages'] = self::request($range,
      ['pagePath'],
      ['screenPageViews', 'totalUsers', 'userEngagementDuration'],
      ['limit' => max(1, (int) $limits['pages']), 'orderBys' => [self::byMetric('screenPageViews')]],
    );
    $requests['week_hours'] = self::request($range,
      ['dayOfWeek', 'hour'],
      ['sessions'],
      $external ? ['dimensionFilter' => $external] : [],
    );
    $requests['events'] = self::request($range,
      ['eventName'],
      ['eventCount', 'totalUsers'],
      ['orderBys' => [self::byMetric('eventCount')]],
    );
    $requests['audience'] = self::request($range,
      ['newVsReturning', 'language'],
      ['sessions', 'totalUsers'],
      ['limit' => 500, 'orderBys' => [self::byMetric('sessions')]],
    );
    $requests['landing'] = self::request($range,
      ['landingPage', 'sessionDefaultChannelGroup'],
      ['sessions', 'engagedSessions'],
      ['limit' => max(1, (int) $limits['landing_pages']), 'orderBys' => [self::byMetric('sessions')]],
    );
    // Visits minute by minute over the last two days, for the edge-cache card.
    // Google reports minutes in the property's time zone; the normaliser
    // moves them to UTC with the time zone the response names.
    $requests['minutes'] = self::request(['startDate' => 'yesterday', 'endDate' => 'today'],
      ['dateHourMinute'],
      ['sessions', 'screenPageViews', 'activeUsers'],
      ['limit' => 3000, 'orderBys' => [['dimension' => ['dimensionName' => 'dateHourMinute']]]],
    );
    foreach ($settings['page_kinds'] ?? [] as $kind) {
      $requests['kind:' . $kind['id']] = self::request($range,
        [],
        ['screenPageViews', 'totalUsers'],
        ['dimensionFilter' => self::pathFilter((string) $kind['pattern'])],
      );
    }
    return $requests;
  }

  /**
   * The filter expression matching internal traffic, or NULL when none is set.
   *
   * @param array $conditions
   *   Each a dimension and the values it may take; a visit must match all.
   */
  public static function internalExpression(array $conditions): ?array {
    $filters = [];
    foreach ($conditions as $condition) {
      $values = array_values(array_filter(array_map('strval', $condition['values'] ?? []), 'strlen'));
      if (empty($condition['dimension']) || !$values) {
        continue;
      }
      $filters[] = [
        'filter' => [
          'fieldName' => (string) $condition['dimension'],
          'inListFilter' => ['values' => $values, 'caseSensitive' => FALSE],
        ],
      ];
    }
    if (!$filters) {
      return NULL;
    }
    return count($filters) === 1 ? $filters[0] : ['andGroup' => ['expressions' => $filters]];
  }

  /**
   * A filter keeping the pages whose whole path matches a regular expression.
   */
  public static function pathFilter(string $pattern): array {
    return [
      'filter' => [
        'fieldName' => 'pagePath',
        'stringFilter' => ['matchType' => 'FULL_REGEXP', 'value' => $pattern],
      ],
    ];
  }

  /**
   * One runReport request body.
   */
  private static function request(array $range, array $dimensions, array $metrics, array $extra): array {
    $request = ['dateRanges' => [$range]];
    if ($dimensions) {
      $request['dimensions'] = array_map(static fn(string $name): array => ['name' => $name], $dimensions);
    }
    $request['metrics'] = array_map(static fn(string $name): array => ['name' => $name], $metrics);
    return $request + $extra + ['limit' => 10000];
  }

  /**
   * Descending order by one metric.
   */
  private static function byMetric(string $metric): array {
    return ['metric' => ['metricName' => $metric], 'desc' => TRUE];
  }

}
