<?php

declare(strict_types=1);

namespace Drupal\ad_inventory\Form;

use Drupal\ad_inventory\Entity\AdBookingInterface;
use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;

/**
 * Adds or edits a booking. The state changes only through approve and stop.
 */
final class AdBookingForm extends ContentEntityForm {

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state) {
    $form = parent::form($form, $form_state);
    $booking = $this->entity;
    assert($booking instanceof AdBookingInterface);
    if (!$booking->isNew()) {
      $form['state_info'] = [
        '#type' => 'item',
        '#title' => $this->t('State'),
        '#markup' => $booking->state() . ($booking->reason() !== '' ? ': ' . $booking->reason() : ''),
        '#weight' => -20,
      ];
    }
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $entity = parent::validateForm($form, $form_state);
    if ($entity instanceof AdBookingInterface && $entity->start() !== '' && $entity->end() !== '' && $entity->end() < $entity->start()) {
      $form_state->setErrorByName('end_day', $this->t('The last day is before the first.'));
    }
    return $entity;
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state) {
    $before = $this->entity->isNew() ? NULL : $this->entityTypeManager->getStorage('ad_booking')->loadUnchanged($this->entity->id());
    $was_approved = $before instanceof AdBookingInterface && $before->state() === 'approved';
    $status = parent::save($form, $form_state);
    $booking = $this->entity;
    assert($booking instanceof AdBookingInterface);
    if ($was_approved && $booking->state() !== 'approved') {
      $this->messenger()->addWarning($this->t('The booking is no longer approved: @reason', ['@reason' => $booking->reason()]));
    }
    else {
      $this->messenger()->addStatus($this->t('Saved the booking %label (@state).', [
        '%label' => $booking->label(),
        '@state' => $booking->state(),
      ]));
    }
    $form_state->setRedirectUrl($booking->toUrl('collection'));
    return $status;
  }

}
