<?php

declare(strict_types=1);

namespace Drupal\edge_purge\EventSubscriber;

use Drupal\Core\Asset\AssetQueryStringInterface;
use Drupal\Core\Cache\CacheableResponseInterface;
use Drupal\edge_purge\EdgeTags;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Names each cacheable response by its cache tags, for the CDN.
 *
 * The page cache stores response headers, so a page served from it carries
 * the header too without this subscriber running.
 */
final class CacheTagHeader implements EventSubscriberInterface {

  public function __construct(
    private readonly AssetQueryStringInterface $assetQueryString,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [KernelEvents::RESPONSE => ['onResponse', -1024]];
  }

  /**
   * Adds the Cache-Tag header to cacheable GET and HEAD responses.
   */
  public function onResponse(ResponseEvent $event): void {
    $response = $event->getResponse();
    if (!$event->isMainRequest() || !$response instanceof CacheableResponseInterface || !$event->getRequest()->isMethodCacheable()) {
      return;
    }
    $header = EdgeTags::header($response->getCacheableMetadata()->getCacheTags());
    if ($header !== '') {
      $response->headers->set(EdgeTags::HEADER, $header);
      // What the page says, as a hash: a release compares it with a fresh
      // render and purges only the pages that changed. Without the assets'
      // cache-busting token, which every cache rebuild renews: a page that
      // differs only by it still loads the same files.
      $body = $response->getContent();
      if (is_string($body)) {
        $token = $this->assetQueryString->get();
        if ($token !== '') {
          $body = str_replace('?' . $token, '', $body);
        }
        // Views names each rendering of a view with a random DOM id.
        $body = preg_replace('/(js-view-dom-id-|"view_dom_id":")[0-9a-f]+/', '$1', $body) ?? $body;
        $response->headers->set(EdgeTags::HASH_HEADER, hash('xxh3', $body));
      }
      // How long the CDN keeps it: a page purged by tag the moment it
      // changes needs no daily expiry at the edge, which only sent it back
      // to the origin to be rendered unchanged. Browsers keep the site's own
      // Cache-Control. Only for what is already public.
      $cdn = EdgeTags::cdnMaxAge();
      if ($cdn > 0 && $response->headers->hasCacheControlDirective('public') && (int) $response->getMaxAge() > 0) {
        $response->headers->set(EdgeTags::CDN_HEADER, 'max-age=' . $cdn);
      }
    }
  }

}
