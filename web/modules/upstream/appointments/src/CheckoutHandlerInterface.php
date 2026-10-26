<?php

declare(strict_types=1);

namespace Drupal\appointments;

use Drupal\appointments\Entity\AppointmentInterface;
use Drupal\Core\Url;

/**
 * Takes an unpaid booking to an online payment.
 *
 * A module that provides one registers it as the service
 * `appointments.checkout` (appointments_commerce does, with Drupal Commerce).
 * Without it, every booking is free and is confirmed at once, whatever the
 * payment mode says.
 */
interface CheckoutHandlerInterface {

  /**
   * Starts the payment for a pending appointment.
   *
   * @param \Drupal\appointments\Entity\AppointmentInterface $appointment
   *   The saved, pending appointment; its `amount` field holds what to charge.
   *
   * @return \Drupal\Core\Url
   *   Where to send the client to pay.
   */
  public function start(AppointmentInterface $appointment): Url;

}
