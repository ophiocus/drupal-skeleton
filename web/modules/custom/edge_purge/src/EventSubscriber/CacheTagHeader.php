<?php

declare(strict_types=1);

namespace Drupal\edge_purge\EventSubscriber;

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
    }
  }

}
