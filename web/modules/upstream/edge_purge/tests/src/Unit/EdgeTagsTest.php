<?php

declare(strict_types=1);

namespace Drupal\Tests\edge_purge\Unit;

use Drupal\Core\Site\Settings;
use Drupal\edge_purge\EdgeTags;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * The CDN's names for Drupal cache tags.
 */
#[Group('edge_purge')]
final class EdgeTagsTest extends UnitTestCase {

  /**
   * A tag always gets the same short name, and different tags do not share it.
   */
  public function testHashIsStableAndShort(): void {
    $this->assertSame(EdgeTags::hash('node:1'), EdgeTags::hash('node:1'));
    $this->assertNotSame(EdgeTags::hash('node:1'), EdgeTags::hash('node:10'));
    $this->assertMatchesRegularExpression('/^[0-9a-f]{12}$/', EdgeTags::hash('config:system.site'));
  }

  /**
   * The header lists each tag once, and the purge names the same value.
   */
  public function testHeaderDeduplicates(): void {
    $header = EdgeTags::header(['node:1', 'node_list', 'node:1']);
    $this->assertSame(EdgeTags::hash('node:1') . ',' . EdgeTags::hash('node_list'), $header);
  }

  /**
   * A page with too many tags stays under Cloudflare's 16 KB header limit.
   */
  public function testHeaderStaysUnderTheLimit(): void {
    $tags = array_map(static fn(int $n): string => "node:$n", range(1, 5000));
    $header = EdgeTags::header($tags);
    $this->assertLessThanOrEqual(EdgeTags::MAX_HEADER, strlen($header));
    $this->assertStringStartsWith(EdgeTags::hash('node:1') . ',', $header);
  }

  /**
   * Tags no page carries, or carried by every page for no change, never go.
   */
  public function testPurgeableDropsInternalTags(): void {
    new Settings([]);
    $tags = [
      'node:1', 'node:1:revisions', 'taxonomy_term:7:revisions', 'node_values',
      'http_response', 'route_match', 'routes', 'local_task', 'rendered',
      'config:field.storage.node.field_values', 'oidoenvivo:day', '4xx-response',
    ];
    $this->assertSame(
      ['node:1', 'rendered', 'config:field.storage.node.field_values', 'oidoenvivo:day', '4xx-response'],
      EdgeTags::purgeable($tags),
    );
    new Settings(['edge_purge_ignore_tags' => ['oidoenvivo:day']]);
    $this->assertSame(['node:1'], EdgeTags::purgeable(['node:1', 'oidoenvivo:day']));
  }

  /**
   * The CDN lifetime comes from settings.php; unset or invalid means none.
   */
  public function testCdnMaxAgeFromSettings(): void {
    new Settings([]);
    $this->assertSame(0, EdgeTags::cdnMaxAge());
    new Settings(['edge_purge_cdn_max_age' => 2592000]);
    $this->assertSame(2592000, EdgeTags::cdnMaxAge());
    new Settings(['edge_purge_cdn_max_age' => -5]);
    $this->assertSame(0, EdgeTags::cdnMaxAge());
  }

}
