<?php

declare(strict_types=1);

namespace Drupal\ad_inventory\Form;

use Drupal\ad_inventory\Entity\AdBookingInterface;
use Drupal\Core\Entity\ContentEntityConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

/**
 * Approves a booking (it runs the checks) or stops it.
 */
final class BookingStateForm extends ContentEntityConfirmFormBase {

  /**
   * Whether this is the stop form.
   */
  private function stopping(): bool {
    return $this->getOperation() === 'stop';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->stopping()
      ? $this->t('Stop the booking %label?', ['%label' => $this->entity->label()])
      : $this->t('Approve the booking %label?', ['%label' => $this->entity->label()]);
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return $this->stopping()
      ? $this->t('It leaves the pages and the email image at their next render.')
      : $this->t("Approval checks every format its slots need has a rendition of the right ratio, the slots take its kind, and the site's validation rules pass. It shows from its first day.");
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    return $this->stopping() ? $this->t('Stop') : $this->t('Approve');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    return $this->entity->toUrl('collection');
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $booking = $this->entity;
    assert($booking instanceof AdBookingInterface);
    if ($this->stopping()) {
      $booking->setState('stopped')->setReason((string) $this->t('Stopped by @name.', ['@name' => $this->currentUser()->getDisplayName()]));
      $booking->save();
      $this->messenger()->addStatus($this->t('The booking %label is stopped.', ['%label' => $booking->label()]));
    }
    else {
      $booking->setState('approved');
      $booking->save();
      if ($booking->state() === 'approved') {
        $this->messenger()->addStatus($this->t('The booking %label is approved.', ['%label' => $booking->label()]));
      }
      else {
        $this->messenger()->addError($this->t('The booking %label cannot be approved: @reason', [
          '%label' => $booking->label(),
          '@reason' => $booking->reason(),
        ]));
      }
    }
    $form_state->setRedirectUrl(Url::fromRoute('entity.ad_booking.collection'));
  }

}
