<?php

declare(strict_types=1);

namespace Drupal\ga4_report\Source;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\ga4_report\Cloudflare\CloudflareClient;
use Drupal\ga4_report\Report\Dates;
use Drupal\ga4_report\ReportException;

/**
 * The edge: requests and cache status from Cloudflare, per minute and per day.
 *
 * Three datasets in one query. The last 24 hours minute by minute come from
 * httpRequestsAdaptiveGroups, grouped by cache status, without the platform's
 * own cache warmer: it fetches every page after each purge, so it can make
 * half of a day's requests and halve the hit rate readers actually got. The
 * warmer's share is counted apart over the same window. Days come from
 * httpRequests1dGroups over the report's dates, at most the last 30; that
 * dataset cannot be filtered by user agent, so its totals include the warmer.
 */
final class CloudflareSource implements SourceInterface {

  /**
   * Cache statuses served from the edge.
   */
  public const CACHED = ['hit', 'stale', 'updating', 'revalidated'];

  /**
   * Cache statuses that went to the origin and could have been cached.
   */
  public const MISSED = ['miss', 'expired'];

  /**
   * Requests with no cache status: Cloudflare's own beacons, mostly.
   */
  public const BEACON = 'none';

  /**
   * Days the daily dataset is asked for, at most.
   */
  public const MAX_DAYS = 30;

  /**
   * The user agent of the platform's cache warmer (webrunners tec-cache-warm).
   */
  public const WARMER_UA = 'webrunners-cache-warm/1';

