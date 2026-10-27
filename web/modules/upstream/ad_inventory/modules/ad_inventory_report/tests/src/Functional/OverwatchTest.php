<?php

declare(strict_types=1);

namespace Drupal\Tests\ad_inventory_report\Functional;

use Drupal\ad_inventory\Entity\AdProvider;
use Drupal\Tests\ad_inventory\Traits\AdInventoryTestTrait;
use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The Marketeer overwatch reads every tab, exports, and edits nothing.
 */
#[Group('ad_inventory')]
#[RunTestsInSeparateProcesses]
final class OverwatchTest extends BrowserTestBase {

  use AdInventoryTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'ad_inventory',
    'ad_inventory_report',
    'ad_inventory_test',
    'toolbar',
    'page_cache',
    'block',
    'field',
    'media',
    'image',
    'file',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * The tabs.
   */
  private const TABS = [
    '/admin/reports/ad-inventory',
    '/admin/reports/ad-inventory/inventory',
    '/admin/reports/ad-inventory/performance',
    '/admin/reports/ad-inventory/fill',
    '/admin/reports/ad-inventory/calendar',
    '/admin/reports/ad-inventory/providers',
    '/admin/reports/ad-inventory/email',
    '/admin/reports/ad-inventory/health',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->config('system.performance')->set('cache.page.max_age', 300)->save();
    $this->config('ad_inventory.settings')->set('timezone', 'UTC')->save();
  }

  /**
   * A Marketeer overwatch account.
   */
  private function overwatch(): void {
    $account = $this->drupalCreateUser();
    $account->addRole('ad_inventory_overwatch');
    $account->save();
    $this->drupalLogin($account);
  }

  /**
   * Every tab and every CSV answers; no edit, approve or stop route does.
   */
  public function testOverwatchReadsAndChangesNothing(): void {
    $today = gmdate('Y-m-d');
    $slot = $this->slot('top');
    $this->slot('mail', ['channel' => 'email', 'desktop_format' => 'email', 'phone_format' => '']);
    $creative = $this->creative(['leaderboard', 'mobile_banner', 'email'], [
      'label' => 'Spring sale',
      'advertiser_name' => 'A shop',
    ]);
    $booking = $this->booking($creative, ['top'], $today, $today);
    $draft = $this->booking($creative, ['top'], $today, $today, 'draft', ['label' => 'Waiting']);
    $provider = AdProvider::create([
      'id' => 'google',
      'label' => 'AdSense',
      'plugin' => 'adsense',
      'settings' => ['verification' => FALSE],
    ]);
    $provider->save();
    $counter = $this->container->get('ad_inventory.counter');
    $counter->add($today, 'top', (string) $creative->id(), 'views', 10);
    $counter->add($today, 'top', (string) $creative->id(), 'clicks', 1);
    $this->container->get('ad_inventory_report.rollup')->run();

    // Anonymous: nothing.
    foreach (self::TABS as $path) {
      $this->drupalGet($path);
      $this->assertSession()->statusCodeEquals(403);
    }
    $this->drupalGet('/admin/reports/ad-inventory/overview/csv/daily');
    $this->assertSession()->statusCodeEquals(403);

    $this->overwatch();
    foreach (self::TABS as $path) {
      $this->drupalGet($path);
      $this->assertSession()->statusCodeEquals(200);
    }
    $this->drupalGet('/admin/reports/ad-inventory');
    $this->assertSession()->pageTextContains('Viewed impressions');
    $this->assertSession()->pageTextContains('Spring sale');
    $this->assertSession()->pageTextContains('no consent rate can be measured');
    // The Inventory tab opens on the space grid: desktop and phone per slot,
    // each format boxed to its ratio, "none" where a device has no format.
    $this->drupalGet('/admin/reports/ad-inventory/inventory');
    $first = '(//div[contains(concat(" ", @class, " "), " air-table ")])[1]';
    $this->assertSession()->elementTextEquals('xpath', $first . '/h2', 'Spaces');
    $this->assertSession()->elementTextContains('xpath', $first . '//tbody', 'none');
    $this->assertSame(3, substr_count($this->getSession()->getPage()->getContent(), 'class="air-ratio"'));
    $this->drupalGet('/admin/reports/ad-inventory/inventory/csv/spaces');
    $this->assertSession()->responseContains('"page group",slot,desktop,phone,pages,"filled by"');
    $this->drupalGet('/admin/reports/ad-inventory/calendar');
    $this->assertSession()->pageTextContains('booked: A shop – Spring sale');
    $this->assertSession()->pageTextContains('Waiting');
    $this->drupalGet('/admin/reports/ad-inventory/providers');
    $this->assertSession()->pageTextContains('missing');

    $this->drupalGet('/admin/reports/ad-inventory/performance/csv/slots', ['query' => ['range' => '7d']]);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseHeaderContains('Content-Type', 'text/csv');
    $this->assertSession()->responseContains('Top,10,1,10.00%');
    $this->drupalGet('/admin/reports/ad-inventory/overview/csv/nope');
    $this->assertSession()->statusCodeEquals(404);

    $forbidden = [
      $slot->toUrl('edit-form'),
      $slot->toUrl('delete-form'),
      '/admin/structure/ad-inventory/slots/add',
      '/admin/structure/ad-inventory/formats/leaderboard',
      '/admin/structure/ad-inventory/formats/add',
      $provider->toUrl('edit-form'),
      '/admin/structure/ad-inventory/providers/add',
      $creative->toUrl('edit-form'),
      '/admin/content/ad-creative/add',
      $booking->toUrl('edit-form'),
      '/admin/content/ad-booking/add',
      $draft->toUrl('approve-form'),
      $booking->toUrl('stop-form'),
      $booking->toUrl('delete-form'),
      '/admin/config/services/ad-inventory',
      '/admin/config/services/ad-inventory/status',
      '/admin/config/services/ad-inventory/report',
    ];
    foreach ($forbidden as $path) {
      $this->drupalGet($path);
      $this->assertSession()->statusCodeEquals(403);
    }
  }

