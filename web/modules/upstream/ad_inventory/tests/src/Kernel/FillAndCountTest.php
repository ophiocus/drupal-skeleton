<?php

declare(strict_types=1);

namespace Drupal\Tests\ad_inventory\Kernel;

use Drupal\ad_inventory\Element\AdSlotElement;
use Drupal\ad_inventory\Entity\AdProvider;
use Drupal\ad_inventory\Plugin\Field\FieldType\AdOverridesItem;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The slot markup, per-page controls, counts, placements and status gates.
 */
#[Group('ad_inventory')]
#[RunTestsInSeparateProcesses]
final class FillAndCountTest extends AdInventoryKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'file',
    'image',
    'media',
    'options',
    'datetime',
    'block',
    'adstxt',
    'ad_inventory',
    'ad_inventory_test',
    'entity_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->container->get('router.builder')->rebuild();
    $this->installEntitySchema('entity_test');
    $this->container->get('theme_installer')->install(['stark']);
    $this->config('system.theme')->set('default', 'stark')->save();
  }

  /**
   * The HTML carries the blocked banner; creatives wait in a template.
   */
  public function testSlotMarkup(): void {
    $this->slot('top');
    $booked = $this->creative(['leaderboard', 'mobile_banner'], ['label' => 'Booked', 'caption' => 'Code 123']);
    $next = $this->creative(['leaderboard', 'mobile_banner'], ['label' => 'Next']);
    $later = $this->creative(['leaderboard', 'mobile_banner'], ['label' => 'Later']);
    $house = $this->creative(['leaderboard', 'mobile_banner'], ['label' => 'House', 'house' => TRUE]);
    // Lacks the phone rendition: never printed.
    $this->creative(['leaderboard'], ['label' => 'Half', 'house' => TRUE]);
    $first = $this->booking($booked, ['top'], '2026-10-20', '2026-10-28');
    $this->assertSame('approved', $first->state(), $first->reason());
    $this->booking($next, ['top'], '2026-10-29', '2026-11-02');
    $this->booking($later, ['top'], '2026-11-03', '2026-11-09');

    $build = AdSlotElement::build('top');
    $html = (string) $this->render($build);
    [$outside, $template] = $this->split($html);

    $this->assertStringContainsString('data-ad-slot="top"', $outside);
    $this->assertStringContainsString('Sponsored Links', $outside);
    $this->assertStringContainsString('Advertising is off until you allow it.', $outside);
    $this->assertStringContainsString('href="#privacy-settings"', $outside);
    $this->assertStringNotContainsString('<img', $outside);
    $this->assertStringNotContainsString('<script', $outside);
    $this->assertStringContainsString('<style data-ad-inventory="formats">', $html);
    $this->assertStringContainsString('aspect-ratio:728/90', $html);

    // In force today, then the next one; not the one after.
    $this->assertStringContainsString('data-ad-creative="' . $booked->id() . '"', $template);
    $this->assertStringContainsString('data-start="2026-10-20"', $template);
    $this->assertStringContainsString('data-ad-creative="' . $next->id() . '"', $template);
    $this->assertStringNotContainsString('data-ad-creative="' . $later->id() . '"', $template);
    $this->assertStringContainsString('data-ad-kind="house" data-ad-creative="' . $house->id() . '"', $template);
    $this->assertSame(1, substr_count($template, 'data-ad-kind="house"'));
    $this->assertStringContainsString('data-caption="Code 123"', $template);
    $this->assertStringContainsString('rel="sponsored noopener"', $template);
    $this->assertStringContainsString('/ad/c/' . $booked->id() . '?slot=top', $template);
    $this->assertStringContainsString('<source media="(max-width: 767px)"', $template);
    $this->assertStringContainsString('loading="lazy"', $template);
    $this->assertStringContainsString('ad_inventory_leaderboard_2x', $template);
    // Network serving is off here: no unit.
    $this->assertStringNotContainsString('data-ad-kind="network"', $template);

    $metadata = CacheableMetadata::createFromRenderArray($this->container->get('ad_inventory.slot_renderer')->build('top'));
    $this->assertContains('ad_slot:top', $metadata->getCacheTags());
    $this->assertNotContains('cookies', $metadata->getCacheContexts());
    $this->assertNotContains('session', $metadata->getCacheContexts());
    $this->assertNotContains('user', $metadata->getCacheContexts());
    $this->assertNotSame(0, $metadata->getCacheMaxAge());
  }

  /**
   * Network units print only when the environment turns them on.
   */
  public function testNetworkGate(): void {
    $this->slot('top', ['units' => ['google' => ['slot' => '1234567890', 'channel' => 'top_slot']]]);
    AdProvider::create([
      'id' => 'google',
      'label' => 'AdSense',
      'plugin' => 'adsense',
      'settings' => ['verification' => TRUE],
    ])->save();

    $build = AdSlotElement::build('top');
    $this->assertStringNotContainsString('adsbygoogle', (string) $this->render($build));

    $this->setSetting('ad_inventory_network', TRUE);
    $this->setSetting('ad_inventory_accounts', ['google' => 'ca-pub-1234567890123456']);
    $build = AdSlotElement::build('top', NULL, 0, ['x']);
    [, $template] = $this->split((string) $this->render($build));
    $this->assertStringContainsString('data-ad-creative="provider:google"', $template);
    $this->assertStringContainsString('<ins class="adsbygoogle" style="display:inline-block" data-ad-client="ca-pub-1234567890123456" data-ad-slot="1234567890" data-ad-channel="top_slot"></ins>', $template);
    $this->assertStringContainsString('data-ad-loader="https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=ca-pub-1234567890123456"', $template);
    $this->assertStringNotContainsString('data-overlays', $template);
    $this->assertStringNotContainsString('data-ad-format', $template);

    // ads.txt gets Google's line, with pub- and not ca-pub-.
    $this->assertSame(['google.com, pub-1234567890123456, DIRECT, f08c47fec0942fa0'], $this->container->get('module_handler')->invokeAll('adstxt', [new CacheableMetadata()]));
  }

  /**
   * A control that turns a slot off on one page leaves the others alone.
   */
  public function testPerPageControls(): void {
    $this->slot('top', ['alternative_formats' => ['rectangle']]);
    $this->slot('band', ['repeatable' => TRUE]);
    FieldStorageConfig::create([
      'field_name' => 'field_ads',
      'entity_type' => 'entity_test',
      'type' => 'ad_inventory_overrides',
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_ads',
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'label' => 'Ads',
    ])->save();
    $off = EntityTest::create([
      'name' => 'off',
      'field_ads' => AdOverridesItem::encode(['top' => ['off' => TRUE], 'band' => ['count' => 1]]),
    ]);
    $off->save();
    $sized = EntityTest::create([
      'name' => 'sized',
      'field_ads' => AdOverridesItem::encode(['top' => ['format' => 'rectangle']]),
    ]);
    $sized->save();
    $plain = EntityTest::create(['name' => 'plain']);
    $plain->save();

    $render = function (string $slot, $entity, int $delta = 0): string {
      $build = AdSlotElement::build($slot, $entity, $delta);
      return (string) $this->render($build);
    };
    $this->assertStringNotContainsString('data-ad-slot="top"', $render('top', $off));
    $this->assertStringContainsString('data-ad-desktop="rectangle"', $render('top', $sized));
    $this->assertStringContainsString('data-ad-desktop="leaderboard"', $render('top', $plain));
    // Repeatable: the page's own count limits it.
    $this->assertStringContainsString('data-ad-slot="band"', $render('band', $off, 0));
    $this->assertStringNotContainsString('data-ad-slot="band"', $render('band', $off, 1));
    $this->assertStringContainsString('data-ad-slot="band"', $render('band', $plain, 3));
    // Not repeatable: one per page.
    $this->assertStringNotContainsString('data-ad-slot="top"', $render('top', $plain, 1));
  }

  /**
   * Counts: known ids only, one row per day, slot and creative.
   */
  public function testCounts(): void {
    $this->slot('top');
    $creative = $this->creative(['leaderboard', 'mobile_banner']);
    AdProvider::create([
      'id' => 'google',
      'label' => 'AdSense',
      'plugin' => 'adsense',
      'settings' => ['verification' => FALSE],
    ])->save();
    $counter = $this->container->get('ad_inventory.counter');
    $id = (string) $creative->id();

    $counted = $counter->beacon([
      'views' => [['top', $id], ['top', 'provider:google'], ['nope', $id], ['top', '999'], ['top', 'provider:nope']],
      'unfilled' => [['top', 'provider:google']],
    ]);
    $this->assertSame(3, $counted);
    $counter->beacon(['views' => [['top', $id]], 'unfilled' => []]);
    $counter->click('top', $id);
    $counter->click('nope', $id);

    $rows = $counter->rows('2026-10-26', '2026-10-26');
    $by = [];
    foreach ($rows as $row) {
      $by[$row['slot'] . '|' . $row['creative']] = [$row['views'], $row['clicks'], $row['unfilled']];
    }
    $this->assertSame([
      '|' . $id => [0, 1, 0],
      'top|' . $id => [2, 1, 0],
      'top|provider:google' => [1, 0, 1],
    ], $by);
    // Nothing about the reader is stored: the table has no such column.
    $columns = array_keys($this->container->get('database')->query('SELECT * FROM {ad_inventory_count} LIMIT 1')->fetchAssoc());
    $this->assertSame(['day', 'slot', 'creative', 'views', 'clicks', 'unfilled'], $columns);
  }

  /**
   * Placements unseen for 90 days are pruned at cron.
   */
  public function testPlacementsPrune(): void {
    $placements = $this->container->get('ad_inventory.placements');
    $placements->record('top', 'entity.node.canonical', 'template');
    $this->assertCount(1, $placements->all());
    $this->at('2026-12-20 12:00');
    $placements->record('band', 'view.events.page', 'template');
    $this->at('2027-02-01 12:00');
    $this->assertSame(1, $placements->prune());
    $this->assertSame(['band'], array_column($placements->all(), 'slot'));
  }

  /**
   * The status page names every closed gate.
   */
  public function testStatusGates(): void {
    $status = $this->container->get('ad_inventory.status');
    $gates = array_column($status->gates(), 'severity', 'gate');
    $this->assertSame('ok', $gates['consent']);
    $this->assertSame('ok', $gates['network']);

    AdProvider::create([
      'id' => 'google',
      'label' => 'AdSense',
      'plugin' => 'adsense',
      'settings' => ['verification' => TRUE],
    ])->save();
    $this->config('ad_inventory.settings')->set('label', 'Publicidad')->save();
    $gates = array_column($status->gates(), 'severity', 'gate');
    $this->assertSame('warning', $gates['network']);
    $this->assertSame('error', $gates['provider:google'], 'No account id.');
    $this->assertSame('error', $gates['label:google'], 'Google allows only its two labels.');
    $this->assertTrue($status->hasErrors());

    $this->setSetting('ad_inventory_accounts', ['google' => 'pub-1234567890123456']);
    $this->config('ad_inventory.settings')->set('label', 'Enlaces patrocinados')->save();
    $gates = array_column($status->gates(), 'severity', 'gate');
    $this->assertArrayNotHasKey('provider:google', $gates);
    $this->assertArrayNotHasKey('label:google', $gates);
    $this->assertArrayNotHasKey('ads_txt:google', $gates, 'The module adds the line to ads.txt itself.');
    $this->assertTrue($status::adsTxtHas($status->adsTxt(), 'google.com,pub-1234567890123456,DIRECT,f08c47fec0942fa0'));
  }

  /**
   * The markup outside the slots' templates, and inside them.
   *
   * @return string[]
   *   Outside, then inside.
   */
  private function split(string $html): array {
    // Only the slots: the page around them is the test theme's.
    preg_match_all('#<aside class="ad-slot".*?</aside>#s', $html, $asides);
    $html = implode("\n", $asides[0]);
    preg_match_all('#<template[^>]*>(.*?)</template>#s', $html, $m);
    return [preg_replace('#<template[^>]*>.*?</template>#s', '', $html), implode("\n", $m[1])];
  }

}
