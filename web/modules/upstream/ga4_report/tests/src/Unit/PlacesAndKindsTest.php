<?php

declare(strict_types=1);

namespace Drupal\Tests\ga4_report\Unit;

use Drupal\ga4_report\Report\CityLocator;
use Drupal\ga4_report\Report\PageKinds;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Kinds of page, and cities on the map.
 */
#[Group('ga4_report')]
final class PlacesAndKindsTest extends UnitTestCase {

  /**
   * A path takes the first kind whose pattern matches all of it.
   */
  public function testPageKinds(): void {
    $kinds = new PageKinds([
      ['id' => 'en', 'label' => 'English', 'pattern' => '/en(/.*)?'],
      ['id' => 'article', 'label' => 'Articles', 'pattern' => '/(en/)?articles/.+'],
      ['id' => 'broken', 'label' => 'Broken', 'pattern' => '/(unclosed'],
    ]);
    $this->assertSame('en', $kinds->classify('/en/articles/one'));
    $this->assertSame('article', $kinds->classify('/articles/one'));
    $this->assertSame(PageKinds::OTHER, $kinds->classify('/articles/'));
    $this->assertSame(PageKinds::OTHER, $kinds->classify('/x/articles/one'));
    $this->assertSame(PageKinds::OTHER, $kinds->classify('/(unclosed'));
    $this->assertNull(PageKinds::compile('/(unclosed'));
  }

  /**
   * Names fold to plain lower-case words.
   */
  public function testFold(): void {
    $this->assertSame('bogota d c', CityLocator::fold('Bogotá D.C.'));
    $this->assertSame('sao paulo', CityLocator::fold('São Paulo'));
    $this->assertSame('koln', CityLocator::fold('Köln'));
  }

  /**
   * A city is found in its own country only.
   */
  public function testLocate(): void {
    $path = tempnam(sys_get_temp_dir(), 'ga4r');
    file_put_contents($path, json_encode(['CO' => ['bogota' => [4.61, -74.08]]]));
    $cities = new CityLocator($path);
    $this->assertSame([4.61, -74.08], $cities->locate('co', 'Bogotá'));
    $this->assertNull($cities->locate('US', 'Bogota'));
    $this->assertNull((new CityLocator('/nonexistent'))->locate('CO', 'Bogota'));
    unlink($path);
  }

  /**
   * The bundled table holds the world's large cities.
   */
  public function testBundledTable(): void {
    $cities = CityLocator::bundled();
    [$lat, $lon] = $cities->locate('FR', 'Paris');
    $this->assertEqualsWithDelta(48.85, $lat, 0.1);
    $this->assertEqualsWithDelta(2.35, $lon, 0.1);
    $this->assertNotNull($cities->locate('JP', 'Tokyo'));
  }

}
