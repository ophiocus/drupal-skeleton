<?php

declare(strict_types=1);

namespace Drupal\shop_delivery;

/**
 * The little geometry a delivery fee needs: distance and "inside a zone".
 *
 * Coordinates follow two conventions on purpose, each where it is native:
 * a point is ['lat' => …, 'lon' => …] (maper's order, latitude first),
 * and a polygon is GeoJSON, whose positions are [lon, lat]. Zones are
 * drawn in geojson.io or any GIS tool and pasted as they come out.
 */
final class GeoMath {

  /**
   * The Earth's mean radius in kilometres (IUGG).
   */
  public const EARTH_RADIUS_KM = 6371.0088;

  /**
   * Great-circle distance between two points, in kilometres.
   *
   * Straight line, not road distance: the fee is a fair approximation
   * the shop parametrizes, not a route.
   *
   * @param array{lat: float, lon: float} $a
   *   One point.
   * @param array{lat: float, lon: float} $b
   *   The other.
   */
  public static function distanceKm(array $a, array $b): float {
    $lat1 = deg2rad((float) $a['lat']);
    $lat2 = deg2rad((float) $b['lat']);
    $dlat = $lat2 - $lat1;
    $dlon = deg2rad((float) $b['lon'] - (float) $a['lon']);
    $h = sin($dlat / 2) ** 2 + cos($lat1) * cos($lat2) * sin($dlon / 2) ** 2;
    return 2 * self::EARTH_RADIUS_KM * asin(min(1.0, sqrt($h)));
  }

  /**
   * Reads a zone's shape from GeoJSON text.
   *
   * Accepts a Polygon or MultiPolygon geometry, a Feature carrying one, or
   * a FeatureCollection (every polygonal feature in it counts as the zone).
   *
   * @return list<list<list<array{0: float, 1: float}>>>|null
   *   Polygons, each a list of rings (the first the outline, the rest
   *   holes), each ring a list of [lon, lat]; NULL when the text holds no
   *   polygon.
   */
  public static function parsePolygons(string $json): ?array {
    $data = json_decode(trim($json), TRUE);
    if (!is_array($data)) {
      return NULL;
    }
    $polygons = [];
    foreach (self::geometries($data) as $geometry) {
      $type = $geometry['type'] ?? '';
      $coordinates = $geometry['coordinates'] ?? NULL;
      if (!is_array($coordinates)) {
        continue;
      }
      $list = match ($type) {
        'Polygon' => [$coordinates],
        'MultiPolygon' => $coordinates,
        default => [],
      };
      foreach ($list as $rings) {
        $polygon = self::rings($rings);
        if ($polygon !== NULL) {
          $polygons[] = $polygon;
        }
      }
    }
    return $polygons ?: NULL;
  }

  /**
   * Whether a point lies inside any of the polygons (holes excluded).
   *
   * @param array{lat: float, lon: float} $point
   *   The point.
   * @param list<list<list<array{0: float, 1: float}>>> $polygons
   *   As parsePolygons() returns them.
   */
  public static function inside(array $point, array $polygons): bool {
    $x = (float) $point['lon'];
    $y = (float) $point['lat'];
    foreach ($polygons as $rings) {
      if (!self::inRing($x, $y, $rings[0])) {
        continue;
      }
      $in_hole = FALSE;
      foreach (array_slice($rings, 1) as $hole) {
        if (self::inRing($x, $y, $hole)) {
          $in_hole = TRUE;
          break;
        }
      }
      if (!$in_hole) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * The geometries a GeoJSON object carries.
   *
   * @return list<array<string, mixed>>
   *   Geometry objects.
   */
  private static function geometries(array $data): array {
    $type = $data['type'] ?? '';
    if ($type === 'Feature') {
      return is_array($data['geometry'] ?? NULL) ? [$data['geometry']] : [];
    }
    if ($type !== 'FeatureCollection') {
      return [$data];
    }
    $geometries = [];
    foreach ((array) ($data['features'] ?? []) as $feature) {
      if (is_array($feature)) {
        array_push($geometries, ...self::geometries($feature));
      }
    }
    return $geometries;
  }

  /**
   * A polygon's rings as numbers, or NULL when the outline is no ring.
   *
   * @return list<list<array{0: float, 1: float}>>|null
   *   The rings.
   */
  private static function rings(mixed $rings): ?array {
    if (!is_array($rings)) {
      return NULL;
    }
    $out = [];
    foreach ($rings as $ring) {
      $positions = [];
      foreach (is_array($ring) ? $ring : [] as $position) {
        if (is_array($position) && isset($position[0], $position[1])
          && is_numeric($position[0]) && is_numeric($position[1])) {
          $positions[] = [(float) $position[0], (float) $position[1]];
        }
      }
      // A ring needs three distinct corners; GeoJSON closes it with a
      // fourth position equal to the first.
      if (count($positions) >= 3) {
        $out[] = $positions;
      }
    }
    return $out ?: NULL;
  }

  /**
   * Ray casting: whether (x, y) is inside one ring.
   *
   * @param float $x
   *   The longitude.
   * @param float $y
   *   The latitude.
   * @param list<array{0: float, 1: float}> $ring
   *   The ring's [lon, lat] positions.
   */
  private static function inRing(float $x, float $y, array $ring): bool {
    $inside = FALSE;
    $n = count($ring);
    for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
      [$xi, $yi] = $ring[$i];
      [$xj, $yj] = $ring[$j];
      if ((($yi > $y) !== ($yj > $y))
        && ($x < ($xj - $xi) * ($y - $yi) / ($yj - $yi) + $xi)) {
        $inside = !$inside;
      }
    }
    return $inside;
  }

}
