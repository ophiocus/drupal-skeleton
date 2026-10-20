<?php

declare(strict_types=1);

namespace Drupal\ga4_report\Report;

/**
 * Turns the GA4 reports of one refresh into the snapshot the page draws.
 *
 * The snapshot is plain data (arrays of scalars), small enough to store whole
 * and to hand to the page as JSON. Everything the charts need is computed
 * here, once per refresh, so a page view does no work beyond rendering.
 */
final class Normalizer {

  /**
   * The snapshot format; raise it when its shape changes.
   */
  public const VERSION = 1;

  /**
   * Sankey sources beyond this many are folded into "Other sources".
   */
  private const SOURCES = 12;

  public function __construct(
    private readonly CityLocator $cities,
  ) {}

  /**
   * Builds the snapshot.
   *
   * @param array<string, array> $reports
   *   GA4 report responses keyed by the ids ReportDefinitions::build() used.
   * @param array $settings
   *   The ga4_report.settings values the requests were built from.
   */
  public function normalize(array $reports, array $settings): array {
    $kinds = new PageKinds($settings['page_kinds'] ?? []);
    $internal = ReportDefinitions::internalExpression($settings['internal_traffic'] ?? []) !== NULL;

    $daily = $this->daily($reports['daily'] ?? [], $internal ? ($reports['daily_external'] ?? []) : NULL);
    $channels = array_map(static fn(array $r): array => [
      'channel' => $r['sessionDefaultChannelGroup'],
      'source' => $r['sessionSource'],
      'device' => $r['deviceCategory'],
      'sessions' => (int) $r['sessions'],
      'engaged' => (int) $r['engagedSessions'],
      'users' => (int) $r['totalUsers'],
    ], self::rows($reports['channels'] ?? []));
    $places = self::rows($reports['places'] ?? []);
    $placesInternal = self::rows($reports['places_internal'] ?? []);
    $pages = array_map(static fn(array $r): array => [
      'path' => $r['pagePath'],
      'views' => (int) $r['screenPageViews'],
      'users' => (int) $r['totalUsers'],
      'engagement' => (int) round((float) $r['userEngagementDuration']),
      'kind' => $kinds->classify($r['pagePath']),
    ], self::rows($reports['pages'] ?? []));
    $events = array_map(static fn(array $r): array => [
      'event' => $r['eventName'],
      'count' => (int) $r['eventCount'],
      'users' => (int) $r['totalUsers'],
    ], self::rows($reports['events'] ?? []));

    $kindTotals = [];
    foreach ($settings['page_kinds'] ?? [] as $kind) {
      $row = self::rows($reports['kind:' . $kind['id']] ?? [])[0] ?? NULL;
      $kindTotals[] = [
        'id' => (string) $kind['id'],
        'label' => (string) $kind['label'],
        'views' => (int) ($row['screenPageViews'] ?? 0),
        'users' => (int) ($row['totalUsers'] ?? 0),
      ];
    }

    $sessions = array_sum(array_column($daily, 'sessions'));
    $external = $internal ? array_sum(array_column($daily, 'externalSessions')) : NULL;
    $channelSessions = array_sum(array_column($channels, 'sessions'));
    $search = array_sum(array_column(array_filter($channels, static fn(array $c): bool => $c['channel'] === 'Organic Search'), 'sessions'));
    $pageView = current(array_filter($events, static fn(array $e): bool => $e['event'] === 'page_view')) ?: NULL;

    return [
      'version' => self::VERSION,
      'internal' => $internal,
      'totals' => [
        'sessions' => $sessions,
        'externalSessions' => $external,
        'views' => array_sum(array_column($daily, 'views')),
        'pageViewUsers' => $pageView['users'] ?? NULL,
        'searchShare' => $channelSessions ? $search / $channelSessions : NULL,
        'internalShare' => ($internal && $sessions) ? ($sessions - $external) / $sessions : NULL,
      ],
      'daily' => $daily,
      'channels' => $channels,
      'flow' => $this->flow($channels),
      'cities' => $this->cities($places, $placesInternal),
      'countries' => self::countries($places),
      'tech' => array_map(static fn(array $r): array => [
        'device' => $r['deviceCategory'],
        'os' => $r['operatingSystem'],
        'browser' => $r['browser'],
        'sessions' => (int) $r['sessions'],
        'users' => (int) $r['totalUsers'],
      ], self::rows($reports['tech'] ?? [])),
      'pages' => $pages,
      'weekHours' => self::weekHours(self::rows($reports['week_hours'] ?? [])),
      'events' => $events,
      'audience' => array_map(static fn(array $r): array => [
        'type' => $r['newVsReturning'],
        'language' => $r['language'],
        'sessions' => (int) $r['sessions'],
        'users' => (int) $r['totalUsers'],
      ], self::rows($reports['audience'] ?? [])),
      'landing' => array_map(static fn(array $r): array => [
        'page' => $r['landingPage'],
        'channel' => $r['sessionDefaultChannelGroup'],
        'sessions' => (int) $r['sessions'],
        'engaged' => (int) $r['engagedSessions'],
        'kind' => $kinds->classify(self::pathOf($r['landingPage'])),
      ], self::rows($reports['landing'] ?? [])),
      'kinds' => $kindTotals,
      'minutes' => self::minutes($reports['minutes'] ?? []),
    ];
  }

