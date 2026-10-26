<?php

declare(strict_types=1);

namespace Drupal\appointments;

use Drupal\appointments\Entity\AppointmentInterface;
use Drupal\appointments\Entity\AppointmentServiceInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Url;
use Psr\Log\LoggerInterface;

/**
 * Books, confirms, moves and cancels appointments.
 *
 * Every write that claims calendar time runs under one lock and re-checks the
 * slot inside it, so two clients racing for the last free start cannot both
 * get it.
 */
final class BookingManager {

  /**
   * The lock that serialises every claim on calendar time.
   */
  private const LOCK = 'appointments.calendar';

  public function __construct(
    private readonly Calendar $calendar,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LockBackendInterface $lock,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly TimeInterface $time,
    private readonly MailManagerInterface $mailManager,
    private readonly LanguageManagerInterface $languageManager,
    private readonly LoggerInterface $logger,
    private readonly ?CheckoutHandlerInterface $checkout = NULL,
  ) {}

  /**
   * The payment mode bookings get: none, deposit or full.
   *
   * 'none' whenever no checkout handler is installed, so a site without one
   * never leaves bookings waiting for a payment that cannot happen.
   */
  public function paymentMode(): string {
    $mode = (string) $this->calendar->settings()->get('payment.mode');
    if ($this->checkout === NULL || !in_array($mode, ['deposit', 'full'], TRUE)) {
      return 'none';
    }
    return $mode;
  }

  /**
   * Whether a checkout handler is installed.
   */
  public function hasCheckout(): bool {
    return $this->checkout !== NULL;
  }

  /**
   * What a booking of the service charges online, as a decimal string.
   */
  public function amountDue(AppointmentServiceInterface $service): string {
    $price = (float) $service->getPrice();
    $amount = match ($this->paymentMode()) {
      'full' => $price,
      'deposit' => $this->deposit($price),
      default => 0.0,
    };
    return number_format(max(0.0, $amount), 2, '.', '');
  }

  /**
   * Books a new appointment.
   *
   * @param \Drupal\appointments\Entity\AppointmentInterface $appointment
   *   An unsaved appointment with its service, start and client fields set.
   *
   * @return \Drupal\Core\Url|null
   *   Where to pay, when the booking waits for a payment; NULL when it is
   *   confirmed already.
   *
   * @throws \Drupal\appointments\SlotUnavailableException
   *   When the start is not free.
   */
  public function book(AppointmentInterface $appointment): ?Url {
    $service = $appointment->getService();
    if (!$service || !$service->isPublished()) {
      throw new SlotUnavailableException('The service is not bookable.');
    }
    $start = $appointment->getStart();
    $amount = $this->amountDue($service);
    $paid = (float) $amount > 0;

    $this->claim(function () use ($appointment, $service, $start, $amount, $paid): void {
      if (!$this->calendar->isAvailable($service, $start)) {
        throw new SlotUnavailableException('The start time is not free.');
      }
      $this->place($appointment, $service, $start);
      $appointment->set('payment', $paid ? $this->paymentMode() : 'none');
      $appointment->set('amount', $amount);
      if ($paid) {
        $hold = max(5, (int) $this->calendar->settings()->get('hold')) * 60;
        $appointment->setStatus(AppointmentInterface::PENDING);
        $appointment->set('hold_expires', $this->time->getCurrentTime() + $hold);
      }
      else {
        $appointment->setStatus(AppointmentInterface::CONFIRMED);
      }
      $appointment->save();
    });

    if ($paid && $this->checkout !== NULL) {
      return $this->checkout->start($appointment);
    }
    $this->notify($appointment, 'confirmed');
    return NULL;
  }

