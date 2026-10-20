<?php

declare(strict_types=1);

namespace Drupal\ga4_report;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\ga4_report\Source\SourceInterface;
use Psr\Log\LoggerInterface;

/**
 * Fetches every configured source and stores what each returned.
 *
 * One path for the button, Drush and cron. Sources are independent: each one's
 * snapshot is replaced only when all of its data arrived, and a source that
 * fails leaves its previous snapshot, with the failure recorded beside it, so
 * the page keeps drawing the last good data and says why it is old.
 */
final class Refresher {

  /**
   * The sources, keyed by id.
   *
   * @var array<string, \Drupal\ga4_report\Source\SourceInterface>
   */
  private array $sources = [];

  /**
   * Builds the refresher.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\ga4_report\SnapshotStore $store
   *   Where snapshots live.
   * @param \Drupal\Core\Lock\LockBackendInterface $lock
   *   One refresh at a time.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The clock.
   * @param \Psr\Log\LoggerInterface $logger
   *   The module's log channel.
   * @param iterable<\Drupal\ga4_report\Source\SourceInterface> $sources
   *   Every source, in the order they are fetched.
   */
  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly SnapshotStore $store,
    private readonly LockBackendInterface $lock,
    private readonly TimeInterface $time,
    private readonly LoggerInterface $logger,
    iterable $sources,
  ) {
    foreach ($sources as $source) {
      $this->sources[$source->id()] = $source;
    }
  }

  /**
   * Every source, keyed by id.
   *
   * @return array<string, \Drupal\ga4_report\Source\SourceInterface>
   *   The sources.
   */
  public function sources(): array {
    return $this->sources;
  }

  /**
   * The sources the site named everything for.
   *
   * @return array<string, \Drupal\ga4_report\Source\SourceInterface>
   *   The configured sources.
   */
  public function configured(): array {
    $settings = $this->settings();
    return array_filter($this->sources, static fn(SourceInterface $s): bool => $s->isConfigured($settings));
  }

  /**
   * Whether any source can refresh.
   */
  public function isConfigured(): bool {
    return $this->configured() !== [];
  }

  /**
   * Whether cron should refresh now, given the interval and the last run.
   */
  public function isDue(): bool {
    $hours = (int) ($this->settings()['refresh_interval'] ?? 0);
    if ($hours <= 0 || !$this->isConfigured()) {
      return FALSE;
    }
    return $this->time->getRequestTime() - $this->store->lastRun() >= $hours * 3600;
  }

  /**
   * Fetches and stores every configured source, or one.
   *
   * @param string|null $only
   *   A source id to refresh alone.
   *
   * @return array<string, array{ok: bool, message: string}>
   *   One outcome per source fetched, keyed by id.
   *
   * @throws \Drupal\ga4_report\ReportException
   *   When another refresh is running, nothing is configured, or $only names
   *   no configured source.
   */
  public function refresh(?string $only = NULL): array {
    $sources = $this->configured();
    if ($only !== NULL) {
      if (!isset($sources[$only])) {
        throw new ReportException(sprintf('"%s" is not a configured source (configured: %s).', $only, implode(', ', array_keys($sources)) ?: 'none'));
      }
      $sources = [$only => $sources[$only]];
    }
    if (!$sources) {
      throw new ReportException('No source is configured: name a GA4 property, a Cloudflare zone or an AdSense account, and its credentials.');
    }
    if (!$this->lock->acquire('ga4_report_refresh', 600)) {
      throw new ReportException('Another refresh is running.');
    }
    try {
      $settings = $this->settings();
      $this->store->recordRun($this->time->getCurrentTime());
      $outcomes = [];
      foreach ($sources as $id => $source) {
        $outcomes[$id] = $this->fetch($source, $settings);
      }
      return $outcomes;
    }
    finally {
      $this->lock->release('ga4_report_refresh');
    }
  }

  /**
   * Fetches one source and stores the outcome.
   */
  private function fetch(SourceInterface $source, array $settings): array {
    $started = microtime(TRUE);
    $attempted = $this->time->getCurrentTime();
    try {
      $result = $source->fetch($settings);
      $duration = round(microtime(TRUE) - $started, 1);
      $this->store->save($source->id(), $result['snapshot'], [
        'attempted' => $attempted,
        'fetched' => $attempted,
        'duration' => $duration,
      ] + $result['meta']);
      $message = sprintf('%s: %s (%.1f s)', $source->label(), $result['summary'], $duration);
      $this->logger->info($message);
      return ['ok' => TRUE, 'message' => $message];
    }
    catch (\Throwable $e) {
      $error = $e instanceof ReportException ? $e->getMessage() : sprintf('Unexpected %s: %s', get_class($e), $e->getMessage());
      $this->store->recordFailure($source->id(), $error, $attempted);
      $message = sprintf('%s failed, its previous snapshot stays: %s', $source->label(), $error);
      $this->logger->error($message);
      return ['ok' => FALSE, 'message' => $message];
    }
  }

  /**
   * The module's settings.
   */
  private function settings(): array {
    return $this->configFactory->get('ga4_report.settings')->get();
  }

}
