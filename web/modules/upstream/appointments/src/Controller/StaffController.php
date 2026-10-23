<?php

declare(strict_types=1);

namespace Drupal\appointments\Controller;

use Drupal\appointments\BookingManager;
use Drupal\appointments\Entity\AppointmentInterface;
use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * The staff's one-click status changes from the appointment list.
 */
final class StaffController extends ControllerBase {

  public function __construct(
    private readonly BookingManager $booking,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('appointments.booking'));
  }

  /**
   * Sets an appointment's status and returns to the list.
   */
  public function status(AppointmentInterface $appointment, string $status): RedirectResponse {
    $label = ['%a' => $appointment->label()];
    if ($status === AppointmentInterface::CONFIRMED) {
      if ($this->booking->confirm($appointment)) {
        $this->messenger()->addStatus($this->t('Confirmed: %a.', $label));
      }
      else {
        $this->messenger()->addError($this->t('%a could not be confirmed: its time has been taken.', $label));
      }
    }
    elseif ($status === AppointmentInterface::CANCELLED) {
      $this->booking->cancel($appointment, 'staff');
      $this->messenger()->addStatus($this->t('Cancelled: %a. The client has been told.', $label));
    }
    else {
      $this->booking->close($appointment, $status);
      $this->messenger()->addStatus($this->t('Saved: %a.', $label));
    }
    return $this->redirect('entity.appointment.collection');
  }

}
