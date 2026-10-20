<?php

declare(strict_types=1);

namespace Drupal\Tests\ga4_report\Unit;

use Drupal\ga4_report\Form\SettingsForm;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * The settings form's one-entry-per-line lists.
 */
#[Group('ga4_report')]
final class SettingsLinesTest extends UnitTestCase {

  /**
   * Lists survive a round trip through their text form.
   */
  public function testRoundTrip(): void {
    $conditions = SettingsForm::parseConditions("city: Springfield, Shelbyville\n\n  deviceCategory : desktop ");
    $this->assertSame([
      ['dimension' => 'city', 'values' => ['Springfield', 'Shelbyville']],
      ['dimension' => 'deviceCategory', 'values' => ['desktop']],
    ], $conditions);
    $this->assertSame($conditions, SettingsForm::parseConditions(SettingsForm::formatConditions($conditions)));

    $kinds = SettingsForm::parseKinds("article | Articles | /(en/)?articles/.+\nhome | Home | /(en/?)?");
    $this->assertSame('/(en/)?articles/.+', $kinds[0]['pattern']);
    $this->assertSame($kinds, SettingsForm::parseKinds(SettingsForm::formatKinds($kinds)));

    $markers = SettingsForm::parseMarkers('2026-01-02 | Launch | day one');
    $this->assertSame([['date' => '2026-01-02', 'label' => 'Launch | day one']], $markers);
    $this->assertSame($markers, SettingsForm::parseMarkers(SettingsForm::formatMarkers($markers)));
  }

  /**
   * A malformed line names its line number.
   */
  #[DataProvider('badLines')]
  public function testRejects(string $method, string $text, string $message): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage($message);
    SettingsForm::$method($text);
  }

  /**
   * Malformed lines.
   */
  public static function badLines(): array {
    return [
      'condition without values' => ['parseConditions', "city: A\nbrowser", 'Line 2'],
      'kind without pattern' => ['parseKinds', 'article | Articles', 'Line 1'],
      'kind with a capital id' => ['parseKinds', 'Article | Articles | /a', 'Line 1'],
      'kind named other' => ['parseKinds', 'other | Other | /a', 'taken'],
      'kind twice' => ['parseKinds', "a | A | /a\na | B | /b", 'Line 2'],
      'kind with a broken pattern' => ['parseKinds', 'a | A | /(a', 'not a valid'],
      'marker without a date' => ['parseMarkers', 'soon | Launch', 'Line 1'],
    ];
  }

  /**
   * The dates the Data API takes.
   */
  public function testGaDates(): void {
    foreach (['2026-01-31', '90daysAgo', 'today', 'yesterday'] as $ok) {
      $this->assertTrue(SettingsForm::isGaDate($ok), $ok);
    }
    foreach (['', 'tomorrow', '31/01/2026', '90 days ago'] as $bad) {
      $this->assertFalse(SettingsForm::isGaDate($bad), $bad);
    }
  }

}
