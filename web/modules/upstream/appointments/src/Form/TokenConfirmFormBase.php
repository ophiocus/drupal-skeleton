<?php

declare(strict_types=1);

namespace Drupal\appointments\Form;

use Drupal\appointments\BookingManager;
use Drupal\appointments\Entity\AppointmentInterface;
use Drupal\appointments\Messages;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A client's confirmation step on an appointment opened by its private link.
 */
abstract class TokenConfirmFormBase extends ConfirmFormBase {

  /**
   * The appointment of the token.
   */
  protected AppointmentInterface $appointment;

  final public function __construct(
    protected BookingManager $booking,
    protected Messages $messages,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('appointments.booking'),
      $container->get('appointments.messages'),
    );
  }

  /**
   * Loads the appointment and checks the client may still change it.
   */
  protected function open(string $token): void {
    $appointment = $this->booking->loadByToken($token);
    if (!$appointment) {
      throw new NotFoundHttpException();
    }
    if (!$this->booking->clientCanChange($appointment)) {
      throw new AccessDeniedHttpException();
    }
    $this->appointment = $appointment;
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    return Url::fromRoute('appointments.manage', ['token' => $this->appointment->getToken()]);
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelText() {
    return $this->t('Back');
  }

}
