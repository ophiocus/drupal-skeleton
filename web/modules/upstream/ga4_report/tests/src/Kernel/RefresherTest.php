<?php

declare(strict_types=1);

namespace Drupal\Tests\ga4_report\Kernel;

use Drupal\Core\Site\Settings;
use Drupal\ga4_report\Hook\Ga4ReportHooks;
use Drupal\ga4_report\Report\ReportDefinitions;
use Drupal\ga4_report\ReportException;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\ga4_report\StubTokens;
use Drupal\Tests\ga4_report\Traits\ReportFixtureTrait;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * A refresh of all three sources end to end, answered by fixtures.
 */
#[Group('ga4_report')]
#[RunTestsInSeparateProcesses]
final class RefresherTest extends KernelTestBase {

  use ReportFixtureTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'ga4_report'];

  /**
   * The answers, consumed in order.
   */
  private MockHandler $answers;

  /**
   * Requests sent, in order.
   */
  private array $sent = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['system', 'ga4_report']);
    $this->config('ga4_report.settings')->setData(self::fixtureSettings() + [
      'cloudflare_zone' => str_repeat('c', 32),
      'adsense_account' => 'pub-1234567890',
    ])->save();
    $token = tempnam(sys_get_temp_dir(), 'cf');
    file_put_contents($token, "test-cloudflare-token\n");
    $this->setSetting('ga4_report_cloudflare_token', $token);
    // The container may already hold the earlier Settings instance.
    $this->container->set('settings', Settings::getInstance());
    $this->answers = new MockHandler();
    $stack = HandlerStack::create($this->answers);
    $stack->push(Middleware::history($this->sent));
    $this->container->set('http_client', new Client(['handler' => $stack]));
    $this->container->set('ga4_report.tokens', new StubTokens());
    $this->container->set('ga4_report.adsense_tokens', new StubTokens());
  }

  /**
   * Queues the answers of one Analytics fetch, in batches of five.
   */
  private function answerAnalytics(): void {
    $reports = self::fixtureReports();
    $order = array_keys(ReportDefinitions::build(self::fixtureSettings()));
    $this->assertEqualsCanonicalizing($order, array_keys($reports), 'The fixture answers every request.');
    foreach (array_chunk($order, 5) as $chunk) {
      $batch = array_map(static fn(string $id): array => $reports[$id], $chunk);
      $this->answers->append(new Response(200, [], json_encode(['reports' => $batch])));
    }
  }

  /**
   * Queues the answers to one full refresh: Analytics, Cloudflare, AdSense.
   */
  private function answerOneRefresh(): void {
    $this->answerAnalytics();
    $this->answers->append(new Response(200, [], json_encode(['data' => self::fixtureCloudflare()])));
    $this->answers->append(new Response(200, [], json_encode(self::fixtureAdSense())));
  }

  /**
   * Every source is fetched and stored under its own name.
   */
  public function testRefreshStoresEverySource(): void {
    $this->answerOneRefresh();
    $outcomes = $this->container->get('ga4_report.refresher')->refresh();
    $this->assertSame(['ga4', 'cloudflare', 'adsense'], array_keys($outcomes));
    $this->assertSame([TRUE, TRUE, TRUE], array_column($outcomes, 'ok'));
    $this->assertStringContainsString('30 visits', $outcomes['ga4']['message']);
    $this->assertCount(0, $this->answers, 'Every queued answer was used.');

    $store = $this->container->get('ga4_report.store');
    $this->assertSame(30, $store->snapshot('ga4')['totals']['sessions']);
    $this->assertSame(14, $store->meta('ga4')['reports']);
    $this->assertSame(300, $store->snapshot('cloudflare')['totals']['dayRequests']);
    $this->assertSame(4000.5, $store->snapshot('adsense')['totals']['earnings']);
    $this->assertSame('accounts/pub-1234567890', $store->meta('adsense')['account']);

    // The token is read from its file and sent as a bearer, nowhere else.
    $cloudflare = $this->sent[3]['request'];
    $this->assertSame('Bearer test-cloudflare-token', $cloudflare->getHeaderLine('Authorization'));
    $this->assertStringContainsString(str_repeat('c', 32), (string) $cloudflare->getBody());
    $adsense = (string) $this->sent[4]['request']->getUri();
    $this->assertStringStartsWith('https://adsense.googleapis.com/v2/accounts/pub-1234567890/reports:generate?', $adsense);
    $this->assertStringContainsString('metrics=ESTIMATED_EARNINGS&metrics=PAGE_VIEWS', $adsense);
  }

  /**
   * A source that fails keeps its last snapshot; the others still refresh.
   */
  public function testFailureKeepsSnapshot(): void {
    $this->answerOneRefresh();
    $refresher = $this->container->get('ga4_report.refresher');
    $refresher->refresh();

    $this->answerAnalytics();
    $refused = ['data' => NULL, 'errors' => [['message' => 'not authorized for that zone']]];
    $this->answers->append(new Response(200, [], json_encode($refused)));
    $this->answers->append(new Response(403, [], json_encode(['error' => ['message' => 'No access to this account.']])));
    $outcomes = $refresher->refresh();
    $this->assertSame([TRUE, FALSE, FALSE], array_column($outcomes, 'ok'));
    $this->assertStringContainsString('not authorized for that zone', $outcomes['cloudflare']['message']);

    $store = $this->container->get('ga4_report.store');
    $this->assertSame(300, $store->snapshot('cloudflare')['totals']['dayRequests']);
    $this->assertStringContainsString('not authorized for that zone', $store->meta('cloudflare')['error']);
    $this->assertStringContainsString('No access to this account.', $store->meta('adsense')['error']);
    $this->assertNull($store->meta('ga4')['error']);
  }

  /**
   * One source alone; an unknown one is refused.
   */
  public function testOnlyOneSource(): void {
    $this->answers->append(new Response(200, [], json_encode(self::fixtureAdSense())));
    $outcomes = $this->container->get('ga4_report.refresher')->refresh('adsense');
    $this->assertSame(['adsense'], array_keys($outcomes));
    $this->expectException(ReportException::class);
    $this->container->get('ga4_report.refresher')->refresh('matomo');
  }

  /**
   * Cron refreshes once per interval, failed attempts included, and never at 0.
   */
  public function testCron(): void {
    $hooks = new Ga4ReportHooks($this->container->get('ga4_report.refresher'));
    $store = $this->container->get('ga4_report.store');

    $this->answerOneRefresh();
    $hooks->cron();
    $this->assertNotNull($store->snapshot('ga4'));

    // Within the interval nothing is asked (an empty queue would fail it).
    $hooks->cron();
    $this->assertNull($store->meta('ga4')['error']);

    $this->config('ga4_report.settings')->set('refresh_interval', 0)->save();
    $store->recordRun(0);
    $this->assertFalse($this->container->get('ga4_report.refresher')->isDue());

    $this->config('ga4_report.settings')->set('refresh_interval', 1)->save();
    $this->assertTrue($this->container->get('ga4_report.refresher')->isDue());
  }

}
