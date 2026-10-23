<?php

declare(strict_types=1);

namespace Drupal\Tests\appointments\Kernel;

use Drupal\appointments\Entity\AppointmentInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Which start times the calendar offers.
 */
#[Group('appointments')]
#[RunTestsInSeparateProcesses]
final class CalendarTest extends AppointmentsKernelTestBase {

  /**
   * An open morning offers every aligned start that fits.
   */
  public function testOpenMorning(): void {
    $service = $this->service(30);
    $this->assertSame(
      ['08:00', '08:30', '09:00', '09:30', '10:00', '10:30', '11:00', '11:30'],
      $this->times($service, '2026-10-26'),
    );
    // A 90-minute service must end by closing time.
    $this->assertSame(['08:00', '08:30', '09:00', '09:30', '10:00', '10:30'], $this->times($this->service(90, 0, '0', 'surgery'), '2026-10-26'));
  }

  /**
   * The minimum notice hides starts that are too soon.
   */
  public function testNotice(): void {
    $this->at('2026-10-26 09:10');
    $this->assertSame(['11:30'], $this->times($this->service(30), '2026-10-26'));
  }

  /**
   * Closed days, days without hours, the past and beyond the horizon.
   */
  public function testClosedDays(): void {
    $service = $this->service(30);
    $calendar = $this->container->get('appointments.calendar');
    // A closure.
    $this->assertSame([], $this->times($service, '2026-11-02'));
    // Tuesday has no hours.
    $this->assertSame([], $this->times($service, '2026-10-27'));
    // The past.
    $this->assertSame([], $this->times($service, '2026-10-19'));
    // 2026-11-30 is a Monday 35 days out, past the 30-day horizon.
    $this->assertSame([], $this->times($service, '2026-11-30'));
    $this->assertNotSame([], $this->times($service, '2026-11-23'));
    // Malformed dates are closed, not errors.
    $this->assertSame([], $this->times($service, '2026-02-30'));
    // Open dates are the Mondays in range except the closure.
    $this->assertSame(['2026-10-26', '2026-11-09', '2026-11-16', '2026-11-23'], $calendar->openDates());
  }

  /**
   * A booking and its buffer make the overlapping starts busy.
   */
  public function testBookingsAndBuffers(): void {
    $checkup = $this->service(30, 15, '0', 'checkup');
    $this->existing($checkup, $this->ts('2026-10-26 09:00'));
    // Busy 09:00-09:45: 09:00 and 09:30 overlap it; 08:30 ends at 09:00.
    $this->assertSame(
      ['08:00', '08:30', '10:00', '10:30', '11:00', '11:30'],
      $this->times($this->service(30), '2026-10-26'),
    );
    // The new appointment's own buffer counts too: 08:30 + 30 + 15 runs into
    // 09:00. A buffer may run past closing time; the appointment may not.
    $this->assertSame(['08:00', '10:00', '10:30', '11:00', '11:30'], $this->times($checkup, '2026-10-26'));
  }

  /**
   * Capacity lets parallel appointments share a start.
   */
  public function testCapacity(): void {
    $service = $this->service(30);
    $this->existing($service, $this->ts('2026-10-26 09:00'));
    $this->assertNotContains('09:00', $this->times($service, '2026-10-26'));
    $this->config('appointments.settings')->set('capacity', 2)->save();
    $this->assertContains('09:00', $this->times($service, '2026-10-26'));
    $this->existing($service, $this->ts('2026-10-26 09:00'));
    $this->assertNotContains('09:00', $this->times($service, '2026-10-26'));
  }

  /**
   * Only confirmed appointments and live holds block time.
   */
  public function testStatusesThatBlock(): void {
    $service = $this->service(30);
    $this->existing($service, $this->ts('2026-10-26 08:00'), AppointmentInterface::CANCELLED);
    $this->existing($service, $this->ts('2026-10-26 08:30'), AppointmentInterface::PENDING, $this->ts('2026-10-26 05:59'));
    $live = $this->existing($service, $this->ts('2026-10-26 09:00'), AppointmentInterface::PENDING, $this->ts('2026-10-26 06:30'));
    $times = $this->times($service, '2026-10-26');
    $this->assertContains('08:00', $times);
    $this->assertContains('08:30', $times);
    $this->assertNotContains('09:00', $times);
    // The appointment being moved does not block itself.
    $this->assertContains('09:00', $this->times($service, '2026-10-26', (int) $live->id()));
  }

  /**
   * Two windows on one day, in order, without crossing the gap.
   */
  public function testSplitDay(): void {
    $this->config('appointments.settings')->set('hours', [
      ['day' => 1, 'start' => '14:00', 'end' => '15:00'],
      ['day' => 1, 'start' => '08:00', 'end' => '09:00'],
    ])->save();
    $this->assertSame(['08:00', '08:30', '14:00', '14:30'], $this->times($this->service(30), '2026-10-26'));
  }

}
