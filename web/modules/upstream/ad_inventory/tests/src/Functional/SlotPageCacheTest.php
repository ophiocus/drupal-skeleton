<?php

declare(strict_types=1);

namespace Drupal\Tests\ad_inventory\Functional;

use Drupal\ad_inventory\Entity\AdCreative;
use Drupal\ad_inventory\Entity\AdSlot;
use Drupal\Tests\ad_inventory\Traits\AdFixturesTrait;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Banner pages stay one cached page for every reader.
 *
 * The page cache is never traded: two anonymous
 * requests give a HIT and no cookie; the creative waits inside <template>
 * behind the "turned off" notice; counting needs no session.
 */
#[Group('ad_inventory')]
#[RunTestsInSeparateProcesses]
final class SlotPageCacheTest extends BrowserTestBase {

  use AdFixturesTrait;
  use MediaTypeCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'block',
    'page_cache',
    'dynamic_page_cache',
    'media',
    'image',
    'ad_inventory',
    'ad_inventory_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * The approved house banner.
   */
  private AdCreative $creative;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // As on a site behind an edge cache: without a page max-age core marks
    // every cacheable response private, the mail image included.
    $this->config('system.performance')->set('cache.page.max_age', 3600)->save();
    $this->createMediaType('image', ['id' => 'image']);
    $this->createSlot('test_end');
    $this->createSlot('test_mail', 'email', 'email', AdSlot::EMAIL);
    $this->creative = $this->createCreative(['leaderboard', 'rectangle', 'email'], [
      'house' => TRUE,
      'state' => AdCreative::APPROVED,
      'target_url' => '/ad-inventory-test?landed=1',
    ]);
    $this->drupalPlaceBlock('ad_inventory_slot:test_end', ['region' => 'content', 'id' => 'test_end']);
  }

  /**
   * Two anonymous requests: HIT, no cookie, creative inert behind consent.
   */
  public function testCachedPage(): void {
    $this->drupalGet('/ad-inventory-test');
    $this->drupalGet('/ad-inventory-test');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseHeaderEquals('X-Drupal-Cache', 'HIT');
    $this->assertSession()->responseHeaderDoesNotExist('Set-Cookie');

    $html = $this->getSession()->getPage()->getContent();
    $this->assertStringContainsString('data-ad-slot="test_end"', $html);
    $this->assertStringContainsString('Turned off by your cookie choice', $html);
    [$before, $after] = explode('<template', $html, 2) + [1 => ''];
    $this->assertStringNotContainsString('<img', $before, 'No image before the reader consents.');
    $this->assertStringContainsString('<img', $after);
    $this->assertStringContainsString('rel="sponsored noopener"', $after);
  }

  /**
   * A space with nothing approved prints nothing.
   */
  public function testEmptySpaceIsHidden(): void {
    $this->creative->set('state', AdCreative::DRAFT)->save();
    $this->drupalGet('/ad-inventory-test');
    $this->assertSession()->responseNotContains('data-ad-slot="test_end"');
    $this->assertSession()->responseNotContains('Turned off by your cookie choice');
  }

  /**
   * The beacon and the click count without a session.
   */
  public function testCounting(): void {
    $client = $this->getHttpClient();
    $id = (int) $this->creative->id();
    $response = $client->post($this->buildUrl('/ad/v'), [
      'body' => json_encode(['v' => [['test_end', $id, 'house'], ['test_end', $id, 'house'], ['nope', $id, 'house']]]),
      'headers' => ['Content-Type' => 'application/json'],
      'http_errors' => FALSE,
    ]);
    $this->assertSame(204, $response->getStatusCode());
    $this->assertSame([], $response->getHeader('Set-Cookie'));

    $response = $client->get($this->buildUrl('/ad/c/' . $id, ['query' => ['s' => 'test_end', 'f' => 'house']]), [
      'allow_redirects' => FALSE,
      'http_errors' => FALSE,
    ]);
    $this->assertSame(302, $response->getStatusCode());
    $this->assertStringContainsString('/ad-inventory-test?landed=1', $response->getHeaderLine('Location'));
    $this->assertSame([], $response->getHeader('Set-Cookie'));

    $rows = $this->container->get('ad_inventory.counter')->rows($id);
    $this->assertCount(1, $rows);
    $this->assertSame(1, $rows[0]['views']);
    $this->assertSame(1, $rows[0]['clicks']);
  }

  /**
   * The mail image is public and cacheable; /ads.txt stays 404 with no lines.
   */
  public function testMailImageAndAdsTxt(): void {
    $response = $this->getHttpClient()->get($this->buildUrl('/ad/e/test_mail/banner.png'), ['http_errors' => FALSE]);
    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame('image/png', $response->getHeaderLine('Content-Type'));
    $this->assertStringContainsString('public', $response->getHeaderLine('Cache-Control'));
    $this->assertGreaterThan(100, strlen((string) $response->getBody()));

    $this->drupalGet('/ad/e/test_end/banner.png');
    $this->assertSession()->statusCodeEquals(404);

    $response = $this->getHttpClient()->get($this->buildUrl('/ads.txt'), ['http_errors' => FALSE]);
    $this->assertSame(404, $response->getStatusCode(), (string) $response->getBody());
  }

}
