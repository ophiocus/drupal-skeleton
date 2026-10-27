<?php

declare(strict_types=1);

namespace Drupal\ad_inventory\Plugin\Field\FieldType;

use Drupal\Core\Field\Attribute\FieldType;
use Drupal\Core\Field\EntityReferenceFieldItemList;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Field\Plugin\Field\FieldType\EntityReferenceItem;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinition;

/**
 * A media reference with the ad format it renders.
 *
 * @property string|null $format
 */
#[FieldType(
  id: 'ad_rendition',
  label: new TranslatableMarkup('Ad rendition'),
  description: new TranslatableMarkup('A media image and the ad format it is drawn for.'),
  default_widget: 'ad_rendition_default',
  list_class: EntityReferenceFieldItemList::class,
  no_ui: TRUE,
)]
class AdRenditionItem extends EntityReferenceItem {

  /**
   * {@inheritdoc}
   */
  public static function propertyDefinitions(FieldStorageDefinitionInterface $field_definition) {
    $properties = parent::propertyDefinitions($field_definition);
    $properties['format'] = DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Format'));
    return $properties;
  }

  /**
   * {@inheritdoc}
   */
  public static function schema(FieldStorageDefinitionInterface $field_definition) {
    $schema = parent::schema($field_definition);
    $schema['columns']['format'] = [
      'description' => 'The ad format id.',
      'type' => 'varchar_ascii',
      'length' => 64,
      'not null' => FALSE,
    ];
    return $schema;
  }

}
