<?php

declare(strict_types=1);

namespace Drupal\Tests\shop_delivery\Unit;

use Drupal\shop_delivery\FeeCalculator;
use Drupal\Tests\UnitTestCase;

/**
 * The three fee modes, and every way a quote is refused.
 *
 * @coversDefaultClass \Drupal\shop_delivery\FeeCalculator
 * @group shop_delivery
 */
final class FeeCalculatorTest extends UnitTestCase {

  private const STORE = ['lat' => 4.6486, 'lon' => -74.0628];

  /**
   * About 1.03 km north-east of the store.
   */
  private const NEAR = ['lat' => 4.6553125, 'lon' => -74.0567812];

  /**
   * About 25 km away.
   */
  private const FAR = ['lat' => 4.86, 'lon' => -74.03];

  /**
   * The settings the tests vary.
   */
  private function config(array $overrides = []): array {
    return $overrides + [
      'fee_mode' => 'flat',
      'flat_amount' => ['number' => '5000', 'currency_code' => 'COP'],
      'distance_base' => ['number' => '3000', 'currency_code' => 'COP'],
      'distance_per_km' => ['number' => '1000', 'currency_code' => 'COP'],
      'distance_max_km' => 8,
      'round_to' => 100,
      'zones' => [],
    ];
  }

  /**
   * Flat needs no point at all.
   *
   * @covers ::quote
   */
  public function testFlat(): void {
    $quote = FeeCalculator::quote($this->config(), NULL, NULL);
    $this->assertSame(['number' => '5000', 'currency_code' => 'COP'], $quote['amount']);

    $unset = FeeCalculator::quote($this->config(['flat_amount' => NULL]), NULL, NULL);
    $this->assertNull($unset['amount']);
  }

  /**
   * Base plus per kilometre, rounded up; refused past the maximum.
   *
   * @covers ::quote
   */
  public function testDistance(): void {
    $config = $this->config(['fee_mode' => 'distance']);
    $quote = FeeCalculator::quote($config, self::STORE, self::NEAR);
    // 3000 + 1000 × 1.03 km = 4030, rounded up to the next 100.
    $this->assertSame(['number' => '4100', 'currency_code' => 'COP'], $quote['amount']);
    $this->assertSame('1,0 km', $quote['detail']);

    $unrounded = FeeCalculator::quote($this->config(['fee_mode' => 'distance', 'round_to' => 0]), self::STORE, self::NEAR);
    $this->assertMatchesRegularExpression('/^40\d\d\.\d\d$/', $unrounded['amount']['number']);

    $this->assertNull(FeeCalculator::quote($config, self::STORE, self::FAR)['amount'], 'Past the maximum distance.');
    $this->assertNotNull(FeeCalculator::quote($this->config(['fee_mode' => 'distance', 'distance_max_km' => 0]), self::STORE, self::FAR)['amount'], '0 is no maximum.');
    $this->assertNull(FeeCalculator::quote($config, NULL, self::NEAR)['amount'], 'No store point.');
    $this->assertNull(FeeCalculator::quote($config, self::STORE, NULL)['amount'], 'No pin.');
  }

  /**
   * The first zone holding the pin wins; outside every zone, no delivery.
   *
   * @covers ::quote
   */
  public function testZones(): void {
    $square = static fn (float $w, float $s, float $e, float $n): string => json_encode([
      'type' => 'Polygon',
      'coordinates' => [[[$w, $s], [$e, $s], [$e, $n], [$w, $n], [$w, $s]]],
    ]);
    $zone = static fn (string $name, string $fee, string $geojson): array => [
      'name' => $name,
      'amount' => ['number' => $fee, 'currency_code' => 'COP'],
      'geojson' => $geojson,
    ];
    $config = $this->config([
      'fee_mode' => 'zones',
      'zones' => [
        $zone('Chapinero', '4000', $square(-74.07, 4.64, -74.05, 4.66)),
        $zone('Norte', '7000', $square(-74.10, 4.60, -74.00, 4.90)),
      ],
    ]);
    $near = FeeCalculator::quote($config, NULL, self::NEAR);
    $this->assertSame('4000', $near['amount']['number']);
    $this->assertSame('Chapinero', $near['detail']);

    $far = FeeCalculator::quote($config, NULL, self::FAR);
    $this->assertSame('7000', $far['amount']['number']);

    $this->assertNull(FeeCalculator::quote($config, NULL, ['lat' => 6.24, 'lon' => -75.58])['amount'], 'Outside every zone.');
    $this->assertNull(FeeCalculator::quote($config, NULL, NULL)['amount'], 'No pin.');
  }

  /**
   * An unknown mode falls back to flat rather than refusing every order.
   *
   * @covers ::quote
   */
  public function testUnknownMode(): void {
    $this->assertSame('5000', FeeCalculator::quote($this->config(['fee_mode' => 'teleport']), NULL, NULL)['amount']['number']);
  }

}
