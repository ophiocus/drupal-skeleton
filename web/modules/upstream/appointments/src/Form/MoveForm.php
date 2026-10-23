<?php

declare(strict_types=1);

namespace Drupal\appointments\Form;

use Drupal\appointments\SlotUnavailableException;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

/**
 * The client moves an appointment to another free start.
 */
final class MoveForm extends TokenConfirmFormBase {

  /**
   * The new start.
   */
  protected int $start = 0;

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'appointments_move';
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param string $token
   *   The appointment's manage token, from the route.
   * @param string|int $start
   *   The new start timestamp, from the route.
   */
  public function buildForm(array $form, FormStateInterface $form_state, string $token = '', string|int $start = 0): array {
    $this->open($token);
    $this->start = (int) $start;
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    $moved = clone $this->appointment;
    $moved->set('start', $this->start);
    return $this->t('Move your appointment to @new?', ['@new' => $this->messages->when($moved)]);
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return $this->t('It is now on @when.', ['@when' => $this->messages->when($this->appointment)]);
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    return $this->t('Move it');
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    try {
      $this->booking->reschedule($this->appointment, $this->start);
      $this->messenger()->addStatus($this->t('Your appointment has moved. We sent you the new details.'));
      $form_state->setRedirectUrl($this->getCancelUrl());
    }
    catch (SlotUnavailableException) {
      $this->messenger()->addError($this->t('Sorry, that time is no longer free. Please choose another.'));
      $service = $this->appointment->getService();
      $form_state->setRedirectUrl($service
        ? Url::fromRoute('appointments.book_service', ['appointment_service' => $service->id()], ['query' => ['reschedule' => $this->appointment->getToken()]])
        : $this->getCancelUrl());
    }
  }

}
