<?php

declare(strict_types=1);

namespace Drupal\shop_delivery;

/**
 * The delivery fee, from the Domicilio method's settings and two points.
 *
 * Pure: no Drupal, no entities, so every mode is a unit test. The shipping
 * method plugin hands it its configuration, the store's point (origin) and
 * the customer's pin (destination).
 *
 * Three modes, one of them active, all parametrized in the admin:
 * - flat: one fee for every delivery; needs no point at all.
 * - distance: a base fee plus a fee per kilometre in a straight line from
 *   the store, rounded up, refused past a maximum distance.
 * - zones: polygons with a fee each; the first zone holding the pin wins,
 *   and a pin outside every zone gets no delivery.
 */
final class FeeCalculator {

  public const MODES = ['flat', 'distance', 'zones'];

  /**
   * Quotes one delivery.
   *
   * @param array<string, mixed> $config
   *   The Domicilio plugin's configuration.
   * @param array{lat: float, lon: float}|null $origin
   *   The store's point, if it has one.
   * @param array{lat: float, lon: float}|null $destination
   *   The customer's pin, if there is one yet.
   *
   * @return array{amount: array{number: string, currency_code: string}|null, detail: string}
   *   The fee (NULL: this point gets no delivery) and a short text for the
   *   customer: the zone's name, the distance, or why there is no fee.
   */
  public static function quote(array $config, ?array $origin, ?array $destination): array {
    $mode = in_array($config['fee_mode'] ?? '', self::MODES, TRUE) ? $config['fee_mode'] : 'flat';
    return match ($mode) {
      'flat' => self::flat($config),
      'distance' => self::distance($config, $origin, $destination),
      'zones' => self::zones($config, $destination),
    };
  }

  /**
   * The flat fee.
   */
  private static function flat(array $config): array {
    $amount = self::price($config['flat_amount'] ?? NULL);
    return [
      'amount' => $amount,
      'detail' => $amount ? '' : 'Tarifa fija sin configurar.',
    ];
  }

  /**
   * Base plus per kilometre, rounded up, within the maximum distance.
   */
  private static function distance(array $config, ?array $origin, ?array $destination): array {
    if ($origin === NULL) {
      return ['amount' => NULL, 'detail' => 'La tienda aún no tiene ubicación.'];
    }
    if ($destination === NULL) {
      return ['amount' => NULL, 'detail' => 'Ubica tu dirección para calcular el domicilio.'];
    }
    $base = self::price($config['distance_base'] ?? NULL);
    $per_km = self::price($config['distance_per_km'] ?? NULL);
    if ($base === NULL || $per_km === NULL) {
      return ['amount' => NULL, 'detail' => 'Tarifa por distancia sin configurar.'];
    }
    $km = GeoMath::distanceKm($origin, $destination);
    $max = (float) ($config['distance_max_km'] ?? 0);
    $shown = number_format($km, 1, ',', '.') . ' km';
    if ($max > 0 && $km > $max) {
      return [
        'amount' => NULL,
        'detail' => 'Fuera de cobertura: ' . $shown . ' (máximo ' . number_format($max, 1, ',', '.') . ' km).',
      ];
    }
    $total = (float) $base['number'] + (float) $per_km['number'] * $km;
    return [
      'amount' => [
        'number' => self::round($total, (int) ($config['round_to'] ?? 0)),
        'currency_code' => $base['currency_code'],
      ],
      'detail' => $shown,
    ];
  }

  /**
   * The first zone holding the pin.
   */
  private static function zones(array $config, ?array $destination): array {
    if ($destination === NULL) {
      return ['amount' => NULL, 'detail' => 'Ubica tu dirección para calcular el domicilio.'];
    }
    foreach ((array) ($config['zones'] ?? []) as $zone) {
      $polygons = GeoMath::parsePolygons((string) ($zone['geojson'] ?? ''));
      $amount = self::price($zone['amount'] ?? NULL);
      if ($polygons !== NULL && $amount !== NULL && GeoMath::inside($destination, $polygons)) {
        return ['amount' => $amount, 'detail' => (string) ($zone['name'] ?? '')];
      }
    }
    return ['amount' => NULL, 'detail' => 'Fuera de nuestras zonas de domicilio.'];
  }

  /**
   * A configured price, or NULL when it is not one.
   *
   * @return array{number: string, currency_code: string}|null
   *   The price.
   */
  private static function price(mixed $value): ?array {
    if (!is_array($value) || !isset($value['number'], $value['currency_code'])
      || !is_numeric($value['number']) || $value['currency_code'] === '') {
      return NULL;
    }
    return ['number' => (string) $value['number'], 'currency_code' => (string) $value['currency_code']];
  }

  /**
   * Rounds up to a multiple of $step, or to cents when $step is 0.
   */
  private static function round(float $amount, int $step): string {
    if ($step > 0) {
      return (string) (int) (ceil($amount / $step - 1e-9) * $step);
    }
    return number_format(ceil($amount * 100 - 1e-9) / 100, 2, '.', '');
  }

}
