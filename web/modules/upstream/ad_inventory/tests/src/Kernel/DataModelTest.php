<?php

declare(strict_types=1);

namespace Drupal\Tests\ad_inventory\Kernel;

use Drupal\ad_inventory\AdInventoryException;
use Drupal\ad_inventory\Entity\AdBooking;
use Drupal\ad_inventory\Entity\AdFormat;
use Drupal\ad_inventory\Entity\AdProvider;
use Drupal\ad_inventory\Entity\AdSlot;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\image\Entity\ImageStyle;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Formats, slots, creatives, bookings, approval, providers and access.
 */
#[Group('ad_inventory')]
#[RunTestsInSeparateProcesses]
final class DataModelTest extends AdInventoryKernelTestBase {

  /**
   * The default formats exist, each with its two image styles.
   */
  public function testFormatsOwnTheirStyles(): void {
    $formats = AdFormat::loadMultiple();
    $this->assertEqualsCanonicalizing(['leaderboard', 'mobile_banner', 'rectangle', 'skyscraper', 'half_page', 'email'], array_keys($formats));
    $this->assertSame([
      ['min_width' => 0, 'width' => 468, 'height' => 60],
      ['min_width' => 728, 'width' => 728, 'height' => 90],
    ], $formats['leaderboard']->networkSizes());
    $this->assertSame([], $formats['email']->networkSizes());

    $style = ImageStyle::load('ad_inventory_leaderboard_2x');
    $this->assertNotNull($style);
    $effects = array_values(array_map(static fn($e) => $e->getConfiguration()['data'], iterator_to_array($style->getEffects())));
    $this->assertSame(1456, $effects[0]['width']);
    $this->assertSame(180, $effects[0]['height']);

    // A new size reshapes the styles; deleting the format removes them.
    $formats['rectangle']->set('width', 336)->set('height', 280)->save();
    $effects = array_values(array_map(static fn($e) => $e->getConfiguration()['data'], iterator_to_array(ImageStyle::load('ad_inventory_rectangle_1x')->getEffects())));
    $this->assertSame([336, 280], [$effects[0]['width'], $effects[0]['height']]);
    $formats['skyscraper']->delete();
    $this->assertNull(ImageStyle::load('ad_inventory_skyscraper_1x'));
  }

  /**
   * No approval while a needed format lacks a rendition of the right ratio.
   */
  public function testApprovalNeedsEveryRendition(): void {
    $this->slot('top');
    $creative = $this->creative(['leaderboard']);
    $booking = $this->booking($creative, ['top'], '2026-10-26', '2026-11-01');
    $this->assertSame('draft', $booking->state());
    $this->assertStringContainsString('Mobile banner', $booking->reason());

    // A phone rendition of the wrong ratio is still refused.
    $creative->get('renditions')->appendItem([
      'target_id' => $this->rendition(640, 300)->id(),
      'format' => 'mobile_banner',
    ]);
    $creative->save();
    $booking->set('creative', $creative)->setState('approved')->save();
    $this->assertSame('draft', $booking->state());
    $this->assertStringContainsString('within 1%', $booking->reason());

    // Within 1% passes (641×200 against 320×100 is 0.16% off).
    $creative->set('renditions', [
      ['target_id' => $creative->rendition('leaderboard')->id(), 'format' => 'leaderboard'],
      ['target_id' => $this->rendition(641, 200)->id(), 'format' => 'mobile_banner'],
    ])->save();
    $booking->set('creative', $creative)->setState('approved')->save();
    $this->assertSame('approved', $booking->state());
    $this->assertSame('', $booking->reason());
  }

  /**
   * Slot groups, kinds and the site's validation event.
   */
  public function testKindsGroupsAndValidation(): void {
    $this->config('ad_inventory.settings')->set('kinds', [['id' => 'event', 'label' => 'Promoted event']])->save();
    $this->slot('side', [
      'group' => 'columns',
      'booking_kinds' => ['event'],
      'desktop_format' => 'rectangle',
      'phone_format' => 'rectangle',
    ]);
    $this->slot('foot', ['group' => 'columns', 'desktop_format' => 'rectangle', 'phone_format' => 'rectangle']);
    $inventory = $this->container->get('ad_inventory.inventory');
    $this->assertSame(['side', 'foot'], $inventory->expandSlots(['group:columns']));

    $plain = $this->creative(['rectangle']);
    $booking = $this->booking($plain, ['group:columns'], '2026-10-26', '2026-10-30');
    $this->assertSame('draft', $booking->state());
    $this->assertStringContainsString('takes no "plain" creatives', $booking->reason());

    $event = $this->creative(['rectangle'], ['kind' => 'event']);
    $booking = $this->booking($event, ['group:columns'], '2026-10-26', '2026-10-30');
    $this->assertSame('approved', $booking->state());

    // A subscriber refusing at save sends a new approval back to draft.
    $state = $this->container->get('state');
    $state->set('ad_inventory_test.refuse', 'The registration code does not match.');
    $refused = $this->booking($event, ['foot'], '2026-10-26', '2026-10-30');
    $this->assertSame('draft', $refused->state());
    $this->assertSame('The registration code does not match.', $refused->reason());

    // At the daily run a refusal stops a running booking.
    $state->set('ad_inventory_test.phase', 'daily');
    $this->at('2026-10-27 01:00');
    $report = $this->container->get('ad_inventory.scheduler')->run();
    $this->assertTrue($report['ran']);
    $this->assertContains((int) $booking->id(), $report['stopped']);
    $this->assertSame('stopped', AdBooking::load($booking->id())->state());

    // A second run the same day does nothing.
    $this->assertFalse($this->container->get('ad_inventory.scheduler')->run()['ran']);
  }

