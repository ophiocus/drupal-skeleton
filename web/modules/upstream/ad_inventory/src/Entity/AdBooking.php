<?php

declare(strict_types=1);

namespace Drupal\ad_inventory\Entity;

use Drupal\ad_inventory\Access\AdInventoryAccessControlHandler;
use Drupal\ad_inventory\Form\AdBookingForm;
use Drupal\ad_inventory\Form\BookingStateForm;
use Drupal\ad_inventory\ListBuilder\AdBookingListBuilder;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\ContentEntityDeleteForm;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\Routing\AdminHtmlRouteProvider;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\user\EntityOwnerTrait;

/**
 * An ad booking.
 *
 * Dates are whole days in the site's time zone, never hours. Saving an
 * approved booking runs the approval checks (renditions, kinds, the
 * validation event): a refusal puts it back to draft with the reason, a stop
 * stops it. Every save and delete invalidates the fill tag of its slots, so
 * pages and email images pick the change up at their next render.
 */
#[ContentEntityType(
  id: 'ad_booking',
  label: new TranslatableMarkup('Ad booking'),
  label_collection: new TranslatableMarkup('Ad bookings'),
  label_singular: new TranslatableMarkup('ad booking'),
  label_plural: new TranslatableMarkup('ad bookings'),
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
    'label' => 'label',
    'owner' => 'uid',
  ],
  handlers: [
    'access' => AdInventoryAccessControlHandler::class,
    'list_builder' => AdBookingListBuilder::class,
    'form' => [
      'default' => AdBookingForm::class,
      'add' => AdBookingForm::class,
      'edit' => AdBookingForm::class,
      'delete' => ContentEntityDeleteForm::class,
      'approve' => BookingStateForm::class,
      'stop' => BookingStateForm::class,
    ],
    'route_provider' => [
      'html' => AdminHtmlRouteProvider::class,
    ],
  ],
  links: [
    'collection' => '/admin/content/ad-booking',
    'add-form' => '/admin/content/ad-booking/add',
    'edit-form' => '/admin/content/ad-booking/{ad_booking}',
    'delete-form' => '/admin/content/ad-booking/{ad_booking}/delete',
    'approve-form' => '/admin/content/ad-booking/{ad_booking}/approve',
    'stop-form' => '/admin/content/ad-booking/{ad_booking}/stop',
  ],
  admin_permission: 'administer ad inventory',
  base_table: 'ad_booking',
  label_count: [
    'singular' => '@count ad booking',
    'plural' => '@count ad bookings',
  ],
)]
class AdBooking extends ContentEntityBase implements AdBookingInterface {

