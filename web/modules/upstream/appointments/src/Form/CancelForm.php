<?php

declare(strict_types=1);

namespace Drupal\appointments\Form;

use Drupal\Core\Form\FormStateInterface;

/**
 * The client cancels an appointment.
 */
final class CancelForm extends TokenConfirmFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'appointments_cancel';
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
   */
  public function buildForm(array $form, FormStateInterface $form_state, string $token = ''): array {
    $this->open($token);
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->t('Cancel your appointment of @when?', ['@when' => $this->messages->when($this->appointment)]);
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return (float) $this->appointment->get('amount')->value > 0
      ? $this->t('The time will be freed for someone else. About the amount you paid online, we will contact you.')
      : $this->t('The time will be freed for someone else.');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    return $this->t('Cancel the appointment');
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->booking->cancel($this->appointment, 'client');
    $this->messenger()->addStatus($this->t('Your appointment is cancelled.'));
    $form_state->setRedirectUrl($this->getCancelUrl());
  }

}
