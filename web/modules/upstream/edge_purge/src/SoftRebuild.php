<?php

declare(strict_types=1);

namespace Drupal\edge_purge;

use Drupal\edge_purge\Cache\FlushShield;

/**
 * A cache rebuild that keeps the pages when no page-building code changed.
 *
 * "drush cr" empties every bin, the three that hold finished pages included,
 * so after every deploy each reader's request and each crawler's miss renders
 * from nothing: 0.6–4.6 s at the origin against 23–350 ms from a kept page or
 * dynamic page (origin-cache audit, 2026-10-20). This runs core's own
 * drupal_flush_all_caches(), so the container, router, plugins, Twig and every
 * other bin are rebuilt exactly as by "drush cr", with the page, dynamic page
 * and render bins shielded from its deleteAll(). What config or content
 * changed reaches them through their cache tags, as on any other day.
 *
 * Only when no page-building code changed (RenderFingerprint against the last
 * release): otherwise the bins are emptied as before, so the host's verify
 * pass compares fresh renders, never kept ones.
 */
final class SoftRebuild {

  /**
   * The bins that hold finished pages and their parts.
   */
  public const BINS = ['page', 'dynamic_page_cache', 'render'];

  /**
   * Whether the page bins can be kept: render code unchanged since release.
   *
   * @param mixed $released
   *   The last release's file map (state RenderFingerprint::STATE), or NULL.
   * @param array<string, string> $now
   *   RenderFingerprint::files() now.
   *
   * @return string[]|null
   *   The changed paths ([] = none), or NULL when there is no release yet.
   */
  public static function changed(mixed $released, array $now): ?array {
    return is_array($released) ? RenderFingerprint::changed($released, $now) : NULL;
  }

  /**
   * Flushes all caches; keeps the page bins' items when $keep_pages.
   */
  public static function flush(bool $keep_pages): void {
    if ($keep_pages) {
      $container = \Drupal::getContainer();
      foreach ($container->getParameter('cache_bins') as $service_id => $bin) {
        if (in_array($bin, self::BINS, TRUE)) {
          $container->set($service_id, new FlushShield($container->get($service_id)));
        }
      }
    }
    // Without a kernel argument it also invalidates and rebuilds the
    // container, which drops the shields again.
    drupal_flush_all_caches();
  }

}
