<?php

declare(strict_types=1);

namespace Drupal\ad_inventory\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * A sold booking: a creative in one or more spaces, from one day to another.
 *
 * The scope narrows the pages a booking shows on, by what the page is about
 * (a city, a genre: keys a site defines), never by who reads it. Days are the
 * site's days. An approved booking in force wins its space over providers
 * and house banners; a stopped booking leaves the page at the next render.
 */
#[ContentEntityType(
  id: 'ad_booking',
  label: new TranslatableMarkup('Banner booking'),
  label_collection: new TranslatableMarkup('Banner bookings'),
  label_singular: new TranslatableMarkup('banner booking'),
  label_plural: new TranslatableMarkup('banner bookings'),
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
    'label' => 'label',
  ],
  handlers: [
    'list_builder' => 'Drupal\ad_inventory\ListBuilder\AdBookingListBuilder',
    'form' => [
      'default' => 'Drupal\ad_inventory\Form\AdBookingForm',
      'delete' => 'Drupal\Core\Entity\ContentEntityDeleteForm',
    ],
    'route_provider' => [
      'html' => 'Drupal\Core\Entity\Routing\AdminHtmlRouteProvider',
    ],
  ],
  links: [
    'collection' => '/admin/content/ad-inventory/bookings',
    'add-form' => '/admin/content/ad-inventory/bookings/add',
    'canonical' => '/admin/content/ad-inventory/bookings/{ad_booking}',
    'edit-form' => '/admin/content/ad-inventory/bookings/{ad_booking}/edit',
    'delete-form' => '/admin/content/ad-inventory/bookings/{ad_booking}/delete',
  ],
  admin_permission: 'manage ad bookings',
  base_table: 'ad_booking',
)]
final class AdBooking extends ContentEntityBase {

  use EntityChangedTrait;

  public const DRAFT = 'draft';
  public const APPROVED = 'approved';
  public const STOPPED = 'stopped';

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);
    $fields['label'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Name'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 255);
    $fields['creative'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Creative'))
      ->setRequired(TRUE)
      ->setSetting('target_type', 'ad_creative');
    $fields['slots'] = BaseFieldDefinition::create('map')
      ->setLabel(new TranslatableMarkup('Spaces'));
    $fields['scope'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Scope'))
      ->setDescription(new TranslatableMarkup('Empty: every page of the spaces. Otherwise one scope key (for example a city).'))
      ->setSetting('max_length', 128);
    $fields['start_day'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('First day'))
      ->setDescription(new TranslatableMarkup('YYYY-MM-DD, in the site time zone.'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 10);
    $fields['end_day'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Last day'))
      ->setDescription(new TranslatableMarkup('YYYY-MM-DD, in the site time zone, included.'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 10);
    $fields['exclusive'] = BaseFieldDefinition::create('boolean')
      ->setLabel(new TranslatableMarkup('Exclusive'))
      ->setDescription(new TranslatableMarkup('No other booking may hold these spaces in this scope on these days.'))
      ->setDefaultValue(TRUE);
    $fields['reference'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Reference code'))
      ->setDescription(new TranslatableMarkup('A code the buyer supplies, checked by the site (for example a show registration code).'))
      ->setSetting('max_length', 64);
    $fields['state'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('State'))
      ->setDefaultValue(self::DRAFT)
      ->setSetting('max_length', 16);
    $fields['state_reason'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Why it stopped'))
      ->setSetting('max_length', 255);
    $fields['notes'] = BaseFieldDefinition::create('string_long')
      ->setLabel(new TranslatableMarkup('Notes'))
      ->setDescription(new TranslatableMarkup('Price, invoice, contact: for staff only.'));
    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(new TranslatableMarkup('Created'));
    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(new TranslatableMarkup('Changed'));
    return $fields;
  }

  /**
   * The booked creative.
   */
  public function creative(): ?AdCreative {
    $creative = $this->get('creative')->entity;
    return $creative instanceof AdCreative ? $creative : NULL;
  }

  /**
   * Space ids.
   *
   * @return string[]
   *   Space ids.
   */
  public function slots(): array {
    $value = $this->get('slots')->first()?->getValue() ?? [];
    return array_values(array_filter((array) ($value['ids'] ?? []), 'is_string'));
  }

  /**
   * Sets the booked spaces.
   *
   * Stored as {ids: [...]}: a map field keyed 0, 1… reads back on load as
   * one item per value and loses them.
   *
   * @param string[] $ids
   *   Space ids.
   */
  public function setSlots(array $ids): static {
    $this->set('slots', ['ids' => array_values($ids)]);
    return $this;
  }

  /**
   * The scope key, or '' for every page.
   */
  public function scope(): string {
    return (string) $this->get('scope')->value;
  }

  /**
   * First day, YYYY-MM-DD.
   */
  public function startDay(): string {
    return (string) $this->get('start_day')->value;
  }

  /**
   * Last day, YYYY-MM-DD.
   */
  public function endDay(): string {
    return (string) $this->get('end_day')->value;
  }

  /**
   * Whether the booking covers a day (YYYY-MM-DD).
   */
  public function covers(string $day): bool {
    return $this->startDay() !== '' && $this->startDay() <= $day && $day <= $this->endDay();
  }

  /**
   * The state.
   */
  public function state(): string {
    return (string) ($this->get('state')->value ?: self::DRAFT);
  }

  /**
   * Stops the booking, with the reason.
   */
  public function stop(string $reason): static {
    $this->set('state', self::STOPPED);
    $this->set('state_reason', mb_substr($reason, 0, 255));
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTagsToInvalidate(): array {
    $tags = parent::getCacheTagsToInvalidate();
    foreach ($this->slots() as $slot) {
      $tags[] = AdSlot::tag($slot);
    }
    $original = $this->isNew() ? NULL : $this->getOriginal();
    if ($original instanceof self) {
      foreach ($original->slots() as $slot) {
        $tags[] = AdSlot::tag($slot);
      }
    }
    return array_values(array_unique($tags));
  }

}
