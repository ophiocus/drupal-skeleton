<?php

declare(strict_types=1);

namespace Drupal\Tests\edge_purge\Unit;

use Drupal\Core\Asset\AssetQueryStringInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\CacheableResponse;
use Drupal\Core\Site\Settings;
use Drupal\edge_purge\EdgeTags;
use Drupal\edge_purge\EventSubscriber\CacheTagHeader;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * The headers a cacheable response gets for the CDN.
 */
#[Group('edge_purge')]
final class CacheTagHeaderTest extends UnitTestCase {

  /**
   * The response after the subscriber, for a body and an asset token.
   *
   * @param string $body
   *   The response body.
   * @param string $token
   *   The assets' cache-busting token.
   * @param array<string, mixed> $settings
   *   Site settings.
   * @param array<string, string> $headers
   *   Headers the response has before the subscriber.
   * @param int $status
   *   The response status.
   * @param string $path
   *   The request path.
   * @param string[] $tags
   *   The response's cache tags.
   */
  private function respond(string $body, string $token, array $settings = [], array $headers = [], int $status = 200, string $path = '/', array $tags = ['node:1']): CacheableResponse {
    new Settings($settings);
    $query = $this->createMock(AssetQueryStringInterface::class);
    $query->method('get')->willReturn($token);
    $response = new CacheableResponse($body, $status, $headers);
    $response->addCacheableDependency((new CacheableMetadata())->setCacheTags($tags));
    $event = new ResponseEvent($this->createMock(HttpKernelInterface::class), Request::create($path), HttpKernelInterface::MAIN_REQUEST, $response);
    (new CacheTagHeader($query))->onResponse($event);
    return $response;
  }

  /**
   * The hash ignores the asset token and Views' DOM ids; real changes count.
   */
  public function testHashIgnoresWhatEveryRebuildRenews(): void {
    $a = $this->respond('<script src="/a.js?tn7tc2"></script><div class="js-view-dom-id-0a1b"></div><p>Hola</p>', 'tn7tc2');
    $b = $this->respond('<script src="/a.js?tn7tc7"></script><div class="js-view-dom-id-ffee"></div><p>Hola</p>', 'tn7tc7');
    $c = $this->respond('<script src="/a.js?tn7tc7"></script><div class="js-view-dom-id-ffee"></div><p>Adiós</p>', 'tn7tc7');

    $this->assertSame(EdgeTags::hash('node:1'), $a->headers->get(EdgeTags::HEADER));
    $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', (string) $a->headers->get(EdgeTags::HASH_HEADER));
    $this->assertSame($a->headers->get(EdgeTags::HASH_HEADER), $b->headers->get(EdgeTags::HASH_HEADER));
    $this->assertNotSame($b->headers->get(EdgeTags::HASH_HEADER), $c->headers->get(EdgeTags::HASH_HEADER));
  }

  /**
   * A 4xx page is named by its path, never by "4xx-response".
   */
  public function testClientErrorCarriesItsPathTag(): void {
    $gone = $this->respond('<p>Ya pasó</p>', '', [], [], 410, '/Eventos/la-maestra/', ['4xx-response', 'node_list:page']);
    $this->assertSame(
      EdgeTags::hash('edge_purge:path:/eventos/la-maestra') . ',' . EdgeTags::hash('node_list:page'),
      $gone->headers->get(EdgeTags::HEADER),
    );

    // A page that answers carries no path tag.
    $page = $this->respond('<p>Hola</p>', '', [], [], 200, '/eventos/la-maestra');
    $this->assertSame(EdgeTags::hash('node:1'), $page->headers->get(EdgeTags::HEADER));
  }

  /**
   * The CDN lifetime goes on public pages, never over one the page set.
   */
  public function testCdnLifetimeOnlyWhenAbsent(): void {
    $year = ['edge_purge_cdn_max_age' => 31536000];
    $public = ['Cache-Control' => 'public, max-age=86400'];

    $page = $this->respond('<p>Hola</p>', '', $year, $public);
    $this->assertSame('max-age=31536000', $page->headers->get(EdgeTags::CDN_HEADER));

    // A page that knows it is true for two minutes only keeps its own.
    $own = $this->respond('<p>Hola</p>', '', $year, $public + [EdgeTags::CDN_HEADER => 'max-age=120']);
    $this->assertSame('max-age=120', $own->headers->get(EdgeTags::CDN_HEADER));

    // Private pages and sites without the setting get none.
    $private = $this->respond('<p>Hola</p>', '', $year, ['Cache-Control' => 'no-cache, private']);
    $this->assertFalse($private->headers->has(EdgeTags::CDN_HEADER));
    $unset = $this->respond('<p>Hola</p>', '', [], $public);
    $this->assertFalse($unset->headers->has(EdgeTags::CDN_HEADER));
  }

}
