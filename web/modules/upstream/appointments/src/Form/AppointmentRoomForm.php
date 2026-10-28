<?php

declare(strict_types=1);

namespace Drupal\appointments\Form;

use Drupal\appointments\Calendar;
use Drupal\appointments\Entity\AppointmentRoom;
use Drupal\appointments\Entity\AppointmentRoomInterface;
use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Adds or edits a room.
 */
final class AppointmentRoomForm extends EntityForm {

  /**
   * The calendar, for the services a room may offer.
   */
  protected Calendar $calendar;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $form = parent::create($container);
    $form->calendar = $container->get('appointments.calendar');
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);
    $room = $this->entity;
    assert($room instanceof AppointmentRoomInterface);

    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Name'),
      '#description' => $this->t('As clients see it, e.g. "Consulting room 1".'),
      '#required' => TRUE,
      '#maxlength' => 128,
      '#default_value' => $room->label(),
    ];
    $form['id'] = [
      '#type' => 'machine_name',
      '#default_value' => $room->id(),
      '#machine_name' => ['exists' => [AppointmentRoom::class, 'load']],
      '#disabled' => !$room->isNew(),
    ];

    $options = [];
    foreach ($this->calendar->services(FALSE) as $key => $service) {
      $options[$key] = $service->label();
    }
    $form['services'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Services offered'),
      '#description' => $this->t('Leave all unchecked for a room that offers every service.'),
      '#options' => $options,
      '#default_value' => $room->getServiceKeys(),
    ];
    $form['weight'] = [
      '#type' => 'number',
      '#title' => $this->t('Weight'),
      '#description' => $this->t('Lighter rooms are listed first and filled first.'),
      '#default_value' => $room->getWeight(),
    ];
    $form['status'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Bookable'),
      '#default_value' => $room->status(),
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    $room = $this->entity;
    $room->set('services', array_values(array_filter((array) $form_state->getValue('services'))));
    $room->set('weight', (int) $form_state->getValue('weight'));
    $status = parent::save($form, $form_state);
    $this->messenger()->addStatus($this->t('Saved the room %label.', ['%label' => $room->label()]));
    $form_state->setRedirect('entity.appointment_room.collection');
    return $status;
  }

}
