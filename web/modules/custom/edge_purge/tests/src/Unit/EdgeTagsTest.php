<?php

declare(strict_types=1);

namespace Drupal\Tests\edge_purge\Unit;

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

}
