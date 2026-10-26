<?php

declare(strict_types=1);

namespace Drupal\Tests\appointments\Kernel;

use Drupal\appointments\Entity\AppointmentInterface;
use Drupal\appointments\Ics;
use Drupal\appointments\SlotUnavailableException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\TestWith;

/**
 * Booking, moving, cancelling, and the mails they send.
 */
#[Group('appointments')]
#[RunTestsInSeparateProcesses]
final class BookingManagerTest extends AppointmentsKernelTestBase {

  /**
   * A free booking is confirmed at once and both sides are told.
   */
  public function testFreeBooking(): void {
    $service = $this->service(30, 10);
    $start = $this->ts('2026-10-26 09:00');
    $appointment = $this->draft($service, $start);
    $pay = $this->container->get('appointments.booking')->book($appointment);

    $this->assertNull($pay);
    $this->assertSame(AppointmentInterface::CONFIRMED, $appointment->getStatus());
    $this->assertSame($start + 1800, $appointment->getEnd());
    $this->assertSame($start + 2400, $appointment->getBusyUntil());
    $this->assertMatchesRegularExpression('/^[0-9a-f]{48}$/', $appointment->getToken());
    $this->assertSame('none', $appointment->get('payment')->value);

    $mails = $this->mails();
    $this->assertCount(2, $mails);
    $this->assertSame('appointments_confirmed.client', $mails[0]['id']);
    $this->assertSame('client@example.com', $mails[0]['to']);
    $this->assertStringContainsString('/appointment/' . $appointment->getToken(), $mails[0]['body']);
    $this->assertStringContainsString('/ics', $mails[0]['body']);
    $this->assertSame('appointments_confirmed.staff', $mails[1]['id']);
    $this->assertSame('clinic@example.com', $mails[1]['to']);
    $this->assertStringContainsString('3001234567', $mails[1]['body']);
  }

  /**
   * The second client racing for the same start loses.
   */
  public function testNoDoubleBooking(): void {
    $service = $this->service(30);
    $start = $this->ts('2026-10-26 09:00');
    $booking = $this->container->get('appointments.booking');
    $booking->book($this->draft($service, $start));
    $this->expectException(SlotUnavailableException::class);
    $booking->book($this->draft($service, $start, 'other@example.com'));
  }

  /**
   * Starts that are not offered cannot be booked.
   *
   * @param string $when
   *   A Bogota wall-clock time.
   */
  #[TestWith(['2026-10-26 09:10'])]
  #[TestWith(['2026-10-26 07:30'])]
  #[TestWith(['2026-10-27 09:00'])]
  #[TestWith(['2026-11-02 09:00'])]
  public function testOffGridStarts(string $when): void {
    $this->expectException(SlotUnavailableException::class);
    $this->container->get('appointments.booking')->book($this->draft($this->service(30), $this->ts($when)));
  }

  /**
   * An unpublished service cannot be booked.
   */
  public function testUnpublishedService(): void {
    $service = $this->service(30);
    $service->setUnpublished()->save();
    $this->expectException(SlotUnavailableException::class);
    $this->container->get('appointments.booking')->book($this->draft($service, $this->ts('2026-10-26 09:00')));
  }

  /**
   * Without a checkout handler every booking is free, whatever the mode.
   */
  public function testPaymentModeNeedsHandler(): void {
    $this->config('appointments.settings')->set('payment.mode', 'full')->save();
    $booking = $this->container->get('appointments.booking');
    $service = $this->service(30, 0, '50000');
    $this->assertFalse($booking->hasCheckout());
    $this->assertSame('none', $booking->paymentMode());
    $this->assertSame('0.00', $booking->amountDue($service));
    $appointment = $this->draft($service, $this->ts('2026-10-26 09:00'));
    $this->assertNull($booking->book($appointment));
    $this->assertSame(AppointmentInterface::CONFIRMED, $appointment->getStatus());
  }

