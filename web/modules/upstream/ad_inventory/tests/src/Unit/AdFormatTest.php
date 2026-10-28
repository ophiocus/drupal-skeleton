<?php

declare(strict_types=1);

namespace Drupal\Tests\ad_inventory\Unit;

use Drupal\ad_inventory\Controller\CountController;
use Drupal\ad_inventory\Entity\AdFormat;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * A rendition fits its format; a click only follows a safe address.
 */
#[Group('ad_inventory')]
final class AdFormatTest extends UnitTestCase {

  /**
   * Pixel sizes against the 728×90 leaderboard.
   *
   * @return array<string, array{int, int, bool}>
   *   Width, height, fits.
   */
  public static function sizes(): array {
    return [
      'twice' => [1456, 180, TRUE],
      'exact' => [728, 90, TRUE],
      'within 1%' => [1456, 179, TRUE],
      'off by 5%' => [1456, 171, FALSE],
      'too small' => [364, 45, FALSE],
      'square' => [600, 600, FALSE],
    ];
  }

  /**
   * The proportion must match within 1% and the image be at least 1x.
   */
  #[DataProvider('sizes')]
  public function testFits(int $width, int $height, bool $fits): void {
    $format = new AdFormat(['id' => 'leaderboard', 'label' => 'Leaderboard', 'width' => 728, 'height' => 90], 'ad_format');
    $this->assertSame($fits, $format->fits($width, $height));
    $this->assertSame('728×90', $format->size());
  }

  /**
   * Targets: https or a site path, never a protocol-relative or script URL.
   */
  public function testSafeTarget(): void {
    $this->assertSame('/contact', CountController::safeTarget('/contact'));
    $this->assertSame('https://example.com/x', CountController::safeTarget(' https://example.com/x '));
    $this->assertNull(CountController::safeTarget('//evil.example'));
    $this->assertNull(CountController::safeTarget('javascript:alert(1)'));
    $this->assertNull(CountController::safeTarget('example.com'));
  }

}
