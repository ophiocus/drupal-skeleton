<?php

declare(strict_types=1);

namespace Drupal\Tests\ga4_report\Unit;

use Drupal\ga4_report\Google\Ga4Client;
use Drupal\ga4_report\ReportException;
use Drupal\Tests\ga4_report\StubTokens;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Group;

/**
 * The one call made to Google: batchRunReports.
 */
#[Group('ga4_report')]
final class Ga4ClientTest extends UnitTestCase {

  /**
   * Requests sent, newest last.
   */
  private array $sent = [];

  /**
   * A client answering with the given responses.
   */
  private function client(array $responses): Ga4Client {
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($this->sent));
    return new Ga4Client(new Client(['handler' => $stack]), new StubTokens());
  }

  /**
   * An answer to a batch of n reports.
   */
  private static function answer(int $n, int $from = 0): Response {
    $reports = array_map(static fn(int $i): array => ['rowCount' => $i], range($from, $from + $n - 1));
    return new Response(200, [], json_encode(['reports' => $reports]));
  }

  /**
   * Seven reports go in two batches and come back under their own keys.
   */
  public function testBatches(): void {
    $requests = [];
    foreach (range(0, 6) as $i) {
      $requests["r$i"] = ['metrics' => [['name' => 'sessions']]];
    }
    $reports = $this->client([self::answer(5), self::answer(2, 5)])->run('42', $requests);
    $this->assertSame(['r0', 'r1', 'r2', 'r3', 'r4', 'r5', 'r6'], array_keys($reports));
    $this->assertSame(6, $reports['r6']['rowCount']);
    $this->assertCount(2, $this->sent);
    $request = $this->sent[0]['request'];
    $this->assertSame('https://analyticsdata.googleapis.com/v1beta/properties/42:batchRunReports', (string) $request->getUri());
    $this->assertSame('Bearer test-token', $request->getHeaderLine('Authorization'));
    $this->assertCount(5, json_decode((string) $request->getBody(), TRUE)['requests']);
  }

  /**
   * Google's own words reach the person reading the error.
   */
  public function testGoogleError(): void {
    $words = 'User does not have sufficient permissions for this property.';
    $body = json_encode(['error' => ['code' => 403, 'message' => $words]]);
    $this->expectException(ReportException::class);
    $this->expectExceptionMessage('Google Analytics answered 403: ' . $words);
    $this->client([new Response(403, [], $body)])->run('42', ['a' => []]);
  }

  /**
   * A measurement ID is not a property ID.
   */
  public function testPropertyId(): void {
    $this->expectException(ReportException::class);
    $this->expectExceptionMessage('must be its number');
    $this->client([])->run('G-ABC123', ['a' => []]);
  }

  /**
   * A short batch is an error, not a silently missing report.
   */
  public function testShortBatch(): void {
    $this->expectException(ReportException::class);
    $this->expectExceptionMessage('answered 1 reports for a batch of 2');
    $this->client([self::answer(1)])->run('42', ['a' => [], 'b' => []]);
  }

}
