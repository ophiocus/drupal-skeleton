<?php

declare(strict_types=1);

namespace Drupal\Tests\ad_inventory\Kernel;

use Drupal\ad_inventory\AdResolver;
use Drupal\ad_inventory\Entity\AdBooking;
use Drupal\ad_inventory\Entity\AdCreative;
use Drupal\ad_inventory\Entity\AdSlot;
use Drupal\ad_inventory\Event\AdBookingValidateEvent;
use Drupal\ad_inventory\Fill;
use Drupal\ad_inventory\Scope\AdScope;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\ad_inventory\Traits\AdFixturesTrait;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * What fills a space: booking, provider, house, or nothing at all.
 *
 * With nothing approved to show, a space is hidden; drafts are seen only by
 * staff who preview them.
 */
#[Group('ad_inventory')]
#[RunTestsInSeparateProcesses]
final class ResolverTest extends KernelTestBase {

  use AdFixturesTrait;
  use MediaTypeCreationTrait;
  use UserCreationTrait;

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
    'block',
    'adstxt',
    'ad_inventory',
  ];

  /**
   * The space under test.
   */
  private AdSlot $slot;

  /**
   * A reader.
   */
  private AccountInterface $reader;

  /**
   * Staff who preview drafts.
   */
  private AccountInterface $staff;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('file');
    $this->installEntitySchema('media');
    $this->installEntitySchema('ad_creative');
    $this->installEntitySchema('ad_booking');
    $this->installEntitySchema('ad_override');
    $this->installSchema('file', ['file_usage']);
    $this->installSchema('ad_inventory', ['ad_inventory_count']);
    $this->installConfig(['system', 'image', 'media', 'ad_inventory']);
    $this->createMediaType('image', ['id' => 'image']);
    $this->slot = $this->createSlot('test_end');
    $this->reader = new AnonymousUserSession();
    // The first account is the superuser; take it so the staff account is not.
    $this->createUser();
    $this->staff = $this->createUser(['preview ad drafts']);
  }

  /**
   * Resolves the space for an account.
   */
  private function fill(AccountInterface $account, array $keys = [], ?CacheableMetadata $cacheability = NULL): ?Fill {
    $resolver = new AdResolver(
      $this->container->get('entity_type.manager'),
      $this->container->get('ad_inventory.site_day'),
      $this->container->get('ad_inventory.overrides'),
    );
    return $resolver->resolve($this->slot, new AdScope(NULL, $keys, new CacheableMetadata()), $account, $cacheability ?? new CacheableMetadata());
  }

  /**
   * A day relative to the site's today.
   */
  private function day(int $offset): string {
    $today = $this->container->get('ad_inventory.site_day')->today();
    return (new \DateTimeImmutable($today))->modify(sprintf('%+d days', $offset))->format('Y-m-d');
  }

  /**
   * Nothing approved: readers get nothing; staff see the draft.
   */
  public function testDraftsAreHiddenFromReaders(): void {
    $this->assertNull($this->fill($this->reader));
    $this->createCreative(['leaderboard', 'rectangle'], ['house' => TRUE]);

    $this->assertNull($this->fill($this->reader));
    $fill = $this->fill($this->staff);
    $this->assertNotNull($fill);
    $this->assertSame(Fill::HOUSE, $fill->source);
    $this->assertTrue($fill->draft);
  }

  /**
   * A house banner missing a rendition the space needs is not used.
   */
  public function testHouseNeedsBothRenditions(): void {
    $this->createCreative(['leaderboard'], ['house' => TRUE, 'state' => AdCreative::APPROVED]);
    $this->assertNull($this->fill($this->reader));
    $this->createCreative(['leaderboard', 'rectangle'], ['house' => TRUE, 'state' => AdCreative::APPROVED]);
    $fill = $this->fill($this->reader);
    $this->assertSame(Fill::HOUSE, $fill?->source);
    $this->assertFalse($fill->draft);
    $this->assertCount(1, $fill->candidates);
  }

  /**
   * Every fitting house banner goes to the page for the browser's pick.
   *
   * Drafts join the pool only for staff previewing them.
   */
  public function testHousePool(): void {
    foreach ([AdCreative::APPROVED, AdCreative::APPROVED, AdCreative::DRAFT] as $state) {
      $this->createCreative(['leaderboard', 'rectangle'], ['house' => TRUE, 'state' => $state]);
    }
    $this->assertCount(2, $this->fill($this->reader)?->candidates ?? []);
    $staff = $this->fill($this->staff);
    $this->assertCount(3, $staff?->candidates ?? []);
    $this->assertTrue($staff->draft);
  }

  /**
   * A booking in force beats the house.
   *
   * Outside its days, stopped, a draft or out of scope it does not.
   */
  public function testBookingOrder(): void {
    $house = $this->createCreative(['leaderboard', 'rectangle'], ['house' => TRUE, 'state' => AdCreative::APPROVED]);
    $paid = $this->createCreative(['leaderboard', 'rectangle'], ['state' => AdCreative::APPROVED]);

    $past = $this->createBooking($paid, ['test_end'], $this->day(-9), $this->day(-2));
    $this->assertSame((int) $house->id(), (int) $this->fill($this->reader)?->creative?->id());

    $city = $this->createBooking($paid, ['test_end'], $this->day(-1), $this->day(1), scope: 'region:north');
    $this->assertSame(Fill::HOUSE, $this->fill($this->reader, ['region:south'])?->source);
    $fill = $this->fill($this->reader, ['region:north']);
    $this->assertSame(Fill::BOOKING, $fill?->source);
    $this->assertSame((int) $city->id(), (int) $fill->booking?->id());

    $city->stop('test')->save();
    $this->assertSame(Fill::HOUSE, $this->fill($this->reader, ['region:north'])?->source);

    $draft = $this->createBooking($paid, ['test_end'], $this->day(0), $this->day(0), AdBooking::DRAFT);
    $this->assertSame(Fill::HOUSE, $this->fill($this->reader)?->source);
    $this->assertSame((int) $draft->id(), (int) $this->fill($this->staff)?->booking?->id());
    $this->assertNotNull($past->id());
  }

  /**
   * A page's own settings can switch a space off.
   */
  public function testPageOverride(): void {
    $this->createCreative(['leaderboard', 'rectangle'], ['house' => TRUE, 'state' => AdCreative::APPROVED]);
    $page = $this->createUser();
    $resolver = $this->container->get('ad_inventory.resolver');
    $scope = new AdScope($page, [], new CacheableMetadata());
    $cacheability = new CacheableMetadata();
    $this->assertNotNull($resolver->resolve($this->slot, $scope, $this->reader, $cacheability));
    $this->assertContains('ad_override:user:' . $page->id(), $cacheability->getCacheTags());

    $this->container->get('ad_inventory.overrides')->set($page, 'test_end', ['off' => TRUE]);
    $resolver = new AdResolver(
      $this->container->get('entity_type.manager'),
      $this->container->get('ad_inventory.site_day'),
      $this->container->get('ad_inventory.overrides'),
    );
    $this->assertNull($resolver->resolve($this->slot, $scope, $this->reader, new CacheableMetadata()));
  }

  /**
   * Every page with a space varies by role and carries the space's tags.
   */
  public function testCacheability(): void {
    $cacheability = new CacheableMetadata();
    $this->fill($this->reader, [], $cacheability);
    $this->assertContains('user.permissions', $cacheability->getCacheContexts());
    $this->assertContains(AdSlot::tag('test_end'), $cacheability->getCacheTags());
    $this->assertContains(AdResolver::DAY_TAG, $cacheability->getCacheTags());
    $this->assertSame(-1, $cacheability->getCacheMaxAge());
  }

  /**
   * Counting adds to daily totals and refuses unknown sources.
   */
  public function testCounter(): void {
    $creative = $this->createCreative(['leaderboard', 'rectangle']);
    $counter = $this->container->get('ad_inventory.counter');
    $counter->view('test_end', (int) $creative->id(), 'house');
    $counter->view('test_end', (int) $creative->id(), 'house', 2);
    $counter->click('test_end', (int) $creative->id(), 'house');
    $counter->view('test_end', (int) $creative->id(), 'somebody else');
    $rows = $counter->rows((int) $creative->id());
    $this->assertCount(1, $rows);
    $row = (array) reset($rows);
    $this->assertSame(3, (int) $row['views']);
    $this->assertSame(1, (int) $row['clicks']);
  }

  /**
   * The daily run stops a booking a site's subscriber refuses.
   */
  public function testDailyValidation(): void {
    $creative = $this->createCreative(['leaderboard', 'rectangle'], ['state' => AdCreative::APPROVED]);
    $booking = $this->createBooking($creative, ['test_end'], $this->day(0), $this->day(3));
    $this->container->get('event_dispatcher')->addListener(AdBookingValidateEvent::NAME, static function (AdBookingValidateEvent $event): void {
      $event->stop('The register cancelled the show');
    });
    $stopped = $this->container->get('ad_inventory.boundaries')->run(TRUE);
    $this->assertSame([(int) $booking->id()], $stopped);
    $booking = AdBooking::load($booking->id());
    $this->assertSame(AdBooking::STOPPED, $booking->state());
    $this->assertSame('The register cancelled the show', $booking->get('state_reason')->value);
  }

}
