<?php

declare(strict_types=1);

namespace Drupal\ad_inventory\Plugin\Field\FieldType;

use Drupal\Core\Field\Attribute\FieldType;
use Drupal\Core\Field\FieldItemBase;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinition;

/**
 * Per-page ad slot controls, stored on the page's entity.
 *
 * One value: JSON keyed by slot id, holding only what differs from the slot
 * ({"off": true}, {"format": "rectangle"}, {"phone_format": "…"},
 * {"count": 2}). The page is the same for every reader; only the page differs.
 *
 * @property string|null $value
 */
#[FieldType(
  id: 'ad_inventory_overrides',
  label: new TranslatableMarkup('Ad slot controls'),
  description: new TranslatableMarkup('Which ad slots show on this page, which format each takes, how many of a repeatable slot.'),
  category: 'general',
  default_widget: 'ad_inventory_overrides_default',
  cardinality: 1,
)]
class AdOverridesItem extends FieldItemBase {

  /**
   * {@inheritdoc}
   */
  public static function propertyDefinitions(FieldStorageDefinitionInterface $field_definition) {
    $properties['value'] = DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Overrides (JSON)'));
    return $properties;
  }

  /**
   * {@inheritdoc}
   */
  public static function schema(FieldStorageDefinitionInterface $field_definition) {
    return [
      'columns' => [
        'value' => [
          'type' => 'text',
          'size' => 'normal',
          'not null' => FALSE,
        ],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function isEmpty() {
    return self::decode((string) $this->get('value')->getValue()) === [];
  }

  /**
   * The overrides as an array, keyed by slot id.
   */
  public function overrides(): array {
    return self::decode((string) $this->get('value')->getValue());
  }

  /**
   * Decodes a stored value.
   */
  public static function decode(string $value): array {
    if ($value === '') {
      return [];
    }
    $decoded = json_decode($value, TRUE);
    return is_array($decoded) ? $decoded : [];
  }

  /**
   * Encodes overrides for storage.
   */
  public static function encode(array $overrides): string {
    return $overrides ? (string) json_encode($overrides, JSON_UNESCAPED_SLASHES) : '';
  }

}
