<?php

declare(strict_types=1);

namespace Drupal\Tests\shop_delivery\Unit;

use Drupal\shop_delivery\GeoMath;
use Drupal\Tests\UnitTestCase;

/**
 * Distance and "inside a zone".
 *
 * @coversDefaultClass \Drupal\shop_delivery\GeoMath
 * @group shop_delivery
 */
final class GeoMathTest extends UnitTestCase {

  /**
   * A square around Chapinero with a hole cut in its middle, as GeoJSON.
   */
  private const SQUARE_WITH_HOLE = '{"type":"Feature","properties":{"name":"Chapinero"},"geometry":{"type":"Polygon","coordinates":[[[-74.08,4.63],[-74.04,4.63],[-74.04,4.67],[-74.08,4.67],[-74.08,4.63]],[[-74.065,4.645],[-74.055,4.645],[-74.055,4.655],[-74.065,4.655],[-74.065,4.645]]]}}';

  /**
   * Bogotá to Medellín is about 240 km in a straight line.
   *
   * @covers ::distanceKm
   */
  public function testDistance(): void {
    $bogota = ['lat' => 4.7110, 'lon' => -74.0721];
    $medellin = ['lat' => 6.2442, 'lon' => -75.5812];
    $this->assertEqualsWithDelta(237.0, GeoMath::distanceKm($bogota, $medellin), 3.0);
    $this->assertSame(0.0, GeoMath::distanceKm($bogota, $bogota));
  }

  /**
   * A point inside, outside, and in the hole.
   *
   * @covers ::parsePolygons
   * @covers ::inside
   */
  public function testInside(): void {
    $polygons = GeoMath::parsePolygons(self::SQUARE_WITH_HOLE);
    $this->assertNotNull($polygons);
    $this->assertTrue(GeoMath::inside(['lat' => 4.64, 'lon' => -74.07], $polygons));
    $this->assertFalse(GeoMath::inside(['lat' => 4.70, 'lon' => -74.07], $polygons));
    $this->assertFalse(GeoMath::inside(['lat' => 4.65, 'lon' => -74.06], $polygons), 'A point in the hole is outside.');
  }

  /**
   * Every GeoJSON shape a drawing tool exports, and text that is none.
   *
   * @covers ::parsePolygons
   */
  public function testParse(): void {
    $ring = [[-74.1, 4.6], [-74.0, 4.6], [-74.0, 4.7], [-74.1, 4.6]];
    $collection = json_encode([
      'type' => 'FeatureCollection',
      'features' => [
        ['type' => 'Feature', 'geometry' => ['type' => 'Point', 'coordinates' => [-74, 4.6]]],
        ['type' => 'Feature', 'geometry' => ['type' => 'MultiPolygon', 'coordinates' => [[$ring], [$ring]]]],
      ],
    ]);
    $this->assertCount(2, GeoMath::parsePolygons($collection));
    $this->assertCount(1, GeoMath::parsePolygons(json_encode(['type' => 'Polygon', 'coordinates' => [$ring]])));
    $this->assertNull(GeoMath::parsePolygons('not json'));
    $this->assertNull(GeoMath::parsePolygons('{"type":"Point","coordinates":[-74,4.6]}'));
    $this->assertNull(GeoMath::parsePolygons('{"type":"Polygon","coordinates":[[[-74,4.6],[-74,4.7]]]}'), 'Two corners are no ring.');
  }

}
