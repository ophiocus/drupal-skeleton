<?php

declare(strict_types=1);

namespace Drupal\appointments;

use Drupal\Core\Cache\CacheableResponseInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * The booking page's lifetime, as HTTP headers.
 *
 * A page of free times is true until a booking, a service, a room or the
 * settings change (its cache tags, purged at the edge by tag) or until the
 * clock moves past what it shows (Calendar::freshFor()). The controller
 * attaches the second as a deadline, a timestamp in an internal header, so
 * it survives Drupal's dynamic page cache, which keeps the page's headers
 * but not the moment it was rendered. This turns the deadline into the
 * seconds left, for browsers, Drupal's page cache (Expires) and the CDN.
 */
final class Freshness {

  /**
   * The internal header carrying the deadline; never sent to the client.
   */
  public const HEADER = 'X-Appointments-Fresh-Until';

  /**
   * The header the CDN reads for its own lifetime (edge_purge's CDN_HEADER).
   */
  public const CDN_HEADER = 'Cloudflare-CDN-Cache-Control';

  /**
   * Sets the lifetime headers of a response that carries a deadline.
   *
   * Seconds left > 0: Expires at the deadline (Drupal's page cache keeps
   * the page until then) and, when core made the response public,
   * `public, max-age=N` for browsers and the same max-age for the CDN.
   * Nothing left: private, no-cache, and the caller stops Drupal's page
   * cache from keeping it.
   *
   * @return int|null
   *   The seconds left, or NULL when the response carries no deadline.
   */
  public static function apply(Response $response, int $now): ?int {
    if (!$response->headers->has(self::HEADER)) {
      return NULL;
    }
    $left = max(0, (int) $response->headers->get(self::HEADER) - $now);
    // Something else on the page cannot be cached at all.
    if ($response instanceof CacheableResponseInterface && $response->getCacheableMetadata()->getCacheMaxAge() === 0) {
      $left = 0;
    }
    if ($left === 0) {
      $response->headers->remove(self::HEADER);
      $response->headers->remove(self::CDN_HEADER);
      $response->headers->set('Cache-Control', 'no-cache, private');
      // Core's date in the past for responses nobody may keep.
      $response->setExpires(new \DateTimeImmutable('@280299600'));
      return 0;
    }
    $response->setExpires(new \DateTimeImmutable('@' . ($now + $left)));
    if ($response->headers->hasCacheControlDirective('public')) {
      $response->headers->set('Cache-Control', 'public, max-age=' . $left);
      $response->headers->set(self::CDN_HEADER, 'max-age=' . $left);
    }
    return $left;
  }

}
