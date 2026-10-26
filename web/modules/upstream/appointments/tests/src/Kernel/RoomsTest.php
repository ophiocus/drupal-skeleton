<?php

declare(strict_types=1);

namespace Drupal\Tests\appointments\Kernel;

use Drupal\appointments\SlotUnavailableException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Rooms: parallel calendars that offer some or all of the services.
 */
#[Group('appointments')]
#[RunTestsInSeparateProcesses]
final class RoomsTest extends AppointmentsKernelTestBase {

  /**
   * Each room takes one appointment at a time; any-room shows the union.
   */
  public function testParallelRooms(): void {
    $consult = $this->service(30);
    $this->room('a', [], 0);
    $this->room('b', [], 1);
    $this->existing($consult, $this->ts('2026-10-26 09:00'), room: 'a');

    $this->assertContains('09:00', $this->times($consult, '2026-10-26'));
    $this->assertNotContains('09:00', $this->times($consult, '2026-10-26', room: 'a'));
    $this->assertContains('09:00', $this->times($consult, '2026-10-26', room: 'b'));

    $this->existing($consult, $this->ts('2026-10-26 09:00'), room: 'b');
    $this->assertNotContains('09:00', $this->times($consult, '2026-10-26'));
  }

  /**
   * A room offers only its services, and its bookings block only itself.
   */
  public function testServicesPerRoom(): void {
    $consult = $this->service(30, 0, '0', 'consult');
    $groom = $this->service(60, 0, '0', 'groom');
    $this->room('surgery', ['consult']);
    $this->room('salon', ['groom']);
    $calendar = $this->container->get('appointments.calendar');

    $this->assertSame(['surgery'], array_keys($calendar->roomsFor($consult)));
    $this->assertSame(['salon'], array_keys($calendar->roomsFor($groom)));
    // Asking for a room that does not offer the service gives nothing.
    $this->assertSame([], $this->times($consult, '2026-10-26', room: 'salon'));

    $this->existing($groom, $this->ts('2026-10-26 09:00'), room: 'salon');
    $this->assertContains('09:00', $this->times($consult, '2026-10-26'));
    $this->assertNotContains('09:00', $this->times($groom, '2026-10-26'));
  }

  /**
   * A service no bookable room offers cannot be booked at all.
   */
  public function testServiceWithoutRoom(): void {
    $consult = $this->service(30, 0, '0', 'consult');
    $this->room('salon', ['groom']);
    $this->assertSame([], $this->times($consult, '2026-10-26'));
  }

  /**
   * An appointment from before rooms existed takes one room of the calendar.
   */
  public function testRoomlessAppointmentTakesOneRoom(): void {
    $consult = $this->service(30);
    $this->existing($consult, $this->ts('2026-10-26 09:00'));
    $this->room('a');
    $this->room('b');
    $this->assertContains('09:00', $this->times($consult, '2026-10-26'));
    $this->existing($consult, $this->ts('2026-10-26 09:00'), room: 'a');
    $this->assertNotContains('09:00', $this->times($consult, '2026-10-26'));
  }

  /**
   * Booking assigns the chosen room, or the first free one.
   */
  public function testBookingAssignsRooms(): void {
    $consult = $this->service(30);
    $this->room('a', [], 0);
    $this->room('b', [], 1);
    $booking = $this->container->get('appointments.booking');
    $start = $this->ts('2026-10-26 09:00');

    $chosen = $this->draft($consult, $start);
    $chosen->set('room', 'b');
    $booking->book($chosen);
    $this->assertSame('b', $chosen->getRoomId());

    $any = $this->draft($consult, $start, 'x@example.com');
    $booking->book($any);
    $this->assertSame('a', $any->getRoomId());

    $this->expectException(SlotUnavailableException::class);
    $booking->book($this->draft($consult, $start, 'y@example.com'));
  }

  /**
   * Moving keeps the room while it is free there, and changes it otherwise.
   */
  public function testRescheduleKeepsOrChangesRoom(): void {
    $consult = $this->service(30);
    $this->room('a', [], 0);
    $this->room('b', [], 1);
    $booking = $this->container->get('appointments.booking');

    $mine = $this->draft($consult, $this->ts('2026-10-26 09:00'));
    $mine->set('room', 'b');
    $booking->book($mine);
    $booking->reschedule($mine, $this->ts('2026-10-26 10:00'));
    $this->assertSame('b', $mine->getRoomId());

    $this->existing($consult, $this->ts('2026-10-26 11:00'), room: 'b');
    $booking->reschedule($mine, $this->ts('2026-10-26 11:00'));
    $this->assertSame('a', $mine->getRoomId());
  }

  /**
   * Without rooms the capacity setting still decides.
   */
  public function testNoRoomsUsesCapacity(): void {
    $consult = $this->service(30);
    $this->config('appointments.settings')->set('capacity', 2)->save();
    $this->existing($consult, $this->ts('2026-10-26 09:00'));
    $this->assertContains('09:00', $this->times($consult, '2026-10-26'));
    $appointment = $this->draft($consult, $this->ts('2026-10-26 09:00'));
    $this->container->get('appointments.booking')->book($appointment);
    $this->assertSame('', $appointment->getRoomId());
    $this->assertNotContains('09:00', $this->times($consult, '2026-10-26'));
  }

}
