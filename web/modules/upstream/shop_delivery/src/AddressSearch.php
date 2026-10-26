<?php

declare(strict_types=1);

namespace Drupal\shop_delivery;

use Drupal\maper_nominatim\GeocoderInterface;

/**
 * The address search behind the pin, when maper_nominatim can answer.
 *
 * EXPERIMENTAL, like the maper_nominatim module it asks: off unless that
 * module is enabled and switched on (its policy acknowledged). Answers are
 * trimmed to what the pin needs, and flagged when they are only a
 * neighbourhood or a town rather than a door.
 */
final class AddressSearch {

  /**
   * Address types too coarse to be a door: the customer must adjust.
   */
  private const COARSE = [
    'country', 'state', 'region', 'province', 'county', 'municipality',
    'city', 'town', 'village', 'hamlet', 'city_district', 'district',
    'borough', 'suburb', 'quarter', 'neighbourhood', 'postcode', 'road',
  ];

  /**
   * OpenStreetMap categories that are never a door.
   *
   * A street comes back as category "highway" with its road class as the
   * address type ("residential", "tertiary"), so the category is what
   * gives it away. A Colombian address names a street and a cross street
   * ("Carrera 7 # 40-62"); OpenStreetMap seldom has the house, so the
   * answer is often some stretch of "Carrera 7", kilometres away.
   */
  private const NOT_A_DOOR = ['highway', 'boundary', 'place', 'landuse', 'natural', 'waterway', 'railway'];

  public function __construct(
    private readonly ?GeocoderInterface $geocoder = NULL,
  ) {}

  /**
   * Whether a search can be offered now.
   */
  public function available(): bool {
    return $this->geocoder !== NULL
      && (!method_exists($this->geocoder, 'refusal') || $this->geocoder->refusal() === NULL);
  }

  /**
   * Up to five places for one address, best first.
   *
   * @return list<array{lat: float, lon: float, display: string, precise: bool}>
   *   The places.
   *
   * @throws \RuntimeException
   *   When the search may not ask, or its service did not answer.
   */
  public function search(string $query): array {
    if (!$this->available()) {
      throw new \RuntimeException('The address search is switched off.');
    }
    $out = [];
    foreach (array_slice($this->geocoder->search($query), 0, 5) as $hit) {
      $out[] = [
        'lat' => (float) $hit['lat'],
        'lon' => (float) $hit['lon'],
        'display' => (string) ($hit['display'] ?? ''),
        'precise' => !in_array((string) ($hit['addresstype'] ?? ''), self::COARSE, TRUE)
        && !in_array((string) ($hit['category'] ?? ''), self::NOT_A_DOOR, TRUE),
      ];
    }
    return $out;
  }

}
