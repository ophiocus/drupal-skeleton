<?php

declare(strict_types=1);

namespace Drupal\Tests\ad_inventory\Kernel;

use Drupal\ad_inventory\AdCounter;
use Drupal\ad_inventory\AdResolver;
use Drupal\ad_inventory\Entity\AdBooking;
use Drupal\ad_inventory\Entity\AdCreative;
use Drupal\ad_inventory\Entity\AdSlot;
use Drupal\ad_inventory\Fill;
use Drupal\ad_inventory\Rotation;
use Drupal\ad_inventory\Scope\AdScope;
use Drupal\ad_inventory\SlotBuilder;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Routing\RouteMatch;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\ad_inventory\Traits\AdFixturesTrait;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Routing\Route;

/**
 * Spaces shared by several placements, each with several creatives.
 *
 * Outer rotation: a space shows one of the placements in force, the one with
 * the fewest views this week next. Inner rotation: a placement shows one of
 * its creatives, the same way. An exclusive booking holds a space alone.
 * Only pairs a space could have shown count.
 */
#[Group('ad_inventory')]
#[RunTestsInSeparateProcesses]
final class RotationTest extends KernelTestBase {

  use AdFixturesTrait;
  use MediaTypeCreationTrait;

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
    $this->createSlot('spot');
  }

  /**
   * A day relative to the site's today.
   */
  private function day(int $offset): string {
    $today = $this->container->get('ad_inventory.site_day')->today();
    return (new \DateTimeImmutable($today))->modify(sprintf('%+d days', $offset))->format('Y-m-d');
  }

  /**
   * An approved creative with both renditions the space needs.
   */
  private function art(): AdCreative {
    return $this->createCreative(['leaderboard', 'rectangle'], ['state' => AdCreative::APPROVED]);
  }

  /**
   * A banner (not exclusive) in the space for this week.
   *
   * @param \Drupal\ad_inventory\Entity\AdCreative[] $creatives
   *   Its creatives, in order.
   * @param bool $exclusive
   *   TRUE for a sponsorship that holds the space alone.
   */
  private function banner(array $creatives, bool $exclusive = FALSE): AdBooking {
    $booking = $this->createBooking($creatives[0], ['spot'], $this->day(-1), $this->day(5), values: ['exclusive' => $exclusive]);
    $booking->setCreatives($creatives)->save();
    return $booking;
  }

  /**
   * What fills the space for a reader (a new resolver: it keeps bookings).
   */
  private function fill(?CacheableMetadata $cacheability = NULL): ?Fill {
    $resolver = new AdResolver(
      $this->container->get('entity_type.manager'),
      $this->container->get('ad_inventory.site_day'),
      $this->container->get('ad_inventory.overrides'),
    );
    return $resolver->resolve(AdSlot::load('spot'), new AdScope(NULL, [], new CacheableMetadata()), new AnonymousUserSession(), $cacheability ?? new CacheableMetadata());
  }

  /**
   * A booking keeps a set of creatives in order; its first is creative().
   */
  public function testCreativeSet(): void {
    [$a, $b, $c] = [$this->art(), $this->art(), $this->art()];
    $booking = $this->banner([$b, $a, $b]);
    $booking = AdBooking::load($booking->id());
    $this->assertSame([(int) $b->id(), (int) $a->id()], $booking->creativeIds());
    $this->assertSame((int) $b->id(), (int) $booking->creative()->id());

    $query = \Drupal::entityTypeManager()->getStorage('ad_booking')->getQuery()->accessCheck(FALSE);
    $this->assertSame([(int) $booking->id()], array_map('intval', array_values(AdBooking::whereCreative($query, (int) $a->id())->execute())));
    $query = \Drupal::entityTypeManager()->getStorage('ad_booking')->getQuery()->accessCheck(FALSE);
    $this->assertSame([], AdBooking::whereCreative($query, (int) $c->id())->execute());

    // A booking given only "creative" (an old caller) still has its set.
    $old = $this->createBooking($c, ['spot'], $this->day(0), $this->day(1));
    $this->assertSame([(int) $c->id()], AdBooking::load($old->id())->creativeIds());
  }

  /**
   * Banners share the space; an exclusive booking holds it alone.
   */
  public function testRoster(): void {
    $first = $this->banner([$this->art()]);
    $second = $this->banner([$this->art(), $this->art()]);
    $fill = $this->fill();
    $this->assertSame(Fill::BOOKING, $fill?->source);
    $this->assertSame([(int) $first->id(), (int) $second->id()], array_map(static fn ($p) => (int) $p->booking->id(), $fill->placements));
    $this->assertCount(2, $fill->placements[1]->creatives);
    $this->assertSame('booking:' . $first->id(), $fill->countSource());

    $sponsor = $this->banner([$this->art()], TRUE);
    $fill = $this->fill();
    $this->assertCount(1, $fill->placements);
    $this->assertSame((int) $sponsor->id(), (int) $fill->booking->id());
  }

  /**
   * Pages carry the space's tags, not every booking's and creative's.
   */
  public function testCacheTags(): void {
    $art = $this->art();
    $cacheability = new CacheableMetadata();
    $this->assertNull($this->fill($cacheability));
    $this->assertNotContains('ad_booking_list', $cacheability->getCacheTags());
    $this->assertNotContains('ad_creative_list', $cacheability->getCacheTags());
    $this->assertContains(AdCreative::HOUSE_TAG, $cacheability->getCacheTags(), 'A new house banner must reach a page that showed nothing.');

    $this->assertNotContains(AdSlot::tag('spot'), $art->getCacheTagsToInvalidate(), 'A creative in no booking renews no space.');
    $this->banner([$art]);
    $this->assertContains(AdSlot::tag('spot'), AdCreative::load($art->id())->getCacheTagsToInvalidate());
    $house = $this->createCreative(['leaderboard', 'rectangle'], ['state' => AdCreative::APPROVED, 'house' => TRUE]);
    $this->assertContains(AdCreative::HOUSE_TAG, $house->getCacheTagsToInvalidate());
  }

  /**
   * Fewest views this week goes next: the placement, then its creative.
   */
  public function testPickFewestViews(): void {
    $counter = $this->container->get('ad_inventory.counter');
    $rotation = $this->container->get('ad_inventory.rotation');
    [$a1, $b1, $b2] = [$this->art(), $this->art(), $this->art()];
    $a = $this->banner([$a1]);
    $b = $this->banner([$b1, $b2]);
    $ids = [(int) $a->id(), (int) $b->id()];

    // Nothing seen yet: the earliest booking, its first creative.
    $this->assertSame([(int) $a->id(), (int) $a1->id()], $rotation->pick('spot', $ids));
    $counter->view('spot', (int) $a1->id(), AdCounter::bookingSource((int) $a->id()), 3);
    $this->assertSame([(int) $b->id(), (int) $b1->id()], $rotation->pick('spot', $ids));
    $counter->view('spot', (int) $b1->id(), AdCounter::bookingSource((int) $b->id()), 2);
    $this->assertSame([(int) $b->id(), (int) $b2->id()], $rotation->pick('spot', $ids), 'Inner rotation: the unseen creative.');
    $counter->view('spot', (int) $b2->id(), AdCounter::bookingSource((int) $b->id()), 1);
    $this->assertSame((int) $a->id(), $rotation->pick('spot', $ids)[0], 'Even: the earliest booking.');

    // Views in another space, or under another source, are not this one's.
    $counter->view('other', (int) $a1->id(), AdCounter::bookingSource((int) $a->id()), 50);
    $counter->view('spot', (int) $a1->id(), 'booking', 50);
    $this->assertSame((int) $a->id(), $rotation->pick('spot', $ids)[0]);

    // Stopped, out of the space, or unknown: never picked.
    $a->stop('test')->save();
    $this->assertSame((int) $b->id(), $rotation->pick('spot', $ids)[0]);
    $this->assertNull($rotation->pick('other', $ids));
    $this->assertNull($rotation->pick('spot', [999]));
  }

  /**
   * The week runs Monday to Sunday.
   */
  public function testWeek(): void {
    [$monday, $sunday] = $this->container->get('ad_inventory.counter')->week();
    $this->assertSame('1', (new \DateTimeImmutable($monday))->format('N'));
    $this->assertSame('7', (new \DateTimeImmutable($sunday))->format('N'));
    $today = $this->container->get('ad_inventory.site_day')->today();
    $this->assertTrue($monday <= $today && $today <= $sunday);
  }

  /**
   * Only a pair the space could have shown counts.
   */
  public function testCountable(): void {
    $rotation = $this->container->get('ad_inventory.rotation');
    [$art, $stranger] = [$this->art(), $this->art()];
    $booking = $this->banner([$art]);
    $source = AdCounter::bookingSource((int) $booking->id());
    $this->assertTrue($rotation->countable('spot', (int) $art->id(), $source));
    $this->assertTrue($rotation->countable('spot', (int) $art->id(), 'booking'), 'A page cached before per-booking counts.');
    $this->assertFalse($rotation->countable('spot', (int) $stranger->id(), $source), 'Not its creative.');
    $this->assertFalse($rotation->countable('nowhere', (int) $art->id(), $source), 'No such space.');
    $this->assertFalse($rotation->countable('spot', (int) $art->id(), 'booking:999'), 'No such booking.');
    $this->assertFalse($rotation->countable('spot', (int) $art->id(), 'house'), 'Not a house banner.');
    $this->assertFalse($rotation->countable('spot', 0, 'provider:none'), 'No such provider.');

    $house = $this->createCreative(['leaderboard', 'rectangle'], ['state' => AdCreative::APPROVED, 'house' => TRUE]);
    $this->assertTrue($rotation->countable('spot', (int) $house->id(), 'house'));

    $booking->stop('test')->save();
    $this->assertFalse($rotation->countable('spot', (int) $art->id(), $source), 'Stopped.');

    $this->assertTrue(Rotation::isRobot('Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'));
    $this->assertTrue(Rotation::isRobot(''));
    $this->assertFalse(Rotation::isRobot('Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Mobile Safari/537.36'));
  }

  /**
   * Error pages show no space.
   */
  public function testNoSpaceOnErrorPages(): void {
    $this->createCreative(['leaderboard', 'rectangle'], ['state' => AdCreative::APPROVED, 'house' => TRUE]);
    $this->container->get('router.builder')->rebuild();
    $builder = fn (string $route): SlotBuilder => new SlotBuilder(
      $this->container->get('ad_inventory.resolver'),
      $this->container->get('ad_inventory.scopes'),
      new AnonymousUserSession(),
      $this->container->get('entity_type.manager'),
      $this->container->get('file_url_generator'),
      $this->container->get('config.factory'),
      new RouteMatch($route, new Route('/x')),
    );
    $this->assertArrayHasKey('#theme', $builder('entity.user.canonical')->build('spot'));
    $error = $builder('system.404')->build('spot');
    $this->assertArrayNotHasKey('#theme', $error);
    $this->assertContains('route.name', $error['#cache']['contexts'] ?? []);
  }

  /**
   * The roster carries the resolver's maximum at most.
   */
  public function testRosterMax(): void {
    $art = $this->art();
    for ($i = 0; $i < AdResolver::ROSTER_MAX + 2; $i++) {
      $this->banner([$art]);
    }
    $this->assertCount(AdResolver::ROSTER_MAX, $this->fill()?->placements ?? []);
  }

}
