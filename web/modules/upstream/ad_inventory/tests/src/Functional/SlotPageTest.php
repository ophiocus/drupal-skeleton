<?php

declare(strict_types=1);

namespace Drupal\Tests\ad_inventory\Functional;

use Drupal\ad_inventory\Entity\AdBooking;
use Drupal\ad_inventory\Entity\AdProvider;
use Drupal\ad_inventory\Entity\AdSlot;
use Drupal\ad_inventory\Entity\AdCreativeInterface;
use Drupal\ad_inventory\Plugin\Field\FieldType\AdOverridesItem;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\Tests\ad_inventory\Traits\AdInventoryTestTrait;
use Drupal\Tests\BrowserTestBase;
use Drupal\user\RoleInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Slots on real pages: the page cache holds, nothing loads before consent.
 */
#[Group('ad_inventory')]
#[RunTestsInSeparateProcesses]
final class SlotPageTest extends BrowserTestBase {

  use AdInventoryTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'ad_inventory',
    'ad_inventory_test',
    'page_cache',
    'block',
    'node',
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
   * The booked creative.
   */
  private AdCreativeInterface $booked;

  /**
   * The house creative.
   */
  private AdCreativeInterface $house;

  /**
   * Today, UTC.
   */
  private string $today;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->config('system.performance')->set('cache.page.max_age', 300)->save();
    $this->config('ad_inventory.settings')->set('timezone', 'UTC')->save();
    $this->today = gmdate('Y-m-d');
    $this->slot('slot_a');
    $this->slot('slot_b', ['desktop_format' => 'rectangle', 'phone_format' => 'rectangle']);
    $this->slot('slot_c', ['sources' => ['house']]);
    $this->booked = $this->creative(['leaderboard', 'mobile_banner', 'email'], [
      'label' => 'Booked',
      'target_url' => 'https://advertiser.example/booked',
    ]);
    $this->house = $this->creative(['leaderboard', 'mobile_banner', 'rectangle', 'email'], [
      'label' => 'House',
      'house' => TRUE,
      'target_url' => 'https://site.example/advertise',
    ]);
    $this->booking($this->booked, ['slot_a'], $this->today, gmdate('Y-m-d', strtotime('+5 days')));
  }

  /**
   * Two anonymous requests: both blocked banners, the second a cache hit.
   */
  public function testPageCacheAndBlockedBanner(): void {
    $this->drupalGet('/ad-inventory-test/page');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSame('MISS', $this->getSession()->getResponseHeader('X-Drupal-Cache'));
    $this->assertEmpty($this->getSession()->getResponseHeader('Set-Cookie'));
    [$outside, $template] = $this->split($this->getSession()->getPage()->getContent());

    $this->assertSame(3, substr_count($outside, 'class="ad-slot__blocked"'));
    $this->assertSame(3, substr_count($outside, 'Sponsored Links</p>'));
    $this->assertStringNotContainsString('<img', $outside, 'No creative image is requested before the grant.');
    $this->assertStringNotContainsString('googlesyndication', $outside);
    $this->assertStringContainsString('data-ad-kind="booking" data-ad-creative="' . $this->booked->id() . '"', $template);
    $this->assertStringContainsString('data-ad-kind="house" data-ad-creative="' . $this->house->id() . '"', $template);
    $this->assertStringContainsString('"adInventory"', $outside);

    $this->drupalGet('/ad-inventory-test/page');
    $this->assertSame('HIT', $this->getSession()->getResponseHeader('X-Drupal-Cache'));
    $this->assertEmpty($this->getSession()->getResponseHeader('Set-Cookie'));

    $routes = array_column($this->container->get('ad_inventory.placements')->all(), 'route');
    $this->assertContains('ad_inventory_test.page', $routes);
  }

  /**
   * Network units print only where the environment allows, never to staff.
   */
  public function testNetworkUnits(): void {
    AdProvider::create([
      'id' => 'google',
      'label' => 'AdSense',
      'plugin' => 'adsense',
      'settings' => ['verification' => TRUE],
    ])->save();
    $slot = AdSlot::load('slot_b');
    $slot->set('units', ['google' => ['slot' => '1234567890']])->save();

    $this->drupalGet('/ad-inventory-test/page');
    $this->assertSession()->responseNotContains('adsbygoogle');

    $settings['settings']['ad_inventory_network'] = (object) ['value' => TRUE, 'required' => TRUE];
    $settings['settings']['ad_inventory_accounts'] = (object) [
      'value' => ['google' => 'pub-1234567890123456'],
      'required' => TRUE,
    ];
    $this->writeSettings($settings);
    drupal_flush_all_caches();

    $this->drupalGet('/ad-inventory-test/page');
    [$outside, $template] = $this->split($this->getSession()->getPage()->getContent());
    $this->assertStringContainsString('<ins class="adsbygoogle"', $template);
    $this->assertStringNotContainsString('adsbygoogle.js', $outside, 'The loader waits for the grant.');
    $this->assertStringContainsString('<meta name="google-adsense-account" content="ca-pub-1234567890123456"', $outside);
    $this->drupalGet('/ad-inventory-test/page');
    $this->assertSame('HIT', $this->getSession()->getResponseHeader('X-Drupal-Cache'));
    $this->assertEmpty($this->getSession()->getResponseHeader('Set-Cookie'));

    $this->drupalGet('/ads.txt');
    $this->assertSession()->responseContains('google.com, pub-1234567890123456, DIRECT, f08c47fec0942fa0');

    $this->drupalLogin($this->drupalCreateUser(['bypass advertising']));
    $this->drupalGet('/ad-inventory-test/page');
    $this->assertSession()->responseNotContains('data-ad-kind="network"');
    $this->assertSession()->responseContains('data-ad-kind="booking"');
  }

  /**
   * Turning a slot off on one page leaves every other page as it was.
   *
   * The control is a field only roles with its permission may edit.
   */
  public function testPerPageControl(): void {
    $this->drupalCreateContentType(['type' => 'page', 'name' => 'Page']);
    FieldStorageConfig::create([
      'field_name' => 'field_ads',
      'entity_type' => 'node',
      'type' => 'ad_inventory_overrides',
    ])->save();
    FieldConfig::create(['field_name' => 'field_ads', 'entity_type' => 'node', 'bundle' => 'page', 'label' => 'Banners'])->save();
    $this->container->get('entity_display.repository')->getFormDisplay('node', 'page')
      ->setComponent('field_ads', ['type' => 'ad_inventory_overrides_default'])
      ->save();
    $this->drupalPlaceBlock('ad_inventory_slot:slot_a', ['region' => 'content']);
    user_role_grant_permissions(RoleInterface::ANONYMOUS_ID, ['access content']);
    $quiet = $this->drupalCreateNode([
      'type' => 'page',
      'title' => 'Quiet',
      'field_ads' => AdOverridesItem::encode(['slot_a' => ['off' => TRUE]]),
    ]);
    $other = $this->drupalCreateNode(['type' => 'page', 'title' => 'Other']);

    $this->drupalGet($quiet->toUrl());
    $this->assertSession()->responseNotContains('data-ad-slot="slot_a"');
    $this->drupalGet($other->toUrl());
    $this->assertSession()->responseContains('data-ad-slot="slot_a"');
    $this->drupalGet($other->toUrl());
    $this->assertSame('HIT', $this->getSession()->getResponseHeader('X-Drupal-Cache'));

    // Without the permission the control is not on the form.
    $editor = $this->drupalCreateUser(['edit any page content', 'access content']);
    $this->drupalLogin($editor);
    $this->drupalGet($other->toUrl('edit-form'));
    $this->assertSession()->fieldNotExists('field_ads[0][value][slot_a][show]');

    $holder = $this->drupalCreateUser(['edit any page content', 'access content', 'use ad inventory overrides']);
    $this->drupalLogin($holder);
    $this->drupalGet($other->toUrl('edit-form'));
    $this->submitForm(['field_ads[0][value][slot_a][show]' => FALSE], 'Save');
    $this->container->get('entity_type.manager')->getStorage('node')->resetCache();
    $stored = $this->container->get('entity_type.manager')->getStorage('node')->load($other->id())->get('field_ads')->value;
    $this->assertSame(['slot_a' => ['off' => TRUE]], AdOverridesItem::decode((string) $stored));
    $this->drupalLogout();
    $this->drupalGet($other->toUrl());
    $this->assertSession()->responseNotContains('data-ad-slot="slot_a"');
  }

  /**
   * Clicks and the beacon count; neither is cached nor starts a session.
   */
  public function testCounting(): void {
    $client = $this->getHttpClient();
    $response = $client->request('GET', $this->buildUrl('/ad/c/' . $this->booked->id(), ['query' => ['slot' => 'slot_a']]), [
      'allow_redirects' => FALSE,
      'http_errors' => FALSE,
    ]);
    $this->assertSame(302, $response->getStatusCode());
    $this->assertSame('https://advertiser.example/booked', $response->getHeaderLine('Location'));
    $this->assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'));
    $this->assertSame('', $response->getHeaderLine('Set-Cookie'));

    $body = json_encode(['v' => [['slot_a', (string) $this->booked->id()], ['nope', '1']], 'u' => []]);
    $response = $client->request('POST', $this->buildUrl('/ad/v'), [
      'body' => $body,
      'headers' => ['Content-Type' => 'text/plain;charset=UTF-8'],
      'http_errors' => FALSE,
    ]);
    $this->assertSame(204, $response->getStatusCode());
    $this->assertSame('', $response->getHeaderLine('Set-Cookie'));

    $rows = $this->container->get('ad_inventory.counter')->rows($this->today, $this->today);
    $this->assertCount(1, $rows);
    $this->assertSame(['slot_a', (string) $this->booked->id(), 1, 1], [
      $rows[0]['slot'],
      $rows[0]['creative'],
      $rows[0]['views'],
      $rows[0]['clicks'],
    ]);

    $this->assertSame(404, $client->request('GET', $this->buildUrl('/ad/c/9999'), [
      'allow_redirects' => FALSE,
      'http_errors' => FALSE,
    ])->getStatusCode());
  }

  /**
   * An email slot: one image and one click address, the same for everyone.
   */
  public function testEmailSlot(): void {
    $this->slot('mail', ['channel' => 'email', 'desktop_format' => 'email', 'phone_format' => '']);
    $client = $this->getHttpClient();

    $this->drupalGet('/ad/e/mail.png');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSame('image/png', $this->getSession()->getResponseHeader('Content-Type'));
    $this->assertStringContainsString('public', (string) $this->getSession()->getResponseHeader('Cache-Control'));
    $size = getimagesizefromstring($this->getSession()->getPage()->getContent());
    $this->assertSame([1200, 400], [$size[0], $size[1]]);
    $this->drupalGet('/ad/e/mail.png');
    $this->assertSame('HIT', $this->getSession()->getResponseHeader('X-Drupal-Cache'));

    $go = $client->request('GET', $this->buildUrl('/ad/e/mail/go'), ['allow_redirects' => FALSE, 'http_errors' => FALSE]);
    $this->assertSame(302, $go->getStatusCode());
    $this->assertSame('https://site.example/advertise', $go->getHeaderLine('Location'), 'House until a booking is in force.');

    // A booking of the slot replaces the house creative at once.
    $this->booking($this->booked, ['mail'], $this->today, $this->today);
    $go = $client->request('GET', $this->buildUrl('/ad/e/mail/go'), ['allow_redirects' => FALSE, 'http_errors' => FALSE]);
    $this->assertSame('https://advertiser.example/booked', $go->getHeaderLine('Location'));
    $this->drupalGet('/ad/e/mail.png');
    $this->assertSame('MISS', $this->getSession()->getResponseHeader('X-Drupal-Cache'), 'The booking invalidated the image.');

    $this->drupalGet('/ad/e/slot_a.png');
    $this->assertSession()->statusCodeEquals(404);
    $this->drupalGet('/ad/e/nope.png');
    $this->assertSession()->statusCodeEquals(404);
  }

  /**
   * The admin pages, approval and the booking report.
   */
  public function testAdminPages(): void {
    $this->drupalLogin($this->drupalCreateUser([
      'administer ad inventory',
      'manage ad creatives',
      'manage ad bookings',
      'approve ad bookings',
      'view ad booking reports',
      'access administration pages',
    ]));
    foreach ([
      '/admin/config/services/ad-inventory',
      '/admin/config/services/ad-inventory/status',
      '/admin/structure/ad-inventory/formats',
      '/admin/structure/ad-inventory/slots',
      '/admin/structure/ad-inventory/providers',
      '/admin/structure/ad-inventory/providers/add',
      '/admin/content/ad-creative',
      '/admin/content/ad-booking',
    ] as $path) {
      $this->drupalGet($path);
      $this->assertSession()->statusCodeEquals(200);
    }

    $draft = $this->booking($this->house, ['slot_b'], $this->today, $this->today, 'draft', ['label' => 'Draft one']);
    $this->drupalGet($draft->toUrl('approve-form'));
    $this->submitForm([], 'Approve');
    $this->assertSame('approved', AdBooking::load($draft->id())->state());
    $this->drupalGet($draft->toUrl('stop-form'));
    $this->submitForm([], 'Stop');
    $this->assertSame('stopped', AdBooking::load($draft->id())->state());

    $this->drupalGet('/admin/content/ad-booking/' . $draft->id() . '/report');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('No views or clicks counted yet.');
    $this->drupalGet('/admin/content/ad-booking/' . $draft->id() . '/report/csv');
    $this->assertSession()->responseHeaderContains('Content-Type', 'text/csv');
  }

  /**
   * The markup outside the slots' templates, and inside them.
   *
   * @return string[]
   *   Outside, then inside.
   */
  private function split(string $html): array {
    preg_match_all('#<template[^>]*>(.*?)</template>#s', $html, $m);
    return [preg_replace('#<template[^>]*>.*?</template>#s', '', $html), implode("\n", $m[1])];
  }

}
