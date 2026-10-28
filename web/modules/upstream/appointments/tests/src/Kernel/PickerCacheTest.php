<?php

declare(strict_types=1);

namespace Drupal\Tests\appointments\Kernel;

use Drupal\appointments\Controller\BookingController;
use Drupal\appointments\Freshness;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * What the booking page depends on, and how long it stays true.
 *
 * The calendar: Monday 08:00-12:00 Bogota, 30-minute steps, two hours of
 * notice; 2026-11-02 closed.
 */
#[Group('appointments')]
#[RunTestsInSeparateProcesses]
final class PickerCacheTest extends AppointmentsKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'field', 'options', 'appointments', 'edge_purge'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('edge_purge', ['edge_purge_queue']);
  }

  /**
   * The picker's render array for a query.
   *
   * @param array<string, string> $query
   *   The query string.
   *
   * @return array<string, mixed>
   *   The render array.
   */
  private function picker(array $query = []): array {
    return BookingController::create($this->container)->picker(Request::create('/book', 'GET', $query));
  }

  /**
   * The deadline the picker attached, as seconds from now.
   *
   * @param array<string, mixed> $build
   *   The picker's render array.
   */
  private function deadline(array $build): int {
    foreach ($build['#attached']['http_header'] ?? [] as [$name, $value]) {
      if ($name === Freshness::HEADER) {
        return (int) $value - (int) $this->container->get('datetime.time')->getCurrentTime();
      }
    }
    $this->fail('The picker attached no deadline.');
  }

  /**
   * Tagged by what it shows, varied by its query and the content language.
   */
  public function testTagsAndContexts(): void {
    $this->service();
    $build = $this->picker(['service' => 'consult']);
    $tags = [
      'appointment_list',
      'appointment_service_list',
      'config:appointment_room_list',
      'config:appointments.settings',
      'config:system.date',
    ];
    foreach ($tags as $tag) {
      $this->assertContains($tag, $build['#cache']['tags']);
    }
    $contexts = [
      'url.query_args:service',
      'url.query_args:room',
      'url.query_args:from',
      'url.query_args:reschedule',
      'languages:language_content',
    ];
    foreach ($contexts as $context) {
      $this->assertContains($context, $build['#cache']['contexts']);
    }

    // With nothing to book the page is cacheable too, under the same tags:
    // the first service saved shows it.
    $this->container->get('entity_type.manager')->getStorage('appointment_service')->load(1)->setUnpublished()->save();
    $empty = $this->picker();
    $this->assertContains('appointment_service_list', $empty['#cache']['tags']);
    $this->assertSame(1800, $empty['#cache']['max-age']);
  }

  /**
   * The lifetime: earliest start minus notice, midnight, one step at most.
   */
  public function testLifetimeFollowsTheClock(): void {
    $this->service();

    // 06:00 with an hour's notice: the first start (08:00) drops out at
    // 07:00, an hour away; one step (30 minutes) is shorter.
    $this->config('appointments.settings')->set('lead_time', 60)->save();
    $build = $this->picker();
    $this->assertSame(1800, $build['#cache']['max-age']);
    $this->assertSame(1800, $this->deadline($build));

    // 06:10 with two hours' notice: the first start is 08:30; it drops out
    // at 06:30, 20 minutes on.
    $this->config('appointments.settings')->set('lead_time', 120)->save();
    $this->at('2026-10-26 06:10');
    $build = $this->picker();
    $this->assertSame(1200, $build['#cache']['max-age']);
    $this->assertSame(1200, $this->deadline($build));

    // 23:50: no start left before next Monday's; midnight moves the days.
    $this->at('2026-10-26 23:50');
    $this->assertSame(600, $this->picker()['#cache']['max-age']);
  }

  /**
   * Moving an appointment is one client's page: never cached.
   */
  public function testRescheduleIsNeverCached(): void {
    $service = $this->service();
    $appointment = $this->existing($service, $this->ts('2026-10-26 10:00'));
    $build = $this->picker(['reschedule' => $appointment->getToken()]);
    $this->assertSame(0, $build['#cache']['max-age']);
    $this->assertSame(0, $this->deadline($build));
  }

  /**
   * A booking, a move, a cancellation: the page is purged by its tag.
   */
  public function testBookingsPurgeThePage(): void {
    $service = $this->service();
    $queue = fn (): array => $this->container->get('database')
      ->query("SELECT tag FROM {edge_purge_queue} WHERE tag = 'appointment_list'")
      ->fetchCol();
    $clear = fn () => $this->container->get('database')->truncate('edge_purge_queue')->execute();
    $booking = $this->container->get('appointments.booking');

    $clear();
    $appointment = $this->draft($service, $this->ts('2026-10-26 09:00'));
    $booking->book($appointment);
    $this->assertSame(['appointment_list'], $queue(), 'A new booking purges the picker.');

    $clear();
    $booking->reschedule($appointment, $this->ts('2026-10-26 10:00'));
    $this->assertSame(['appointment_list'], $queue(), 'A move purges the picker.');

    $clear();
    $booking->cancel($appointment, 'client');
    $this->assertSame(['appointment_list'], $queue(), 'A cancellation purges the picker.');
  }

}