  /**
   * Confirms a pending appointment once it is paid.
   *
   * @param \Drupal\appointments\Entity\AppointmentInterface $appointment
   *   The appointment.
   * @param string $reference
   *   The payment reference to record (an order number).
   *
   * @return bool
   *   FALSE when the hold had run out and the slot was taken meanwhile; the
   *   staff are told, the appointment stays cancelled.
   */
  public function confirm(AppointmentInterface $appointment, string $reference = ''): bool {
    if ($appointment->getStatus() === AppointmentInterface::CONFIRMED) {
      return TRUE;
    }
    $ok = FALSE;
    $this->claim(function () use ($appointment, $reference, &$ok): void {
      $now = $this->time->getCurrentTime();
      $service = $appointment->getService();
      $still_held = $appointment->isHolding($now);
      if (!$still_held && $service && $this->overlapsFree($appointment)) {
        $still_held = TRUE;
      }
      if ($reference !== '') {
        $appointment->set('reference', $reference);
      }
      if ($still_held) {
        $appointment->setStatus(AppointmentInterface::CONFIRMED);
        $appointment->set('hold_expires', NULL);
        $ok = TRUE;
      }
      else {
        $appointment->setStatus(AppointmentInterface::CANCELLED);
      }
      $appointment->save();
    });
    if ($ok) {
      $this->notify($appointment, 'confirmed');
    }
    else {
      $this->logger->error('Paid appointment @id could not be confirmed: its hold ran out and the slot was taken (reference @ref).', [
        '@id' => $appointment->id(),
        '@ref' => $reference,
      ]);
      $this->notify($appointment, 'conflict', ['staff']);
    }
    return $ok;
  }

  /**
   * Moves an appointment to a new start.
   *
   * @throws \Drupal\appointments\SlotUnavailableException
   *   When the new start is not free.
   */
  public function reschedule(AppointmentInterface $appointment, int $start): void {
    $service = $appointment->getService();
    if (!$service) {
      throw new SlotUnavailableException('The service no longer exists.');
    }
    $this->claim(function () use ($appointment, $service, $start): void {
      if (!$this->calendar->isAvailable($service, $start, (int) $appointment->id())) {
        throw new SlotUnavailableException('The start time is not free.');
      }
      $this->place($appointment, $service, $start);
      $appointment->save();
    });
    $this->notify($appointment, 'rescheduled');
  }

  /**
   * Cancels an appointment.
   *
   * @param \Drupal\appointments\Entity\AppointmentInterface $appointment
   *   The appointment.
   * @param string $by
   *   Who cancels: 'client', 'staff', 'expired' (the client is told the hold
   *   ran out), or 'silent' (nobody is told: an abandoned unpaid booking).
   */
  public function cancel(AppointmentInterface $appointment, string $by = 'client'): void {
    if ($appointment->getStatus() === AppointmentInterface::CANCELLED) {
      return;
    }
    $appointment->setStatus(AppointmentInterface::CANCELLED);
    $appointment->set('hold_expires', NULL);
    $appointment->save();
    match ($by) {
      'silent' => NULL,
      'expired' => $this->notify($appointment, 'expired', ['client']),
      default => $this->notify($appointment, 'cancelled'),
    };
  }

  /**
   * Extends the hold of a pending appointment, if its time is still free.
   *
   * @return bool
   *   Whether the appointment holds its time now.
   */
  public function renewHold(AppointmentInterface $appointment): bool {
    if ($appointment->getStatus() !== AppointmentInterface::PENDING) {
      return $appointment->getStatus() === AppointmentInterface::CONFIRMED;
    }
    $now = $this->time->getCurrentTime();
    if ($appointment->isHolding($now)) {
      return TRUE;
    }
    $held = FALSE;
    $this->claim(function () use ($appointment, $now, &$held): void {
      if ($this->overlapsFree($appointment)) {
        $appointment->set('hold_expires', $now + max(5, (int) $this->calendar->settings()->get('hold')) * 60);
        $appointment->save();
        $held = TRUE;
      }
    });
    return $held;
  }

  /**
   * Sets a staff-only outcome: completed or no-show.
   */
  public function close(AppointmentInterface $appointment, string $status): void {
    if (!in_array($status, [AppointmentInterface::COMPLETED, AppointmentInterface::NO_SHOW], TRUE)) {
      throw new \InvalidArgumentException("Not an outcome: $status");
    }
    $appointment->setStatus($status)->save();
  }

  /**
   * Whether the client may still cancel or move the appointment.
   */
  public function clientCanChange(AppointmentInterface $appointment): bool {
    if (!in_array($appointment->getStatus(), [AppointmentInterface::PENDING, AppointmentInterface::CONFIRMED], TRUE)) {
      return FALSE;
    }
    $cutoff = max(0, (int) $this->calendar->settings()->get('cancel_cutoff')) * 60;
    return $appointment->getStart() - $this->time->getCurrentTime() > $cutoff;
  }