  use EntityChangedTrait;
  use EntityOwnerTrait;

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);
    $fields += static::ownerBaseFieldDefinitions($entity_type);

    $fields['label'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Name'))
      ->setDescription(new TranslatableMarkup('For editors, the advertiser and reports.'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 255)
      ->setDisplayOptions('form', ['type' => 'string_textfield', 'weight' => -10])
      ->setDisplayConfigurable('form', TRUE);

    $fields['creative'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Creative'))
      ->setRequired(TRUE)
      ->setSetting('target_type', 'ad_creative')
      ->setDisplayOptions('form', ['type' => 'entity_reference_autocomplete', 'weight' => -5])
      ->setDisplayConfigurable('form', TRUE);

    $fields['slots'] = BaseFieldDefinition::create('list_string')
      ->setLabel(new TranslatableMarkup('Slots'))
      ->setDescription(new TranslatableMarkup('Slots, or a slot group (every slot in it).'))
      ->setRequired(TRUE)
      ->setCardinality(FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED)
      ->setSetting('allowed_values_function', 'Drupal\ad_inventory\Entity\AdBooking::slotOptions')
      ->setDisplayOptions('form', ['type' => 'options_buttons', 'weight' => -4])
      ->setDisplayConfigurable('form', TRUE);

    $fields['scope'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Pages'))
      ->setDescription(new TranslatableMarkup('Empty: every page of the slots. Otherwise a page context the site provides (for example "city:12"); never anything about the reader.'))
      ->setSetting('max_length', 128)
      ->setDefaultValue('')
      ->setDisplayOptions('form', ['type' => 'string_textfield', 'weight' => -3])
      ->setDisplayConfigurable('form', TRUE);

    $fields['start_day'] = BaseFieldDefinition::create('datetime')
      ->setLabel(new TranslatableMarkup('First day'))
      ->setRequired(TRUE)
      ->setSetting('datetime_type', 'date')
      ->setDisplayOptions('form', ['type' => 'datetime_default', 'weight' => -2])
      ->setDisplayConfigurable('form', TRUE);

    $fields['end_day'] = BaseFieldDefinition::create('datetime')
      ->setLabel(new TranslatableMarkup('Last day'))
      ->setRequired(TRUE)
      ->setSetting('datetime_type', 'date')
      ->setDisplayOptions('form', ['type' => 'datetime_default', 'weight' => -1])
      ->setDisplayConfigurable('form', TRUE);

    $fields['exclusive'] = BaseFieldDefinition::create('boolean')
      ->setLabel(new TranslatableMarkup('Exclusive'))
      ->setDescription(new TranslatableMarkup('While in force, no other booking shows in these slots.'))
      ->setDefaultValue(FALSE)
      ->setDisplayOptions('form', ['type' => 'boolean_checkbox', 'weight' => 0])
      ->setDisplayConfigurable('form', TRUE);

    $fields['state'] = BaseFieldDefinition::create('list_string')
      ->setLabel(new TranslatableMarkup('State'))
      ->setRequired(TRUE)
      ->setDefaultValue('draft')
      ->setSetting('allowed_values', [
        'draft' => 'Draft',
        'approved' => 'Approved',
        'stopped' => 'Stopped',
      ]);

    $fields['reason'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Reason'))
      ->setDescription(new TranslatableMarkup('Why the booking was refused or stopped.'))
      ->setSetting('max_length', 1024)
      ->setDefaultValue('');

    $fields['uid']
      ->setLabel(new TranslatableMarkup('Booked by'));

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(new TranslatableMarkup('Created'));

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(new TranslatableMarkup('Changed'));

    return $fields;
  }

  /**
   * Allowed values of the slots field: every slot, then every group.
   *
   * @return array<string, string>
   *   Labels keyed by slot id or "group:<name>".
   */
  public static function slotOptions(): array {
    $options = [];
    $groups = [];
    foreach (AdSlot::loadMultiple() as $slot) {
      $options[(string) $slot->id()] = sprintf('%s (%s)', $slot->label(), $slot->channel());
      if ($slot->group() !== '') {
        $groups[$slot->group()] = TRUE;
      }
    }
    foreach (array_keys($groups) as $group) {
      $options['group:' . $group] = (string) new TranslatableMarkup('Group: @group', ['@group' => $group]);
    }
    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function creative(): ?AdCreativeInterface {
    $creative = $this->get('creative')->entity;
    return $creative instanceof AdCreativeInterface ? $creative : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function slotValues(): array {
    $values = [];
    foreach ($this->get('slots') as $item) {
      if ((string) $item->value !== '') {
        $values[] = (string) $item->value;
      }
    }
    return $values;
  }

  /**
   * {@inheritdoc}
   */
  public function scope(): string {
    return trim((string) $this->get('scope')->value);
  }

  /**
   * {@inheritdoc}
   */
  public function start(): string {
    return (string) $this->get('start_day')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function end(): string {
    return (string) $this->get('end_day')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function isExclusive(): bool {
    return (bool) $this->get('exclusive')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function state(): string {
    return (string) ($this->get('state')->value ?: 'draft');
  }

  /**
   * {@inheritdoc}
   */
  public function setState(string $state): static {
    $this->set('state', $state);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function reason(): string {
    return (string) $this->get('reason')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function setReason(string $reason): static {
    $this->set('reason', mb_substr($reason, 0, 1024));
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function inForceOn(string $day): bool {
    return $this->state() === 'approved' && $this->start() !== '' && $this->start() <= $day && $this->end() >= $day;
  }

  /**
   * {@inheritdoc}
   */
  public function preSave(EntityStorageInterface $storage): void {
    parent::preSave($storage);
    if ($this->state() === 'approved') {
      \Drupal::service('ad_inventory.approval')->enforce($this, 'save');
    }
  }

  /**
   * {@inheritdoc}
   */
  public function postSave(EntityStorageInterface $storage, $update = TRUE): void {
    parent::postSave($storage, $update);
    $values = $this->slotValues();
    $original = $this->getOriginal();
    if ($original instanceof AdBookingInterface) {
      $values = array_merge($values, $original->slotValues());
    }
    self::invalidateSlots($values);
  }

  /**
   * {@inheritdoc}
   */
  public static function postDelete(EntityStorageInterface $storage, array $entities): void {
    parent::postDelete($storage, $entities);
    $values = [];
    foreach ($entities as $booking) {
      if ($booking instanceof AdBookingInterface) {
        $values = array_merge($values, $booking->slotValues());
      }
    }
    self::invalidateSlots($values);
  }

  /**
   * Invalidates the fill tags of slots and groups.
   *
   * @param string[] $values
   *   Slot ids and "group:<name>" values.
   */
  protected static function invalidateSlots(array $values): void {
    $slots = \Drupal::service('ad_inventory.inventory')->expandSlots($values);
    if ($slots) {
      Cache::invalidateTags(array_map([AdSlot::class, 'fillTag'], $slots));
    }
  }

}