  /**
   * Moving keeps the appointment, frees the old start, and tells both sides.
   */
  public function testReschedule(): void {
    $service = $this->service(30);
    $booking = $this->container->get('appointments.booking');
    $appointment = $this->draft($service, $this->ts('2026-10-26 09:00'));
    $booking->book($appointment);
    $id = $appointment->id();

    // Moving onto its own start's neighbour is fine; it does not block itself.
    $booking->reschedule($appointment, $this->ts('2026-10-26 09:30'));
    $this->assertSame($id, $appointment->id());
    $this->assertSame($this->ts('2026-10-26 10:00'), $appointment->getEnd());
    $this->assertContains('09:00', $this->times($service, '2026-10-26'));
    $this->assertSame('appointments_rescheduled.client', $this->mails()[2]['id']);

    // Onto another booking it is refused.
    $other = $this->draft($service, $this->ts('2026-10-26 10:00'), 'b@example.com');
    $booking->book($other);
    $this->expectException(SlotUnavailableException::class);
    $booking->reschedule($appointment, $this->ts('2026-10-26 10:00'));
  }

  /**
   * Clients may change until the cutoff; cancelling frees the time.
   */
  public function testCancelAndCutoff(): void {
    $service = $this->service(30);
    $booking = $this->container->get('appointments.booking');
    $appointment = $this->draft($service, $this->ts('2026-10-26 09:00'));
    $booking->book($appointment);

    $this->at('2026-10-26 06:59');
    $this->assertTrue($booking->clientCanChange($appointment));
    $this->at('2026-10-26 07:00');
    $this->assertFalse($booking->clientCanChange($appointment));

    $this->at('2026-10-26 06:00');
    $booking->cancel($appointment, 'client');
    $this->assertSame(AppointmentInterface::CANCELLED, $appointment->getStatus());
    $this->assertFalse($booking->clientCanChange($appointment));
    $this->assertContains('09:00', $this->times($service, '2026-10-26'));
    $ids = array_column($this->mails(), 'id');
    $this->assertSame(['appointments_cancelled.client', 'appointments_cancelled.staff'], array_slice($ids, -2));

    // A second cancel is a no-op: no more mail.
    $booking->cancel($appointment, 'client');
    $this->assertCount(count($ids), $this->mails());
  }

  /**
   * Cron releases unpaid holds and tells the client.
   */
  public function testExpireHolds(): void {
    $service = $this->service(30);
    $stale = $this->existing($service, $this->ts('2026-10-26 09:00'), AppointmentInterface::PENDING, $this->ts('2026-10-26 05:30'));
    $live = $this->existing($service, $this->ts('2026-10-26 10:00'), AppointmentInterface::PENDING, $this->ts('2026-10-26 06:20'));

    $this->assertSame(1, $this->container->get('appointments.booking')->expireHolds());
    $storage = $this->container->get('entity_type.manager')->getStorage('appointment');
    $this->assertSame(AppointmentInterface::CANCELLED, $storage->loadUnchanged($stale->id())->getStatus());
    $this->assertSame(AppointmentInterface::PENDING, $storage->loadUnchanged($live->id())->getStatus());
    $this->assertSame(['appointments_expired.client'], array_column($this->mails(), 'id'));
  }

  /**
   * Tokens open the right appointment, and only well-formed ones are looked up.
   */
  public function testLoadByToken(): void {
    $booking = $this->container->get('appointments.booking');
    $appointment = $this->draft($this->service(30), $this->ts('2026-10-26 09:00'));
    $booking->book($appointment);
    $this->assertSame($appointment->id(), $booking->loadByToken($appointment->getToken())?->id());
    $this->assertNull($booking->loadByToken(str_repeat('0', 48)));
    $this->assertNull($booking->loadByToken("' OR 1=1"));
  }

  /**
   * The calendar invite is valid iCalendar with the right times.
   */
  public function testIcs(): void {
    $appointment = $this->draft($this->service(30), $this->ts('2026-10-26 09:00'));
    $this->container->get('appointments.booking')->book($appointment);
    $ics = Ics::build($appointment, 'Clinic; Main, Branch', 'https://example.com/appointment/x', 'example.com');
    $this->assertStringStartsWith("BEGIN:VCALENDAR\r\n", $ics);
    $this->assertStringContainsString("DTSTART:20261026T140000Z\r\n", $ics);
    $this->assertStringContainsString("DTEND:20261026T143000Z\r\n", $ics);
    $this->assertStringContainsString('LOCATION:Clinic\; Main\, Branch', $ics);
    foreach (explode("\r\n", $ics) as $line) {
      $this->assertLessThanOrEqual(75, strlen($line));
    }
  }

}
