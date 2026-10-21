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
   */
  private function respond(string $body, string $token): CacheableResponse {
    new Settings([]);
    $query = $this->createMock(AssetQueryStringInterface::class);
    $query->method('get')->willReturn($token);
    $response = new CacheableResponse($body);
    $response->addCacheableDependency((new CacheableMetadata())->setCacheTags(['node:1']));
    $event = new ResponseEvent($this->createMock(HttpKernelInterface::class), Request::create('/'), HttpKernelInterface::MAIN_REQUEST, $response);
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

}