  /**
   * Visits per minute, keyed by UTC minute ("2026-10-20T14:05").
   *
   * Analytics names minutes in the property's time zone, which the response
   * carries; Cloudflare names them in UTC. Only minutes with a visit or a
   * view are listed.
   *
   * @return list<array{0: string, 1: int, 2: int, 3: int}>
   *   UTC minute, visits, page views, users.
   */
  private static function minutes(array $report): array {
    try {
      $zone = new \DateTimeZone((string) ($report['metadata']['timeZone'] ?? 'UTC'));
    }
    catch (\Exception) {
      $zone = new \DateTimeZone('UTC');
    }
    $utc = new \DateTimeZone('UTC');
    $out = [];
    foreach (self::rows($report) as $r) {
      $at = \DateTimeImmutable::createFromFormat('YmdHi', $r['dateHourMinute'], $zone);
      if ($at === FALSE) {
        continue;
      }
      $out[] = [
        $at->setTimezone($utc)->format('Y-m-d\TH:i'),
        (int) $r['sessions'],
        (int) $r['screenPageViews'],
        (int) $r['activeUsers'],
      ];
    }
    usort($out, static fn(array $a, array $b): int => strcmp($a[0], $b[0]));
    return $out;
  }

  /**
   * The rows of one report as associative arrays keyed by header name.
   *
   * @return list<array<string, string>>
   *   Dimension and metric values as Google sent them (strings).
   */
  public static function rows(array $report): array {
    $names = array_merge(
      array_column($report['dimensionHeaders'] ?? [], 'name'),
      array_column($report['metricHeaders'] ?? [], 'name'),
    );
    $rows = [];
    foreach ($report['rows'] ?? [] as $row) {
      $values = array_merge(
        array_column($row['dimensionValues'] ?? [], 'value'),
        array_column($row['metricValues'] ?? [], 'value'),
      );
      if (count($values) === count($names)) {
        $rows[] = array_combine($names, array_map('strval', $values));
      }
    }
    return $rows;
  }

  /**
   * Visits per day, with the external share beside each day when known.
   */
  private function daily(array $all, ?array $external): array {
    $byDate = [];
    foreach (self::rows($all) as $r) {
      $date = self::isoDate($r['date']);
      $byDate[$date] = [
        'date' => $date,
        'sessions' => (int) $r['sessions'],
        'users' => (int) $r['totalUsers'],
        'newUsers' => (int) $r['newUsers'],
        'views' => (int) $r['screenPageViews'],
        'engaged' => (int) $r['engagedSessions'],
        'duration' => round((float) $r['averageSessionDuration'], 1),
      ];
      if ($external !== NULL) {
        $byDate[$date] += ['externalSessions' => 0, 'externalUsers' => 0, 'externalViews' => 0];
      }
    }
    foreach ($external === NULL ? [] : self::rows($external) as $r) {
      $date = self::isoDate($r['date']);
      if (isset($byDate[$date])) {
        $byDate[$date]['externalSessions'] = (int) $r['sessions'];
        $byDate[$date]['externalUsers'] = (int) $r['totalUsers'];
        $byDate[$date]['externalViews'] = (int) $r['screenPageViews'];
      }
    }
    ksort($byDate);
    return array_values($byDate);
  }

