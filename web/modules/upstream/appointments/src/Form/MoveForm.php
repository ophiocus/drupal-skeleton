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
   * The room asked for (from ?room=), or NULL for any free room.
   */
  protected ?string $room = NULL;

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
    $room = (string) $this->getRequest()->query->get('room', '');
    $this->room = $room !== '' ? $room : NULL;
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
      $this->booking->reschedule($this->appointment, $this->start, $this->room);
      $this->messenger()->addStatus($this->t('Your appointment has moved. We sent you the new details.'));
      $form_state->setRedirectUrl($this->getCancelUrl());
    }
    catch (SlotUnavailableException) {
      $this->messenger()->addError($this->t('Sorry, that time is no longer free. Please choose another.'));
      $form_state->setRedirectUrl($this->appointment->getService()
        ? Url::fromRoute('appointments.book', [], ['query' => ['reschedule' => $this->appointment->getToken()]])
        : $this->getCancelUrl());
    }
  }

}
