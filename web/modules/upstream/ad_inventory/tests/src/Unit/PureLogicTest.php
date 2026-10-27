<?php

declare(strict_types=1);

namespace Drupal\Tests\ad_inventory\Unit;

use Drupal\ad_inventory\BeaconPayload;
use Drupal\ad_inventory\Calendar;
use Drupal\ad_inventory\FormatStyles;
use Drupal\ad_inventory\Overrides;
use Drupal\ad_inventory\Png;
use Drupal\ad_inventory\StatusChecker;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The beacon body, per-page controls, format CSS, ads.txt lines, days, PNG.
 */
#[Group('ad_inventory')]
#[RunTestsInSeparateProcesses]
final class PureLogicTest extends UnitTestCase {

  /**
   * Only well-formed, unique pairs survive; network unfilled only.
   */
  public function testBeaconPayload(): void {
    $body = json_encode([
      'v' => [['top', '12'], ['top', 12], ['top', 'provider:google'], ['TOP', '1'], ['top', 'drop table'], ['top'], 'x'],
      'u' => [['top', 'provider:google'], ['top', '12']],
      'ip' => '203.0.113.9',
    ]);
    $this->assertSame([
      'views' => [['top', '12'], ['top', 'provider:google']],
      'unfilled' => [['top', 'provider:google']],
    ], BeaconPayload::parse($body));
    $this->assertSame(['views' => [], 'unfilled' => []], BeaconPayload::parse('not json'));
    $many = json_encode(['v' => array_map(static fn(int $i): array => ['s' . $i, (string) $i], range(1, 80))]);
    $this->assertCount(BeaconPayload::MAX_PAIRS, BeaconPayload::parse($many)['views']);
  }

  /**
   * Controls keep only what differs, and only allowed formats.
   */
  public function testOverrides(): void {
    $slots = [
      'top' => [
        'desktop' => 'leaderboard',
        'phone' => 'mobile_banner',
        'desktop_options' => ['leaderboard', 'rectangle'],
        'phone_options' => ['mobile_banner'],
        'repeatable' => FALSE,
      ],
      'band' => [
        'desktop' => 'leaderboard',
        'phone' => 'rectangle',
        'desktop_options' => ['leaderboard'],
        'phone_options' => ['rectangle'],
        'repeatable' => TRUE,
      ],
      'side' => [
        'desktop' => 'rectangle',
        'phone' => 'rectangle',
        'desktop_options' => ['rectangle'],
        'phone_options' => ['rectangle'],
        'repeatable' => FALSE,
      ],
    ];
    $input = [
      'top' => ['show' => 1, 'format' => 'rectangle', 'phone_format' => 'skyscraper'],
      'band' => ['show' => 1, 'count' => '2'],
      'side' => ['show' => 0],
    ];
    $this->assertSame([
      'top' => ['format' => 'rectangle'],
      'band' => ['count' => 2],
      'side' => ['off' => TRUE],
    ], Overrides::normalize($input, $slots));
    $this->assertSame([], Overrides::normalize(['top' => ['show' => 1, 'format' => 'leaderboard', 'count' => '3']], $slots));

    $this->assertSame(['off' => FALSE, 'desktop' => 'rectangle', 'phone' => 'mobile_banner', 'count' => NULL], Overrides::apply(['format' => 'rectangle'], 'leaderboard', 'mobile_banner'));
    $this->assertSame(['off' => TRUE, 'desktop' => 'leaderboard', 'phone' => 'mobile_banner', 'count' => 0], Overrides::apply([
      'off' => TRUE,
      'count' => -2,
    ], 'leaderboard', 'mobile_banner'));
  }

  /**
   * The box rules and the network unit sizes per breakpoint.
   */
  public function testFormatStyles(): void {
    $css = FormatStyles::css([
      'leaderboard' => [
        'width' => 728,
        'height' => 90,
        'network' => [
          ['min_width' => 0, 'width' => 468, 'height' => 60],
          ['min_width' => 728, 'width' => 728, 'height' => 90],
        ],
      ],
      'mobile_banner' => [
        'width' => 320,
        'height' => 100,
        'network' => [['min_width' => 0, 'width' => 320, 'height' => 100]],
      ],
      'bad id' => ['width' => 1, 'height' => 1, 'network' => []],
    ], 768);
    $this->assertStringContainsString('.ad-slot[data-ad-desktop="leaderboard"] .ad-slot__box{max-width:728px;aspect-ratio:728/90}', $css);
    $this->assertStringContainsString('@media (max-width: 767.98px){.ad-slot[data-ad-phone="mobile_banner"] .ad-slot__box{max-width:320px;aspect-ratio:320/100}}', $css);
    $this->assertStringContainsString('@media (min-width: 768px){.ad-slot[data-ad-desktop="leaderboard"] .ad-slot__unit{width:728px;height:90px}}', $css);
    $this->assertStringContainsString('@media (max-width: 767.98px){.ad-slot[data-ad-phone="mobile_banner"] .ad-slot__unit{width:320px;height:100px}}', $css);
    $this->assertStringContainsString('@media (min-width: 728px) and (max-width: 767.98px){.ad-slot[data-ad-phone="leaderboard"] .ad-slot__unit{width:728px;height:90px}}', $css);
    $this->assertStringNotContainsString('bad id', $css);
    $this->assertStringNotContainsString('max-width: ', FormatStyles::css([
      'email' => ['width' => 600, 'height' => 200, 'network' => []],
    ], 0));
  }

  /**
   * Ads.txt lines compare without regard to case or spacing.
   */
  public function testAdsTxtLines(): void {
    $file = "# comment\ngoogle.com, pub-1, DIRECT, f08c47fec0942fa0\nother.example, 9, RESELLER";
    $this->assertTrue(StatusChecker::adsTxtHas($file, 'GOOGLE.COM,pub-1,DIRECT,f08c47fec0942fa0'));
    $this->assertFalse(StatusChecker::adsTxtHas($file, 'google.com, pub-2, DIRECT, f08c47fec0942fa0'));
    $this->assertFalse(StatusChecker::adsTxtHas('', 'google.com, pub-1, DIRECT'));
  }

  /**
   * Days.
   */
  public function testDays(): void {
    $this->assertSame('2026-03-01', Calendar::shift('2026-02-28', 1));
    $this->assertSame('2025-12-31', Calendar::shift('2026-01-01', -1));
    $this->assertTrue(Calendar::isDay('2026-10-26'));
    $this->assertFalse(Calendar::isDay('2026-02-30'));
    $this->assertFalse(Calendar::isDay('26-10-2026'));
  }

  /**
   * The blank email image is a real PNG of the asked size.
   */
  public function testPng(): void {
    $info = getimagesizefromstring(Png::solid(1200, 400));
    $this->assertSame([1200, 400], [$info[0], $info[1]]);
    $this->assertSame('image/png', $info['mime']);
  }

}