  /**
   * A booking's changes invalidate its slots' fill tag, and only theirs.
   */
  public function testBookingInvalidatesItsSlots(): void {
    $this->slot('top');
    $this->slot('other');
    $cache = $this->container->get('cache.render');
    $cache->set('top', 'x', -1, [AdSlot::fillTag('top')]);
    $cache->set('other', 'x', -1, [AdSlot::fillTag('other')]);
    $this->booking($this->creative(['leaderboard', 'mobile_banner']), ['top'], '2026-10-26', '2026-10-30');
    $this->assertFalse($cache->get('top'));
    $this->assertNotFalse($cache->get('other'));
  }

  /**
   * A booking that starts tomorrow shows tomorrow, with no deploy.
   */
  public function testTomorrowsBookingArrivesByCron(): void {
    $slot = $this->slot('mail', [
      'channel' => 'email',
      'desktop_format' => 'email',
      'sources' => ['booking', 'network', 'house'],
    ]);
    $this->assertSame(['booking', 'house'], $slot->sources(), 'Email never takes a network.');
    $house = $this->creative(['email'], ['house' => TRUE, 'label' => 'House']);
    $booked = $this->creative(['email'], ['label' => 'Booked']);
    $this->booking($booked, ['mail'], '2026-10-27', '2026-10-31');
    $scheduler = $this->container->get('ad_inventory.scheduler');
    $scheduler->run();

    $email = $this->container->get('ad_inventory.email');
    $this->assertSame($house->id(), $email->pick($slot, new CacheableMetadata())->id());

    $cache = $this->container->get('cache.render');
    $cache->set('mail', 'x', -1, [AdSlot::fillTag('mail')]);
    $this->at('2026-10-27 00:30');
    $report = $scheduler->run();
    $this->assertSame(['mail'], $report['slots']);
    $this->assertFalse($cache->get('mail'), 'The slot tag was invalidated when the booking started.');
    $this->assertSame($booked->id(), $email->pick($slot, new CacheableMetadata())->id());

    // And invalidated again the day after it ends.
    $cache->set('mail', 'x', -1, [AdSlot::fillTag('mail')]);
    $this->at('2026-11-01 00:30');
    $this->assertSame(['mail'], $scheduler->run()['slots']);
    $this->assertSame($house->id(), $email->pick($slot, new CacheableMetadata())->id());
  }

  /**
   * A pop-under provider is refused beside AdSense.
   */
  public function testPopUnderRefusedBesideAdSense(): void {
    AdProvider::create([
      'id' => 'adsense',
      'label' => 'AdSense',
      'plugin' => 'adsense',
      'weight' => 0,
      'settings' => ['verification' => TRUE],
    ])->save();
    // A banner network is fine beside it.
    AdProvider::create([
      'id' => 'banners',
      'label' => 'Banners',
      'plugin' => 'snippet',
      'weight' => 1,
      'settings' => ['kinds' => ['banner']] + $this->snippetDefaults(),
    ])->save();
    // A disabled pop-under network may be kept.
    $popunder = AdProvider::create([
      'id' => 'popunder',
      'label' => 'Popunder',
      'plugin' => 'snippet',
      'weight' => 2,
      'status' => FALSE,
      'settings' => ['kinds' => ['banner', 'pop_under']] + $this->snippetDefaults(),
    ]);
    $popunder->save();
    try {
      $popunder->setStatus(TRUE)->save();
      $this->fail('Enabling a pop-under beside AdSense was not refused.');
    }
    catch (AdInventoryException $e) {
      $this->assertStringContainsString('pop_under', $e->getMessage());
    }
    $this->assertFalse(AdProvider::load('popunder')->status());
  }

  /**
   * Who may do what with creatives and bookings.
   */
  public function testAccess(): void {
    Role::create(['id' => 'seller', 'label' => 'Seller', 'permissions' => ['manage ad bookings', 'manage ad creatives']])->save();
    Role::create(['id' => 'boss', 'label' => 'Boss', 'permissions' => ['approve ad bookings']])->save();
    // User 1 is the super user; start ids at 2.
    User::create(['name' => 'admin', 'uid' => 1])->save();
    $seller = User::create(['name' => 'seller', 'roles' => ['seller']]);
    $seller->save();
    $boss = User::create(['name' => 'boss', 'roles' => ['boss']]);
    $boss->save();
    $nobody = User::create(['name' => 'nobody']);
    $nobody->save();

    $this->slot('top');
    $booking = $this->booking($this->creative(['leaderboard', 'mobile_banner']), ['top'], '2026-10-26', '2026-10-30', 'draft');
    $this->assertTrue($booking->access('update', $seller));
    $this->assertFalse($booking->access('approve', $seller));
    $this->assertTrue($booking->access('approve', $boss));
    $this->assertFalse($booking->access('update', $boss));
    $this->assertFalse($booking->access('view', $nobody));
    $this->assertFalse($booking->creative()->access('update', $nobody));
  }

  /**
   * Snippet provider settings with every key.
   */
  private function snippetDefaults(): array {
    return [
      'loader' => '',
      'house_behind' => FALSE,
      'max_units' => 0,
      'labels' => [],
      'ads_txt' => [],
      'consent' => 'Advertising consent.',
    ];
  }

}
