<?php

declare(strict_types=1);

namespace Drupal\appointments\Entity;

use Drupal\appointments\AppointmentListBuilder;
use Drupal\appointments\Form\AppointmentForm;
use Drupal\appointments\Form\BookingForm;
use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\ContentEntityDeleteForm;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\Routing\AdminHtmlRouteProvider;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * One appointment on the calendar.
 *
 * Fieldable without bundles: a site adds the fields its clients fill in (a
 * pet's name, a vehicle plate) through Field UI on the settings page, and the
 * public booking form shows them.
 */
#[ContentEntityType(
  id: 'appointment',
  label: new TranslatableMarkup('Appointment'),
  label_collection: new TranslatableMarkup('Appointments'),
  label_singular: new TranslatableMarkup('appointment'),
  label_plural: new TranslatableMarkup('appointments'),
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
  ],
  handlers: [
    'list_builder' => AppointmentListBuilder::class,
    'form' => [
      'default' => AppointmentForm::class,
      'edit' => AppointmentForm::class,
      'book' => BookingForm::class,
      'delete' => ContentEntityDeleteForm::class,
    ],
    'route_provider' => ['html' => AdminHtmlRouteProvider::class],
  ],
  links: [
    'collection' => '/admin/appointments',
    'edit-form' => '/admin/appointments/{appointment}',
    'delete-form' => '/admin/appointments/{appointment}/delete',
  ],
  admin_permission: 'administer appointments',
  collection_permission: 'view appointments',
  base_table: 'appointment',
  field_ui_base_route: 'appointments.settings',
  label_count: [
    'singular' => '@count appointment',
    'plural' => '@count appointments',
  ],
)]
final class Appointment extends ContentEntityBase implements AppointmentInterface {

  use EntityChangedTrait;

  /**
   * {@inheritdoc}
   */
  public function label(): string {
    $service = $this->getService();
    $when = $this->getStart()
      ? \Drupal::service('date.formatter')->format($this->getStart(), 'custom', 'Y-m-d H:i', \Drupal::service('appointments.calendar')->timezone())
      : '';
    return trim(($service ? $service->label() : '') . ' · ' . $when, ' ·');
  }

  /**
   * {@inheritdoc}
   */
  public function preSave(EntityStorageInterface $storage): void {
    parent::preSave($storage);
    if ($this->getToken() === '') {
      $this->set('token', bin2hex(random_bytes(24)));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getService(): ?AppointmentServiceInterface {
    $service = $this->get('service')->entity;
    return $service instanceof AppointmentServiceInterface ? $service : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getStart(): int {
    return (int) $this->get('start')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getEnd(): int {
    return (int) $this->get('end')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getBusyUntil(): int {
    return max($this->getEnd(), (int) $this->get('busy_until')->value);
  }

  /**
   * {@inheritdoc}
   */
  public function getStatus(): string {
    return (string) $this->get('status')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function setStatus(string $status): static {
    $this->set('status', $status);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function isHolding(int $now): bool {
    return match ($this->getStatus()) {
      self::CONFIRMED => TRUE,
      self::PENDING => (int) $this->get('hold_expires')->value > $now,
      default => FALSE,
    };
  }

  /**
   * {@inheritdoc}
   */
  public function getToken(): string {
    return (string) $this->get('token')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getEmail(): string {
    return (string) $this->get('email')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getRoomId(): string {
    return $this->hasField('room') ? (string) $this->get('room')->target_id : '';
  }

  /**
   * {@inheritdoc}
   */
  public function getRoom(): ?AppointmentRoomInterface {
    $room = $this->hasField('room') ? $this->get('room')->entity : NULL;
    return $room instanceof AppointmentRoomInterface ? $room : NULL;
  }

  /**
   * The status options, for the status field and for listings.
   *
   * @return array<string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   *   Labels keyed by status.
   */
  public static function statusOptions(): array {
    // A context of their own: "Completed" or "Cancelled" elsewhere (a
    // payment, an order) may need another word or gender.
    $context = ['context' => 'appointment status'];
    return [
      self::PENDING => new TranslatableMarkup('Awaiting payment', [], $context),
      self::CONFIRMED => new TranslatableMarkup('Confirmed', [], $context),
      self::COMPLETED => new TranslatableMarkup('Completed', [], $context),
      self::NO_SHOW => new TranslatableMarkup('No-show', [], $context),
      self::CANCELLED => new TranslatableMarkup('Cancelled', [], $context),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['service'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Service'))
      ->setRequired(TRUE)
      ->setSetting('target_type', 'appointment_service')
      ->setDisplayOptions('form', ['type' => 'options_select', 'weight' => -20])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['start'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(new TranslatableMarkup('Start'))
      ->setRequired(TRUE)
      ->setDisplayOptions('form', ['type' => 'datetime_timestamp', 'weight' => -19])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['end'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(new TranslatableMarkup('End'))
      ->setRequired(TRUE);

    $fields['busy_until'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(new TranslatableMarkup('Busy until'))
      ->setDescription(new TranslatableMarkup('End plus the service buffer.'));

    $fields['status'] = BaseFieldDefinition::create('list_string')
      ->setLabel(new TranslatableMarkup('Status'))
      ->setRequired(TRUE)
      ->setDefaultValue(self::CONFIRMED)
      ->setSetting('allowed_values_function', [self::class, 'statusOptions'])
      ->setDisplayOptions('form', ['type' => 'options_select', 'weight' => -18])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['hold_expires'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(new TranslatableMarkup('Hold expires'))
      ->setDescription(new TranslatableMarkup('When an unpaid booking releases its slot.'));

    $fields['name'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Your name'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 255)
      ->setDisplayOptions('form', ['type' => 'string_textfield', 'weight' => 0])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['email'] = BaseFieldDefinition::create('email')
      ->setLabel(new TranslatableMarkup('E-mail'))
      ->setRequired(TRUE)
      ->setDisplayOptions('form', ['type' => 'email_default', 'weight' => 1])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['phone'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Phone'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 32)
      ->setDisplayOptions('form', ['type' => 'string_textfield', 'weight' => 2])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['notes'] = BaseFieldDefinition::create('string_long')
      ->setLabel(new TranslatableMarkup('Notes'))
      ->setDescription(new TranslatableMarkup('Anything we should know before the appointment.'))
      ->setDisplayOptions('form', ['type' => 'string_textarea', 'weight' => 20])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['payment'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Payment mode at booking'))
      ->setDescription(new TranslatableMarkup('none, deposit or full.'))
      ->setSetting('max_length', 16)
      ->setDefaultValue('none');

    $fields['amount'] = BaseFieldDefinition::create('decimal')
      ->setLabel(new TranslatableMarkup('Amount charged online'))
      ->setSetting('precision', 19)
      ->setSetting('scale', 2)
      ->setDefaultValue('0');

    $fields['reference'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Payment reference'))
      ->setDescription(new TranslatableMarkup('Set by the payment integration, e.g. an order number.'))
      ->setSetting('max_length', 64);

    $fields['token'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Manage token'))
      ->setSetting('max_length', 64)
      ->addConstraint('UniqueField');

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(new TranslatableMarkup('Booked', [], ['context' => 'appointment status']));

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(new TranslatableMarkup('Changed'));

    return $fields;
  }

}
