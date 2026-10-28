<?php

declare(strict_types=1);

namespace Drupal\Tests\appointments\Unit;

use Drupal\appointments\EventSubscriber\FreshHeaders;
use Drupal\appointments\Freshness;
use Drupal\appointments\StackMiddleware\FreshMiddleware;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\CacheableResponse;
use Drupal\Core\PageCache\ResponsePolicy\KillSwitch;
use Drupal\Core\PageCache\ResponsePolicyInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * The booking page's deadline as HTTP headers.
 */
#[Group('appointments')]
final class FreshnessTest extends UnitTestCase {

  /**
   * The clock of these tests.
   */
  private const NOW = 1_800_000_000;

  /**
   * A response the way core leaves it, carrying a deadline.
   *
   * @param string $cache_control
   *   What core's FinishResponseSubscriber set.
   * @param int|null $until
   *   The deadline, or NULL for none.
   * @param int $max_age
   *   The page's bubbled max-age.
   */
  private function response(string $cache_control, ?int $until, int $max_age = 600): CacheableResponse {
    $response = new CacheableResponse('<p>Hola</p>', 200, ['Cache-Control' => $cache_control]);
    if ($until !== NULL) {
      $response->headers->set(Freshness::HEADER, (string) $until);
    }
    $response->addCacheableDependency((new CacheableMetadata())->setCacheMaxAge($max_age));
    return $response;
  }

  /**
   * A clock stopped at a moment.
   */
  private function clock(int $now): TimeInterface {
    $time = $this->createMock(TimeInterface::class);
    $time->method('getCurrentTime')->willReturn($now);
    $time->method('getRequestTime')->willReturn($now);
    return $time;
  }

  /**
   * Public: max-age, Expires and the CDN lifetime all end at the deadline.
   */
  public function testPublicPageLivesUntilItsDeadline(): void {
    $response = $this->response('public, max-age=86400', self::NOW + 600);
    $this->assertSame(600, Freshness::apply($response, self::NOW));
    $this->assertSame('max-age=600, public', $response->headers->get('Cache-Control'));
    $this->assertSame('max-age=600', $response->headers->get(Freshness::CDN_HEADER));
    $this->assertSame(self::NOW + 600, $response->getExpires()?->getTimestamp());
  }

  /**
   * Private (a site whose page max-age is 0): Expires only, for Drupal.
   *
   * Drupal's page cache keeps a page until its Expires; core's date in the
   * past would keep it forever, past the deadline.
   */
  public function testPrivatePageStillExpiresInDrupal(): void {
    $response = $this->response('must-revalidate, no-cache, private', self::NOW + 600);
    $this->assertSame(600, Freshness::apply($response, self::NOW));
    $this->assertTrue($response->headers->hasCacheControlDirective('private'));
    $this->assertFalse($response->headers->has(Freshness::CDN_HEADER));
    $this->assertSame(self::NOW + 600, $response->getExpires()?->getTimestamp());
  }

  /**
   * Nothing left, or something on the page uncacheable: kept nowhere.
   */
  public function testNothingLeftIsPrivate(): void {
    $responses = [
      $this->response('public, max-age=86400', self::NOW),
      $this->response('public, max-age=86400', self::NOW + 600, 0),
    ];
    foreach ($responses as $response) {
      $this->assertSame(0, Freshness::apply($response, self::NOW));
      $this->assertTrue($response->headers->hasCacheControlDirective('private'));
      $this->assertTrue($response->headers->hasCacheControlDirective('no-cache'));
      $this->assertFalse($response->headers->has(Freshness::HEADER));
      $this->assertFalse($response->headers->has(Freshness::CDN_HEADER));
      $this->assertLessThan(self::NOW, $response->getExpires()?->getTimestamp());
    }
  }

  /**
   * Other pages are left as core made them.
   */
  public function testOtherPagesUntouched(): void {
    $response = $this->response('public, max-age=86400', NULL);
    $this->assertNull(Freshness::apply($response, self::NOW));
    $this->assertSame('max-age=86400, public', $response->headers->get('Cache-Control'));
  }

  /**
   * The subscriber stops Drupal's page cache from keeping a dead page.
   */
  public function testSubscriberTriggersTheKillSwitch(): void {
    $kernel = $this->createMock(HttpKernelInterface::class);
    $request = Request::create('/book');

    $kill = new KillSwitch();
    $live = $this->response('public, max-age=86400', self::NOW + 600);
    (new FreshHeaders($this->clock(self::NOW), $kill))->onResponse(new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $live));
    $this->assertNull($kill->check($live, $request));
    $this->assertTrue($live->headers->has(Freshness::HEADER), 'Kept for the page cache to store.');

    $dead = $this->response('public, max-age=86400', self::NOW);
    (new FreshHeaders($this->clock(self::NOW), $kill))->onResponse(new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $dead));
    $this->assertSame(ResponsePolicyInterface::DENY, $kill->check($dead, $request));
  }

  /**
   * A copy served again later says how long it has left, not how long it had.
   */
  public function testMiddlewareReagesStoredCopy(): void {
    // Stored at NOW with 600 seconds left, as the page cache keeps it.
    $stored = $this->response('public, max-age=86400', self::NOW + 600);
    Freshness::apply($stored, self::NOW);
    $kernel = $this->createMock(HttpKernelInterface::class);
    $kernel->method('handle')->willReturn($stored);

    // Served again 200 seconds later.
    $response = (new FreshMiddleware($kernel, $this->clock(self::NOW + 200)))->handle(Request::create('/book'));
    $this->assertSame('max-age=400, public', $response->headers->get('Cache-Control'));
    $this->assertSame('max-age=400', $response->headers->get(Freshness::CDN_HEADER));
    $this->assertFalse($response->headers->has(Freshness::HEADER), 'The deadline never leaves the site.');

    // Any other response passes unchanged.
    $plain = new Response('x', 200, ['Cache-Control' => 'public, max-age=86400']);
    $other = $this->createMock(HttpKernelInterface::class);
    $other->method('handle')->willReturn($plain);
    $this->assertSame('max-age=86400, public', (new FreshMiddleware($other, $this->clock(self::NOW)))->handle(Request::create('/'))->headers->get('Cache-Control'));
  }

}