  /**
   * Channel → source → device flows for the Sankey, small sources folded.
   */
  private function flow(array $channels): array {
    $bySource = [];
    foreach ($channels as $c) {
      $bySource[$c['source']] = ($bySource[$c['source']] ?? 0) + $c['sessions'];
    }
    arsort($bySource);
    $kept = array_slice(array_keys($bySource), 0, self::SOURCES);
    $links = [];
    foreach ($channels as $c) {
      $source = in_array($c['source'], $kept, TRUE) ? $c['source'] : 'Other sources';
      $a = "c:{$c['channel']}|s:$source";
      $b = "s:$source|d:{$c['device']}";
      $links[$a] = ($links[$a] ?? 0) + $c['sessions'];
      $links[$b] = ($links[$b] ?? 0) + $c['sessions'];
    }
    $out = [];
    foreach ($links as $key => $value) {
      [$source, $target] = explode('|', $key, 2);
      if ($value > 0) {
        $out[] = ['source' => $source, 'target' => $target, 'value' => $value];
      }
    }
    return $out;
  }

  /**
   * Visits per city with their position, internal visits beside them.
   */
  private function cities(array $places, array $internal): array {
    $cities = [];
    foreach ($places as $r) {
      if ($r['city'] === '(not set)' || $r['city'] === '') {
        continue;
      }
      $key = $r['countryId'] . '|' . $r['city'];
      $cities[$key] ??= [
        'city' => $r['city'],
        'region' => $r['region'],
        'country' => $r['country'],
        'countryId' => $r['countryId'],
        'sessions' => 0,
        'internalSessions' => 0,
        'views' => 0,
      ];
      $cities[$key]['sessions'] += (int) $r['sessions'];
      $cities[$key]['views'] += (int) $r['screenPageViews'];
    }
    foreach ($internal as $r) {
      $key = $r['countryId'] . '|' . $r['city'];
      if (isset($cities[$key])) {
        $cities[$key]['internalSessions'] += (int) $r['sessions'];
      }
    }
    foreach ($cities as &$city) {
      $point = $this->cities->locate($city['countryId'], $city['city']);
      $city['lat'] = $point[0] ?? NULL;
      $city['lon'] = $point[1] ?? NULL;
    }
    unset($city);
    usort($cities, static fn(array $a, array $b): int => $b['sessions'] <=> $a['sessions']);
    return $cities;
  }

  /**
   * Visits per country, most first.
   */
  private static function countries(array $places): array {
    $countries = [];
    foreach ($places as $r) {
      $countries[$r['country']] = ($countries[$r['country']] ?? 0) + (int) $r['sessions'];
    }
    arsort($countries);
    $out = [];
    foreach ($countries as $country => $sessions) {
      $out[] = ['country' => (string) $country, 'sessions' => $sessions];
    }
    return $out;
  }

  /**
   * Sessions by weekday (0 = Sunday) and hour, every cell present.
   */
  private static function weekHours(array $rows): array {
    $grid = [];
    foreach ($rows as $r) {
      $grid[(int) $r['dayOfWeek']][(int) $r['hour']] = (int) $r['sessions'];
    }
    $out = [];
    for ($day = 0; $day < 7; $day++) {
      for ($hour = 0; $hour < 24; $hour++) {
        $out[] = [$day, $hour, $grid[$day][$hour] ?? 0];
      }
    }
    return $out;
  }

  /**
   * Writes an Analytics date ("20261004") the ISO way ("2026-10-04").
   */
  private static function isoDate(string $date): string {
    return preg_match('/^(\d{4})(\d{2})(\d{2})$/', $date, $m) ? "$m[1]-$m[2]-$m[3]" : $date;
  }

  /**
   * The path of a landing page, which may carry a query string.
   */
  private static function pathOf(string $page): string {
    return (string) strtok($page, '?');
  }

}
