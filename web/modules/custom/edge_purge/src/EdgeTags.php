<?php

declare(strict_types=1);

namespace Drupal\edge_purge;

/**
 * How Drupal cache tags are named at the CDN.
 *
 * Cloudflare reads the Cache-Tag response header (comma-separated, at most
 * 16 KB in all) and strips it before the visitor sees it. Drupal tags are
 * long ("config:block.block.olivero_main_menu") and a page carries hundreds,
 * so each is sent as a fixed 12-character hash: about 1,100 tags fit.
 */
final class EdgeTags {

  /**
   * The response header the CDN reads.
   */
  public const HEADER = 'Cache-Tag';

  /**
   * Bytes of header kept, under Cloudflare's 16 KB limit.
   */
  public const MAX_HEADER = 15000;

  /**
   * The CDN's name for a Drupal cache tag.
   */
  public static function hash(string $tag): string {
    return substr(hash('xxh3', $tag), 0, 12);
  }

  /**
   * The Cache-Tag header value for a response carrying these tags.
   *
   * Tags past MAX_HEADER are dropped: those pages then rotate by max-age
   * instead of being purged by that tag. Every rendered page also carries
   * "rendered", which a full purge invalidates.
   *
   * @param string[] $tags
   *   Drupal cache tags.
   */
  public static function header(array $tags): string {
    $hashes = [];
    $length = 0;
    foreach (array_unique($tags) as $tag) {
      $length += 13;
      if ($length > self::MAX_HEADER) {
        break;
      }
      $hashes[] = self::hash($tag);
    }
    return implode(',', $hashes);
  }

}
