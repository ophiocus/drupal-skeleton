<?php

declare(strict_types=1);

namespace Drupal\Tests\ga4_report\Functional;

use Drupal\ga4_report\Report\CityLocator;
use Drupal\ga4_report\Report\Normalizer;
use Drupal\ga4_report\Source\AdSenseSource;
use Drupal\ga4_report\Source\CloudflareSource;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\ga4_report\Traits\ReportFixtureTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The report page and the settings form, as an editor sees them.
 */
#[Group('ga4_report')]
#[RunTestsInSeparateProcesses]
final class ReportPageTest extends BrowserTestBase {

  use ReportFixtureTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['ga4_report'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * The report is for those given it; there is nothing before a snapshot.
   */
  public function testAccessAndEmptyState(): void {
    $this->drupalGet('admin/reports/ga4');
    $this->assertSession()->statusCodeEquals(403);

    $this->drupalLogin($this->drupalCreateUser(['view ga4 report']));
    $this->drupalGet('admin/reports/ga4');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('No snapshot yet');
    $this->assertSession()->buttonNotExists('Refresh now');
  }

  /**
   * A stored snapshot is drawn without asking Google anything.
   */
  public function testSnapshotIsDrawn(): void {
    $settings = self::fixtureSettings();
    $this->config('ga4_report.settings')->setData($settings)->save();
    $table = self::fixtureCityTable();
    $snapshot = (new Normalizer(new CityLocator($table)))->normalize(self::fixtureReports(), $settings);
    unlink($table);
    $store = $this->container->get('ga4_report.store');
    $store->save('ga4', $snapshot, [
      'attempted' => 1767225600,
      'fetched' => 1767225600,
      'property' => '123456789',
      'start_date' => '2026-01-01',
      'end_date' => 'today',
      'reports' => 14,
    ]);
    $from = 1767312000;
    $store->save('cloudflare', CloudflareSource::normalize(self::fixtureCloudflare(), $from, $from + 180), [
      'fetched' => 1767225600,
      'zone' => str_repeat('c', 32),
      'days' => ['2026-01-01', '2026-01-02'],
    ]);
    $store->save('adsense', AdSenseSource::normalize(self::fixtureAdSense()), [
      'fetched' => 1767225600,
      'account' => 'accounts/pub-1234567890',
      'start_date' => '2026-01-01',
      'end_date' => '2026-01-02',
    ]);

    $this->drupalLogin($this->drupalCreateUser(['view ga4 report', 'refresh ga4 report']));
    $this->drupalGet('admin/reports/ga4');
    $session = $this->assertSession();
    $session->pageTextContains('Google Analytics (property 123456789, 2026-01-01 to today): snapshot taken');
    $session->pageTextContains('AdSense (accounts/pub-1234567890, 2026-01-01 to 2026-01-02): snapshot taken');
    $session->pageTextContains('Edge hit rate, 24 h');
    $session->pageTextContains('4,000.50 COP');
    $session->pageTextContains('Visits without internal traffic');
    $session->pageTextContains('40 %');
    $titles = [
      'Visits per day',
      'How visits arrive',
      'Where visitors are',
      'When they come',
      'Devices, systems, browsers',
      'New or returning, by language',
      'What they read',
      'Page views by kind of page',
      'First page of a visit, by channel',
      'What Analytics records',
      'Edge cache and visits, minute by minute',
      'Edge cache per day',
      'Ad earnings per day',
    ];
    foreach ($titles as $title) {
      $session->elementExists('xpath', "//h2[normalize-space()='$title']");
    }
    $session->elementsCount('css', '[data-ga4r-chart]', 12);
    $session->pageTextContains('/en/articles/two');
    // Colombia and the United States tie; the first reported is the plot's.
    $session->pageTextContains('1 of 1 cities in Colombia placed (0 visits not placed)');
    $session->pageTextContains('Springfield');
    $session->responseContains('"ga4Report"');
    $session->pageTextContains('2026-01-02 00:00');
    // The refresh button is there, and disabled without credentials.
    $session->buttonExists('Refresh now');
    $session->elementAttributeExists('css', '#edit-refresh', 'disabled');

    // A failure is shown above the snapshot it leaves in place.
    $store->recordFailure('ga4', 'No access to this property.', 1767229200);
    $this->drupalGet('admin/reports/ga4');
    $session->pageTextContains('failed, and its previous snapshot stays: No access to this property.');
    $session->pageTextContains('/en/articles/two');
  }

  /**
   * The settings form reads and writes the line lists.
   */
  public function testSettingsForm(): void {
    $this->drupalLogin($this->drupalCreateUser(['administer ga4 report']));
    $this->drupalGet('admin/config/services/ga4-report');
    $this->assertSession()->pageTextContains('Credentials: not named');
    $this->submitForm([
      'property_id' => 'G-ABC',
      'internal_traffic' => 'city: Springfield',
    ], 'Save configuration');
    $this->assertSession()->pageTextContains('The property ID is a number.');

    $this->submitForm([
      'property_id' => '123456789',
      'cloudflare_zone' => str_repeat('C', 32),
      'adsense_account' => 'ca-pub-1234567890',
      'start_date' => '30daysAgo',
      'internal_traffic' => "city: Springfield\ndeviceCategory: desktop",
      'page_kinds' => 'article | Articles | /articles/.+',
      'markers' => '2026-01-02 | Launch',
    ], 'Save configuration');
    $config = $this->config('ga4_report.settings');
    $this->assertSame('123456789', $config->get('property_id'));
    $this->assertSame(str_repeat('c', 32), $config->get('cloudflare_zone'));
    $this->assertSame('ca-pub-1234567890', $config->get('adsense_account'));
    $this->assertSame('30daysAgo', $config->get('start_date'));
    $this->assertSame(['desktop'], $config->get('internal_traffic')[1]['values']);
    $this->assertSame('Articles', $config->get('page_kinds')[0]['label']);
    $this->assertSame('Launch', $config->get('markers')[0]['label']);
  }

}