  /**
   * Cancels the pending appointments whose hold has run out. For cron.
   *
   * @return int
   *   How many were cancelled.
   */
  public function expireHolds(): int {
    $storage = $this->entityTypeManager->getStorage('appointment');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('status', AppointmentInterface::PENDING)
      ->condition('hold_expires', $this->time->getCurrentTime(), '<=')
      ->range(0, 100)
      ->execute();
    $count = 0;
    /** @var \Drupal\appointments\Entity\AppointmentInterface $appointment */
    foreach ($storage->loadMultiple($ids) as $appointment) {
      $this->cancel($appointment, 'expired');
      $count++;
    }
    return $count;
  }

  /**
   * Loads an appointment by its manage token.
   */
  public function loadByToken(string $token): ?AppointmentInterface {
    if (!preg_match('/^[0-9a-f]{48}$/', $token)) {
      return NULL;
    }
    $found = $this->entityTypeManager->getStorage('appointment')->loadByProperties(['token' => $token]);
    $appointment = reset($found);
    return $appointment instanceof AppointmentInterface ? $appointment : NULL;
  }

  /**
   * The staff addresses, falling back to the site e-mail.
   */
  public function staffAddresses(): string {
    $notify = trim((string) $this->calendar->settings()->get('notify'));
    return $notify !== '' ? $notify : (string) $this->configFactory->get('system.site')->get('mail');
  }

  /**
   * Sends the messages of an event.
   *
   * @param \Drupal\appointments\Entity\AppointmentInterface $appointment
   *   The appointment.
   * @param string $key
   *   The event: confirmed, rescheduled, cancelled, expired or conflict.
   * @param list<string> $audiences
   *   Who is told: 'client' and/or 'staff'.
   */
  public function notify(AppointmentInterface $appointment, string $key, array $audiences = ['client', 'staff']): void {
    $langcode = $this->languageManager->getCurrentLanguage()->getId();
    foreach ($audiences as $audience) {
      $to = $audience === 'client' ? $appointment->getEmail() : $this->staffAddresses();
      if ($to === '') {
        continue;
      }
      $this->mailManager->mail('appointments', $key . '.' . $audience, $to, $langcode, [
        'appointment' => $appointment,
        'audience' => $audience,
      ], $audience === 'staff' ? $appointment->getEmail() : NULL);
    }
  }

  /**
   * Sets start, end and busy-until from the service.
   */
  private function place(AppointmentInterface $appointment, AppointmentServiceInterface $service, int $start): void {
    $end = $start + $service->getDuration() * 60;
    $appointment->set('start', $start);
    $appointment->set('end', $end);
    $appointment->set('busy_until', $end + $service->getBuffer() * 60);
  }

  /**
   * Whether the appointment's own slot is still free (its hold ran out).
   */
  private function overlapsFree(AppointmentInterface $appointment): bool {
    $service = $appointment->getService();
    return $service !== NULL
      && $this->calendar->slotIsFree($service, $appointment->getStart(), (int) $appointment->id());
  }

  /**
   * Runs a write under the calendar lock.
   *
   * @throws \Drupal\appointments\SlotUnavailableException
   *   When the lock cannot be had (the calendar is busy).
   */
  private function claim(callable $write): void {
    if (!$this->lock->acquire(self::LOCK, 10.0)) {
      $this->lock->wait(self::LOCK, 5);
      if (!$this->lock->acquire(self::LOCK, 10.0)) {
        throw new SlotUnavailableException('The calendar is busy; try again.');
      }
    }
    try {
      $write();
    }
    finally {
      $this->lock->release(self::LOCK);
    }
  }

  /**
   * The deposit for a price, by the settings.
   */
  private function deposit(float $price): float {
    $type = (string) $this->calendar->settings()->get('payment.deposit_type');
    $value = (float) $this->calendar->settings()->get('payment.deposit_value');
    $deposit = $type === 'fixed' ? $value : round($price * $value / 100, 2);
    return min($price, max(0.0, $deposit));
  }

}
