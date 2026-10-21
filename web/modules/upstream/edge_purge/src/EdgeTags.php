<?php

declare(strict_types=1);

namespace Drupal\edge_purge;

use Drupal\Core\Site\Settings;

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
   * The header carrying a hash of the response body.
   *
   * The CDN keeps it with its copy. After a release that changed render
   * code, the host compares it with a fresh render's and purges by URL only
   * the pages whose HTML changed (tec-cache-warm, verify mode), instead of
   * every page.
   */
  public const HASH_HEADER = 'X-Edge-Hash';

  /**
   * Bytes of header kept, under Cloudflare's 16 KB limit.
   */
  public const MAX_HEADER = 15000;

  /**
   * The header that sets the CDN's own lifetime.
   *
   * Cloudflare reads it and strips it; browsers never see it.
   * CDN-Cache-Control works the same on other CDNs.
   */
  public const CDN_HEADER = 'Cloudflare-CDN-Cache-Control';

  /**
   * The CDN lifetime of a tagged page, in seconds; 0 leaves it to the page.
   *
   * $settings['edge_purge_cdn_max_age'] in settings.php. Safe only while the
   * purge timer drains the queue: a missed purge then lasts this long.
   */
  public static function cdnMaxAge(): int {
    return max(0, (int) Settings::get('edge_purge_cdn_max_age', 0));
  }

  /**
   * Tags that are never worth a purge.
   *
   * "http_response" is on every response and only a router rebuild (every
   * "drush cr") invalidates it: purging it empties the whole edge for no
   * change. The others never reach a response at all.
   */
  public const INTERNAL = ['http_response', 'route_match', 'routes', 'local_task'];

  /**
   * The tags of these invalidated, minus the ones never worth a purge.
   *
   * Besides INTERNAL: "<type>:<id>:revisions" and "<type>_values" belong to
   * the entity storage's own cache, which Drupal 11.4 invalidates on every
   * resetCache(); a bulk load of 5,000 unchanged entities used to queue
   * 5,000 of them. $settings['edge_purge_ignore_tags'] adds more.
   *
   * @param string[] $tags
   *   Drupal cache tags.
   *
   * @return string[]
   *   The ones to purge at the CDN.
   */
  public static function purgeable(array $tags): array {
    $ignore = array_merge(self::INTERNAL, (array) Settings::get('edge_purge_ignore_tags', []));
    return array_values(array_filter($tags, static function (string $tag) use ($ignore): bool {
      if (in_array($tag, $ignore, TRUE) || str_ends_with($tag, ':revisions')) {
        return FALSE;
      }
      return !(str_ends_with($tag, '_values') && !str_contains($tag, ':'));
    }));
  }

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