  public function __construct(
    private readonly CloudflareClient $client,
    private readonly TimeInterface $time,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function id(): string {
    return 'cloudflare';
  }

  /**
   * {@inheritdoc}
   */
  public function label(): string {
    return 'Cloudflare';
  }

  /**
   * {@inheritdoc}
   */
  public function isConfigured(array $settings): bool {
    return trim((string) ($settings['cloudflare_zone'] ?? '')) !== '' && $this->client->isConfigured();
  }

  /**
   * {@inheritdoc}
   */
  public function fetch(array $settings): array {
    $zone = strtolower(trim((string) $settings['cloudflare_zone']));
    if (!preg_match('/^[0-9a-f]{32}$/', $zone)) {
      throw new ReportException('The Cloudflare zone ID is the 32-character hex value on the zone\'s Overview page, not its name.');
    }
    $now = $this->time->getCurrentTime();
    $to = intdiv($now, 60) * 60;
    $from = $to - 86400;
    $days = self::days($settings, $now, (string) $this->configFactory->get('system.date')->get('timezone.default'));
    $data = $this->client->query(self::query($zone, $from, $to, $days[0], $days[1]));
    $snapshot = self::normalize($data, $from, $to);
    return [
      'snapshot' => $snapshot,
      'meta' => ['zone' => $zone, 'from' => gmdate('c', $from), 'to' => gmdate('c', $to), 'days' => $days],
      'summary' => sprintf('Zone %s: %d requests in 24 h, %s %% from cache; the cache warmer %d more.', substr($zone, 0, 8), $snapshot['totals']['minuteRequests'], $snapshot['totals']['minuteHitRate'] === NULL ? '–' : round($snapshot['totals']['minuteHitRate'] * 100, 1), $snapshot['totals']['warmRequests']),
    ];
  }

  /**
   * The first and last day of the daily dataset.
   *
   * @return array{0: string, 1: string}
   *   Y-m-d, at most MAX_DAYS apart, ending no later than today.
   */
  public static function days(array $settings, int $now, string $timezone): array {
    $last = Dates::day((string) ($settings['end_date'] ?? 'today'), $now, $timezone);
    $first = Dates::day((string) ($settings['start_date'] ?? '30daysAgo'), $now, $timezone);
    $floor = (new \DateTimeImmutable($last))->modify('-' . (self::MAX_DAYS - 1) . ' days')->format('Y-m-d');
    return [max($first, $floor), $last];
  }

  /**
   * The GraphQL query. Every value in it was validated or built here.
   */
  public static function query(string $zone, int $from, int $to, string $firstDay, string $lastDay): string {
    $fromIso = gmdate('Y-m-d\TH:i:s\Z', $from);
    $toIso = gmdate('Y-m-d\TH:i:s\Z', $to);
    $ua = self::WARMER_UA;
    return <<<GRAPHQL
{
  viewer {
    zones(filter: {zoneTag: "$zone"}) {
      minutes: httpRequestsAdaptiveGroups(limit: 10000, filter: {datetime_geq: "$fromIso", datetime_lt: "$toIso", userAgent_neq: "$ua"}, orderBy: [datetimeMinute_ASC]) {
        count
        dimensions { datetimeMinute cacheStatus }
      }
      warm: httpRequestsAdaptiveGroups(limit: 100, filter: {datetime_geq: "$fromIso", datetime_lt: "$toIso", userAgent: "$ua"}) {
        count
        dimensions { cacheStatus }
      }
      days: httpRequests1dGroups(limit: 100, filter: {date_geq: "$firstDay", date_leq: "$lastDay"}, orderBy: [date_ASC]) {
        dimensions { date }
        sum { requests cachedRequests bytes cachedBytes pageViews }
        uniq { uniques }
      }
    }
  }
}
GRAPHQL;
  }

  /**
   * The snapshot from the query's data.
   *
   * Minutes: [UTC minute "Y-m-d\TH:i", cached, missed, other, beacons]; every
   * minute of the window is present. Hit rate counts cached against cached +
   * missed + other (dynamic, bypass), leaving beacons out. Minutes are
   * everyone but the cache warmer, whose requests and hit rate are totalled
   * apart (warmRequests, warmHitRate) the same way. Days carry Cloudflare's own
   * totals, beacons and the warmer included, as its dashboard counts them.
   */
  public static function normalize(array $data, int $from, int $to): array {
    $zone = $data['viewer']['zones'][0] ?? [];
    $byMinute = [];
    for ($t = $from; $t < $to; $t += 60) {
      $byMinute[gmdate('Y-m-d\TH:i', $t)] = [0, 0, 0, 0];
    }
    foreach ($zone['minutes'] ?? [] as $row) {
      $minute = substr((string) ($row['dimensions']['datetimeMinute'] ?? ''), 0, 16);
      if (!isset($byMinute[$minute])) {
        continue;
      }
      $status = (string) ($row['dimensions']['cacheStatus'] ?? '');
      $slot = match (TRUE) {
        in_array($status, self::CACHED, TRUE) => 0,
        in_array($status, self::MISSED, TRUE) => 1,
        $status === self::BEACON => 3,
        default => 2,
      };
      $byMinute[$minute][$slot] += (int) ($row['count'] ?? 0);
    }
    $minutes = [];
    $cached = $all = 0;
    foreach ($byMinute as $minute => [$c, $m, $o, $b]) {
      $minutes[] = [$minute, $c, $m, $o, $b];
      $cached += $c;
      $all += $c + $m + $o;
    }
    $warmCached = $warmAll = 0;
    foreach ($zone['warm'] ?? [] as $row) {
      $status = (string) ($row['dimensions']['cacheStatus'] ?? '');
      if ($status === self::BEACON) {
        continue;
      }
      $warmAll += (int) ($row['count'] ?? 0);
      if (in_array($status, self::CACHED, TRUE)) {
        $warmCached += (int) ($row['count'] ?? 0);
      }
    }
    $days = [];
    $dayRequests = $dayCached = 0;
    foreach ($zone['days'] ?? [] as $row) {
      $sum = $row['sum'] ?? [];
      $days[] = [
        (string) ($row['dimensions']['date'] ?? ''),
        (int) ($sum['requests'] ?? 0),
        (int) ($sum['cachedRequests'] ?? 0),
        (int) ($sum['bytes'] ?? 0),
        (int) ($sum['cachedBytes'] ?? 0),
        (int) ($sum['pageViews'] ?? 0),
        (int) ($row['uniq']['uniques'] ?? 0),
      ];
      $dayRequests += (int) ($sum['requests'] ?? 0);
      $dayCached += (int) ($sum['cachedRequests'] ?? 0);
    }
    return [
      'version' => 1,
      'minutes' => $minutes,
      'days' => $days,
      'totals' => [
        'minuteRequests' => $all,
        'minuteHitRate' => $all ? $cached / $all : NULL,
        'warmRequests' => $warmAll,
        'warmHitRate' => $warmAll ? $warmCached / $warmAll : NULL,
        'dayRequests' => $dayRequests,
        'dayHitRate' => $dayRequests ? $dayCached / $dayRequests : NULL,
      ],
    ];
  }

}
