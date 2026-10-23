<?php

declare(strict_types=1);

namespace Drupal\appointments\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;

/**
 * Adds or edits a bookable service.
 */
final class AppointmentServiceForm extends ContentEntityForm {

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    $status = parent::save($form, $form_state);
    $this->messenger()->addStatus($this->t('Saved the service %label.', ['%label' => $this->entity->label()]));
    $form_state->setRedirect('entity.appointment_service.collection');
    return $status;
  }

}
