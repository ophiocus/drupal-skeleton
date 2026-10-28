<?php

declare(strict_types=1);

namespace Drupal\appointments\Entity;

use Drupal\appointments\AppointmentServiceListBuilder;
use Drupal\appointments\Form\AppointmentServiceForm;
use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\ContentEntityDeleteForm;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityPublishedTrait;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\Routing\AdminHtmlRouteProvider;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * A bookable service.
 *
 * Content, not config: the services a site offers are business data, seeded
 * by the site and edited by its staff, like products in a shop.
 *
 * Translatable: the name and description have a translation per language,
 * everything else (key, duration, price, bookable) is shared. A service has
 * no page of its own, so the translation pages hang off the edit form.
 */
#[ContentEntityType(
  id: 'appointment_service',
  label: new TranslatableMarkup('Service'),
  label_collection: new TranslatableMarkup('Services'),
  label_singular: new TranslatableMarkup('service'),
  label_plural: new TranslatableMarkup('services'),
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
    'label' => 'label',
    'langcode' => 'langcode',
    'published' => 'status',
  ],
  handlers: [
    'list_builder' => AppointmentServiceListBuilder::class,
    'form' => [
      'default' => AppointmentServiceForm::class,
      'delete' => ContentEntityDeleteForm::class,
    ],
    'route_provider' => ['html' => AdminHtmlRouteProvider::class],
  ],
  links: [
    'collection' => '/admin/appointments/services',
    'add-form' => '/admin/appointments/services/add',
    'edit-form' => '/admin/appointments/services/{appointment_service}',
    'delete-form' => '/admin/appointments/services/{appointment_service}/delete',
    'drupal:content-translation-overview' => '/admin/appointments/services/{appointment_service}/translations',
    'drupal:content-translation-add' => '/admin/appointments/services/{appointment_service}/translations/add/{source}/{target}',
    'drupal:content-translation-edit' => '/admin/appointments/services/{appointment_service}/translations/edit/{language}',
    'drupal:content-translation-delete' => '/admin/appointments/services/{appointment_service}/translations/delete/{language}',
  ],
  admin_permission: 'administer appointments',
  base_table: 'appointment_service',
  data_table: 'appointment_service_field_data',
  translatable: TRUE,
  label_count: [
    'singular' => '@count service',
    'plural' => '@count services',
  ],
  // What content_translation sets itself only for types with a canonical
  // page: who may open the translation pages.
  additional: [
    'translation' => [
      'content_translation' => ['access_callback' => 'content_translation.manager:access'],
    ],
  ],
)]
final class AppointmentService extends ContentEntityBase implements AppointmentServiceInterface {

  use EntityChangedTrait;
  use EntityPublishedTrait;

  /**
   * {@inheritdoc}
   */
  public function getKey(): string {
    return (string) $this->get('key')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getDuration(): int {
    return max(1, (int) $this->get('duration')->value);
  }

  /**
   * {@inheritdoc}
   */
  public function getBuffer(): int {
    return max(0, (int) $this->get('buffer')->value);
  }

  /**
   * {@inheritdoc}
   */
  public function getPrice(): string {
    $price = (string) $this->get('price')->value;
    return $price === '' ? '0' : $price;
  }

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);
    $fields += static::publishedBaseFieldDefinitions($entity_type);

    $fields['key'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Key'))
      ->setDescription(new TranslatableMarkup('A stable machine key, for seed data. Letters, digits, hyphens.'))
      ->setRequired(TRUE)
      ->addConstraint('UniqueField')
      ->setSetting('max_length', 64)
      ->setDisplayOptions('form', ['type' => 'string_textfield', 'weight' => -10])
      ->setDisplayConfigurable('form', TRUE);

    $fields['label'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Name'))
      ->setRequired(TRUE)
      ->setTranslatable(TRUE)
      ->setSetting('max_length', 255)
      ->setDisplayOptions('form', ['type' => 'string_textfield', 'weight' => -9])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['description'] = BaseFieldDefinition::create('string_long')
      ->setLabel(new TranslatableMarkup('Description'))
      ->setTranslatable(TRUE)
      ->setDisplayOptions('form', ['type' => 'string_textarea', 'weight' => -8])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['duration'] = BaseFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Duration (minutes)'))
      ->setRequired(TRUE)
      ->setDefaultValue(30)
      ->setSetting('min', 1)
      ->setDisplayOptions('form', ['type' => 'number', 'weight' => -7])
      ->setDisplayConfigurable('form', TRUE);

    $fields['buffer'] = BaseFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Buffer after (minutes)'))
      ->setDescription(new TranslatableMarkup('Time kept free after each appointment of this service.'))
      ->setDefaultValue(0)
      ->setSetting('min', 0)
      ->setDisplayOptions('form', ['type' => 'number', 'weight' => -6])
      ->setDisplayConfigurable('form', TRUE);

    $fields['price'] = BaseFieldDefinition::create('decimal')
      ->setLabel(new TranslatableMarkup('Price'))
      ->setDescription(new TranslatableMarkup('In the currency of the appointment settings. 0 for a free service. Charged online only when the payment mode asks for it.'))
      ->setSetting('precision', 19)
      ->setSetting('scale', 2)
      ->setDefaultValue('0')
      ->setDisplayOptions('form', ['type' => 'number', 'weight' => -5])
      ->setDisplayConfigurable('form', TRUE);

    $fields['weight'] = BaseFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Weight'))
      ->setDefaultValue(0)
      ->setDisplayOptions('form', ['type' => 'number', 'weight' => 5])
      ->setDisplayConfigurable('form', TRUE);

    // Bookable or not in every language at once: the calendar is one.
    $fields['status']
      ->setLabel(new TranslatableMarkup('Bookable'))
      ->setTranslatable(FALSE)
      ->setDisplayOptions('form', ['type' => 'boolean_checkbox', 'weight' => 10])
      ->setDisplayConfigurable('form', TRUE);

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(new TranslatableMarkup('Changed'))
      ->setTranslatable(TRUE);

    return $fields;
  }

}
