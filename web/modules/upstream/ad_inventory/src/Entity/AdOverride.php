<?php

declare(strict_types=1);

namespace Drupal\ad_inventory\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * One page's own banner settings: spaces switched off, another format chosen.
 *
 * Kept by this module, keyed by the page's entity type and id, so the page's
 * own fields are never touched. A page that has no record follows the spaces'
 * defaults. Staff write these through a site's admin form.
 */
#[ContentEntityType(
  id: 'ad_override',
  label: new TranslatableMarkup('Banner settings of a page'),
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
  ],
  admin_permission: 'manage ad bookings',
  base_table: 'ad_override',
)]
final class AdOverride extends ContentEntityBase {

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);
    $fields['target_type'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Page entity type'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 64);
    $fields['target_id'] = BaseFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Page entity id'))
      ->setRequired(TRUE)
      ->setSetting('unsigned', TRUE);
    $fields['settings'] = BaseFieldDefinition::create('map')
      ->setLabel(new TranslatableMarkup('Settings'))
      ->setDescription(new TranslatableMarkup('Space id → {off: bool, desktop: format id, phone: format id}.'));
    return $fields;
  }

  /**
   * The cache tag of one page's settings.
   */
  public static function tag(string $type, int|string $id): string {
    return 'ad_override:' . $type . ':' . $id;
  }

  /**
   * Settings per space id.
   *
   * @return array<string, array<string, mixed>>
   *   Settings.
   */
  public function settings(): array {
    $value = $this->get('settings')->first()?->getValue() ?? [];
    return array_filter($value, 'is_array');
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTagsToInvalidate(): array {
    return array_merge(parent::getCacheTagsToInvalidate(), [
      self::tag((string) $this->get('target_type')->value, (int) $this->get('target_id')->value),
    ]);
  }

}
