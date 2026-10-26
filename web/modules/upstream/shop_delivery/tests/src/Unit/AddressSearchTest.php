<?php

declare(strict_types=1);

namespace Drupal\Tests\shop_delivery\Unit;

use Drupal\shop_delivery\AddressSearch;
use Drupal\maper_nominatim\GeocoderInterface;
use Drupal\Tests\UnitTestCase;

/**
 * The address search: off without a geocoder, and honest about precision.
 *
 * @coversDefaultClass \Drupal\shop_delivery\AddressSearch
 * @group shop_delivery
 */
final class AddressSearchTest extends UnitTestCase {

  /**
   * Without maper_nominatim there is no search.
   *
   * @covers ::available
   * @covers ::search
   */
  public function testOff(): void {
    $search = new AddressSearch(NULL);
    $this->assertFalse($search->available());
    $this->expectException(\RuntimeException::class);
    $search->search('Calle 67 # 7-35, Bogotá');
  }

  /**
   * A building is a door; a road or a neighbourhood is only near one.
   *
   * @covers ::search
   */
  public function testPrecision(): void {
    $geocoder = new class() implements GeocoderInterface {

      /**
       * {@inheritdoc}
       */
      public function search(string $query): array {
        // As the public Nominatim answers: a street is category "highway"
        // with its road class ("residential") as the address type.
        $hit = static fn (string $category, string $type): array => [
          'lat' => 4.65,
          'lon' => -74.06,
          'name' => '',
          'display' => $category . '/' . $type,
          'category' => $category,
          'type' => '',
          'addresstype' => $type,
          'osm' => '',
          'address' => [],
        ];
        return [
          $hit('building', 'building'),
          $hit('highway', 'residential'),
          $hit('place', 'neighbourhood'),
          $hit('amenity', 'amenity'),
          $hit('boundary', 'city'),
          $hit('shop', 'shop'),
        ];
      }

    };
    $hits = (new AddressSearch($geocoder))->search('Carrera 7 # 40-62, Bogotá');
    $this->assertCount(5, $hits, 'At most five places.');
    $this->assertSame(
      [
        'building/building' => TRUE,
        'highway/residential' => FALSE,
        'place/neighbourhood' => FALSE,
        'amenity/amenity' => TRUE,
        'boundary/city' => FALSE,
      ],
      array_column($hits, 'precise', 'display'),
    );
  }

}
