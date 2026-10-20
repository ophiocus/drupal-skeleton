<?php

declare(strict_types=1);

namespace Drupal\ga4_report\Source;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\ga4_report\AdSense\AdSenseClient;
use Drupal\ga4_report\Report\Dates;
use Drupal\ga4_report\ReportException;

/**
 * Ad earnings per day from AdSense, over the report's dates.
 */
final class AdSenseSource implements SourceInterface {

  /**
   * The metrics asked for, in the order the snapshot keeps them.
   */
  public const METRICS = ['ESTIMATED_EARNINGS', 'PAGE_VIEWS', 'PAGE_VIEWS_RPM', 'IMPRESSIONS', 'CLICKS'];

  public function __construct(
    private readonly AdSenseClient $client,
    private readonly TimeInterface $time,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function id(): string {
    return 'adsense';
  }

  /**
   * {@inheritdoc}
   */
  public function label(): string {
    return 'AdSense';
  }

  /**
   * {@inheritdoc}
   */
  public function isConfigured(array $settings): bool {
    return trim((string) ($settings['adsense_account'] ?? '')) !== '' && $this->client->isConfigured();
  }

  /**
   * {@inheritdoc}
   */
  public function fetch(array $settings): array {
    $account = self::account((string) $settings['adsense_account']);
    $now = $this->time->getCurrentTime();
    $timezone = (string) $this->configFactory->get('system.date')->get('timezone.default');
    $first = Dates::day((string) $settings['start_date'], $now, $timezone);
    $last = Dates::day((string) $settings['end_date'], $now, $timezone);
    $report = $this->client->generate($account, self::params($first, $last));
    $snapshot = self::normalize($report);
    return [
      'snapshot' => $snapshot,
      'meta' => ['account' => $account, 'start_date' => $first, 'end_date' => $last],
      'summary' => sprintf('%s, %s to %s: %s %s estimated.', $account, $first, $last, number_format($snapshot['totals']['earnings'], 2), $snapshot['currency']),
    ];
  }

  /**
   * The account resource name from what a site may write.
   *
   * "pub-123", "ca-pub-123" and "accounts/pub-123" all name one account.
   *
   * @throws \Drupal\ga4_report\ReportException
   *   When the value holds no publisher ID.
   */
  public static function account(string $value): string {
    if (!preg_match('/(pub-\d{6,})/', $value, $m)) {
      throw new ReportException('The AdSense account is the publisher ID, "pub-" and its digits.');
    }
    return 'accounts/' . $m[1];
  }

  /**
   * The query of the daily report.
   */
  public static function params(string $first, string $last): array {
    [$fy, $fm, $fd] = array_map('intval', explode('-', $first));
    [$ly, $lm, $ld] = array_map('intval', explode('-', $last));
    return [
      'dateRange' => 'CUSTOM',
      'startDate.year' => (string) $fy,
      'startDate.month' => (string) $fm,
      'startDate.day' => (string) $fd,
      'endDate.year' => (string) $ly,
      'endDate.month' => (string) $lm,
      'endDate.day' => (string) $ld,
      'dimensions' => ['DATE'],
      'metrics' => self::METRICS,
      'orderBy' => ['+DATE'],
    ];
  }

  /**
   * The snapshot from the report.
   *
   * Days: [Y-m-d, earnings, page views, page RPM, impressions, clicks].
   */
  public static function normalize(array $report): array {
    $names = array_column($report['headers'] ?? [], 'name');
    $currency = '';
    foreach ($report['headers'] ?? [] as $header) {
      $currency = $currency ?: (string) ($header['currencyCode'] ?? '');
    }
    $days = [];
    foreach ($report['rows'] ?? [] as $row) {
      $values = array_column($row['cells'] ?? [], 'value');
      if (count($values) !== count($names)) {
        continue;
      }
      $cell = array_combine($names, $values);
      $days[] = [
        (string) $cell['DATE'],
        round((float) ($cell['ESTIMATED_EARNINGS'] ?? 0), 2),
        (int) ($cell['PAGE_VIEWS'] ?? 0),
        round((float) ($cell['PAGE_VIEWS_RPM'] ?? 0), 2),
        (int) ($cell['IMPRESSIONS'] ?? 0),
        (int) ($cell['CLICKS'] ?? 0),
      ];
    }
    usort($days, static fn(array $a, array $b): int => strcmp($a[0], $b[0]));
    $earnings = array_sum(array_column($days, 1));
    $views = array_sum(array_column($days, 2));
    return [
      'version' => 1,
      'currency' => $currency,
      'days' => $days,
      'totals' => [
        'earnings' => round($earnings, 2),
        'pageViews' => $views,
        'rpm' => $views ? round($earnings / $views * 1000, 2) : NULL,
        'impressions' => array_sum(array_column($days, 4)),
        'clicks' => array_sum(array_column($days, 5)),
      ],
    ];
  }

}