  /**
   * With no data and no provider, every tab explains itself.
   */
  public function testEmptyStates(): void {
    $this->overwatch();
    $expected = [
      '/admin/reports/ad-inventory' => 'No counts yet.',
      '/admin/reports/ad-inventory/inventory' => 'No slot is defined.',
      '/admin/reports/ad-inventory/performance' => 'No slot was viewed in this range.',
      '/admin/reports/ad-inventory/fill' => 'No network unit was viewed in this range.',
      '/admin/reports/ad-inventory/calendar' => 'No slot is defined.',
      '/admin/reports/ad-inventory/providers' => 'No network provider is configured',
      '/admin/reports/ad-inventory/email' => 'No email slot is defined.',
      '/admin/reports/ad-inventory/health' => 'Nothing is wrong.',
    ];
    foreach ($expected as $path => $text) {
      $this->drupalGet($path);
      $this->assertSession()->statusCodeEquals(200);
      $this->assertSession()->pageTextContains($text);
    }
  }

  /**
   * A public page with slots stays a cache hit with no cookie.
   */
  public function testNoPublicEffect(): void {
    $this->slot('slot_a');
    $this->slot('slot_b');
    $this->slot('slot_c');
    $this->drupalGet('/ad-inventory-test/page');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertEmpty($this->getSession()->getResponseHeader('Set-Cookie'));
    $this->assertSession()->responseNotContains('ad-inventory-report');
    $this->assertSession()->responseNotContains('class="air');
    $this->drupalGet('/ad-inventory-test/page');
    $this->assertSame('HIT', $this->getSession()->getResponseHeader('X-Drupal-Cache'));
    $this->assertEmpty($this->getSession()->getResponseHeader('Set-Cookie'));
  }

}
