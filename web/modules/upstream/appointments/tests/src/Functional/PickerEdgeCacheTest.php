<?php

declare(strict_types=1);

namespace Drupal\Tests\appointments\Functional;

use Drupal\appointments\Entity\AppointmentInterface;
use Drupal\edge_purge\EdgeTags;
use Drupal\Tests\BrowserTestBase;
use Drupal\user\RoleInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The booking page through Drupal's page cache and edge_purge, as at the edge.
 *
 * The calendar's time zone is chosen so that it is about noon there and the
 * day itself is closed: midnight and the first free start (tomorrow) are
 * hours away, so the page lives exactly one step (30 minutes) whenever the
 * test runs.
 */
#[Group('appointments')]
#[RunTestsInSeparateProcesses]
final class PickerEdgeCacheTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['appointments', 'page_cache', 'dynamic_page_cache', 'edge_purge'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // A time zone where it is between 06:00 and 18:00 now.
    $offset = 12 - (int) gmdate('G');
    if ($offset > 14) {
      $offset -= 24;
    }
    if ($offset < -12) {
      $offset += 24;
    }
    // Etc/GMT-5 is five hours ahead of UTC.
    $tz = $offset === 0 ? 'UTC' : sprintf('Etc/GMT%+d', -$offset);
    $today = (int) (new \DateTimeImmutable('now', new \DateTimeZone($tz)))->format('N');
    $hours = [];
    foreach (range(1, 7) as $day) {
      if ($day !== $today) {
        $hours[] = ['day' => $day, 'start' => '00:00', 'end' => '24:00'];
      }
    }
    $this->config('appointments.settings')
      ->set('timezone', $tz)
      ->set('hours', $hours)
      ->set('slot_step', 30)
      ->set('lead_time', 60)
      ->set('horizon', 3)
      ->save();
    $this->config('system.performance')->set('cache.page.max_age', 86400)->save();
    // The CDN lifetime edge_purge gives every other page: a year.
    $settings['settings']['edge_purge_cdn_max_age'] = (object) ['value' => 31536000, 'required' => TRUE];
    $this->writeSettings($settings);
    $this->container->get('entity_type.manager')->getStorage('appointment_service')->create([
      'key' => 'consult',
      'label' => 'Consult',
      'duration' => 30,
      'status' => TRUE,
    ])->save();
    user_role_grant_permissions(RoleInterface::ANONYMOUS_ID, ['book appointments']);
  }

  /**
   * The seconds of a max-age directive in a header value.
   */
  private function maxAge(string $header): int {
    $value = (string) $this->getSession()->getResponseHeader($header);
    $this->assertMatchesRegularExpression('/max-age=\d+/', $value, "$header: $value");
    preg_match('/max-age=(\d+)/', $value, $m);
    return (int) $m[1];
  }

  /**
   * Cached for one step, purged by a booking, re-aged when served again.
   */
  public function testCachedUntilClockOrBookingChangesIt(): void {
    $assert = $this->assertSession();

    $this->drupalGet('/book');
    $assert->statusCodeEquals(200);
    $assert->responseHeaderEquals('X-Drupal-Cache', 'MISS');
    $this->assertTrue((bool) preg_match('/\bpublic\b/', (string) $this->getSession()->getResponseHeader('Cache-Control')));
    $this->assertSame(1800, $this->maxAge('Cache-Control'));
    // The page's own lifetime at the edge, not edge_purge's year.
    $this->assertSame(1800, $this->maxAge(EdgeTags::CDN_HEADER));
    $expires = strtotime((string) $this->getSession()->getResponseHeader('Expires'));
    $this->assertEqualsWithDelta(time() + 1800, $expires, 5);
    // Tagged for the purge, and the deadline never leaves the site.
    $this->assertContains(EdgeTags::hash('appointment_list'), explode(',', (string) $this->getSession()->getResponseHeader(EdgeTags::HEADER)));
    $assert->responseHeaderDoesNotExist('X-Appointments-Fresh-Until');

    // Served again from Drupal's page cache, with what it has left.
    sleep(2);
    $this->drupalGet('/book');
    $assert->responseHeaderEquals('X-Drupal-Cache', 'HIT');
    $this->assertLessThan(1800, $this->maxAge('Cache-Control'));
    $this->assertLessThan(1800, $this->maxAge(EdgeTags::CDN_HEADER));
    $assert->responseHeaderDoesNotExist('X-Appointments-Fresh-Until');

    // A booking takes the page out of every cache.
    // The href itself: an element found on a page is looked up again on
    // whatever page is loaded when it is read.
    $first = (string) $this->getSession()->getPage()->find('css', 'a.appointments-slot')?->getAttribute('href');
    $this->assertSame(1, preg_match('#/book/1/(\d+)#', $first, $m), $first);
    $appointment = $this->container->get('entity_type.manager')->getStorage('appointment')->create([
      'service' => 1,
      'start' => (int) $m[1],
      'name' => 'Ana',
      'email' => 'ana@example.com',
      'phone' => '1',
    ]);
    assert($appointment instanceof AppointmentInterface);
    $this->container->get('appointments.booking')->book($appointment);
    $this->drupalGet('/book');
    $assert->responseHeaderEquals('X-Drupal-Cache', 'MISS');
    $this->assertSession()->linkByHrefNotExists($first, 'The booked start is gone.');
    $this->assertNotNull($this->getSession()->getPage()->find('css', 'a.appointments-slot'), 'The next start is offered.');
    $queued = $this->container->get('database')->query("SELECT COUNT(*) FROM {edge_purge_queue} WHERE tag = 'appointment_list'")->fetchField();
    $this->assertGreaterThan(0, (int) $queued, 'The edge purge queue has the page.');

    // Moving an appointment: one client's page, kept by nobody.
    $this->drupalGet('/book', ['query' => ['reschedule' => $appointment->getToken()]]);
    $assert->statusCodeEquals(200);
    $cache_control = (string) $this->getSession()->getResponseHeader('Cache-Control');
    $this->assertStringContainsString('private', $cache_control);
    $this->assertStringContainsString('no-cache', $cache_control);
    $assert->responseHeaderDoesNotExist(EdgeTags::CDN_HEADER);
    $this->drupalGet('/book', ['query' => ['reschedule' => $appointment->getToken()]]);
    $this->assertNotSame('HIT', $this->getSession()->getResponseHeader('X-Drupal-Cache'));
  }

  /**
   * A site whose page max-age is 0: private, yet Drupal's copy still expires.
   */
  public function testPrivateSiteExpiresInDrupal(): void {
    $this->config('system.performance')->set('cache.page.max_age', 0)->save();
    $this->drupalGet('/book');
    $this->assertStringContainsString('private', (string) $this->getSession()->getResponseHeader('Cache-Control'));
    $this->assertSession()->responseHeaderDoesNotExist(EdgeTags::CDN_HEADER);
    $expires = strtotime((string) $this->getSession()->getResponseHeader('Expires'));
    $this->assertEqualsWithDelta(time() + 1800, $expires, 5);
  }

}
