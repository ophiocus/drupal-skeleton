<?php

declare(strict_types=1);

namespace Drupal\appointments\Form;

use Drupal\appointments\Entity\AppointmentInterface;
use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;

/**
 * The staff's edit form for an appointment.
 *
 * Staff may place an appointment anywhere, outside the opening hours too; the
 * calendar rules bind the public booking pages only. The end follows the
 * service whenever the start or the service changes.
 */
final class AppointmentForm extends ContentEntityForm {

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    $appointment = $this->entity;
    assert($appointment instanceof AppointmentInterface);
    $service = $appointment->getService();
    $original = $appointment->isNew() ? NULL : $this->entityTypeManager->getStorage('appointment')->loadUnchanged($appointment->id());
    $moved = !$original instanceof AppointmentInterface
      || $original->getStart() !== $appointment->getStart()
      || $original->get('service')->target_id !== $appointment->get('service')->target_id;
    if ($service && $moved) {
      $end = $appointment->getStart() + $service->getDuration() * 60;
      $appointment->set('end', $end);
      $appointment->set('busy_until', $end + $service->getBuffer() * 60);
    }
    $status = parent::save($form, $form_state);
    $this->messenger()->addStatus($this->t('Saved: %label.', ['%label' => $appointment->label()]));
    $form_state->setRedirect('entity.appointment.collection');
    return $status;
  }

}
