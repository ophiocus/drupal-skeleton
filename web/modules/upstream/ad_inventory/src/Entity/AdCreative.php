<?php

declare(strict_types=1);

namespace Drupal\ad_inventory\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\media\MediaInterface;

/**
 * A banner: one image per format, a link, alt text and a caption.
 *
 * Every format a booked space needs, on desktop and on phones, carries its own
 * rendition (a media image at twice the format's size). A phone rendition is
 * designed for phones, not the desktop image shrunk. A house creative fills
 * free spaces; any creative stays a draft, seen only by staff who preview
 * drafts, until it is approved.
 */
#[ContentEntityType(
  id: 'ad_creative',
  label: new TranslatableMarkup('Banner creative'),
  label_collection: new TranslatableMarkup('Banner creatives'),
  label_singular: new TranslatableMarkup('banner creative'),
  label_plural: new TranslatableMarkup('banner creatives'),
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
    'label' => 'label',
  ],
  handlers: [
    'list_builder' => 'Drupal\ad_inventory\ListBuilder\AdCreativeListBuilder',
    'form' => [
      'default' => 'Drupal\ad_inventory\Form\AdCreativeForm',
      'delete' => 'Drupal\Core\Entity\ContentEntityDeleteForm',
    ],
    'route_provider' => [
      'html' => 'Drupal\Core\Entity\Routing\AdminHtmlRouteProvider',
    ],
  ],
  links: [
    'collection' => '/admin/content/ad-inventory/creatives',
    'add-form' => '/admin/content/ad-inventory/creatives/add',
    'canonical' => '/admin/content/ad-inventory/creatives/{ad_creative}',
    'edit-form' => '/admin/content/ad-inventory/creatives/{ad_creative}/edit',
    'delete-form' => '/admin/content/ad-inventory/creatives/{ad_creative}/delete',
  ],
  admin_permission: 'manage ad bookings',
  base_table: 'ad_creative',
)]
final class AdCreative extends ContentEntityBase {

  use EntityChangedTrait;

  public const DRAFT = 'draft';
  public const APPROVED = 'approved';

  /**
   * A banner for anything that is not a single show.
   */
  public const PLAIN = 'plain';

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);
    $fields['label'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Name'))
      ->setDescription(new TranslatableMarkup('For staff and reports; readers never see it.'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 255);
    $fields['advertiser'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Advertiser'))
      ->setSetting('max_length', 255);
    $fields['kind'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Kind'))
      ->setDescription(new TranslatableMarkup('"plain", or a kind a site defines (for example a single show).'))
      ->setDefaultValue(self::PLAIN)
      ->setSetting('max_length', 64);
    $fields['target_url'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Link'))
      ->setDescription(new TranslatableMarkup('Where a click goes: an https:// address, or a path on this site.'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 2048);
    $fields['alt'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Alternative text'))
      ->setDescription(new TranslatableMarkup('What the banner says, for readers who cannot see it.'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 255);
    $fields['caption'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Caption'))
      ->setDescription(new TranslatableMarkup('A short line printed under the banner (for example a registration code a law asks for).'))
      ->setSetting('max_length', 255);
    $fields['renditions'] = BaseFieldDefinition::create('map')
      ->setLabel(new TranslatableMarkup('Renditions'))
      ->setDescription(new TranslatableMarkup('Format id → media id.'));
    $fields['house'] = BaseFieldDefinition::create('boolean')
      ->setLabel(new TranslatableMarkup('House banner'))
      ->setDescription(new TranslatableMarkup('Fills spaces no booking holds.'))
      ->setDefaultValue(FALSE);
    $fields['house_slots'] = BaseFieldDefinition::create('map')
      ->setLabel(new TranslatableMarkup('House spaces'))
      ->setDescription(new TranslatableMarkup('Space ids a house banner may fill; empty means all.'));
    $fields['promoted_type'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Promoted entity type'))
      ->setSetting('max_length', 64);
    $fields['promoted_id'] = BaseFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Promoted entity'))
      ->setSetting('unsigned', TRUE);
    $fields['state'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('State'))
      ->setDefaultValue(self::DRAFT)
      ->setSetting('max_length', 16);
    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(new TranslatableMarkup('Created'));
    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(new TranslatableMarkup('Changed'));
    return $fields;
  }

  /**
   * Media id per format id.
   *
   * @return array<string, int>
   *   Renditions.
   */
  public function renditions(): array {
    $value = $this->get('renditions')->first()?->getValue() ?? [];
    return array_map('intval', array_filter($value, 'is_numeric'));
  }

  /**
   * Sets one format's rendition.
   */
  public function setRendition(string $format, int $media_id): static {
    $renditions = $this->renditions();
    $renditions[$format] = $media_id;
    ksort($renditions);
    $this->set('renditions', $renditions);
    return $this;
  }

  /**
   * The rendition for a format, if there is one.
   */
  public function rendition(string $format): ?MediaInterface {
    $id = $this->renditions()[$format] ?? NULL;
    if ($id === NULL) {
      return NULL;
    }
    $media = $this->entityTypeManager()->getStorage('media')->load($id);
    return $media instanceof MediaInterface ? $media : NULL;
  }

  /**
   * Format ids among $needed with no rendition yet.
   *
   * @param string[] $needed
   *   Format ids.
   *
   * @return string[]
   *   Missing format ids.
   */
  public function missing(array $needed): array {
    return array_values(array_diff($needed, array_keys($this->renditions())));
  }

  /**
   * Whether readers may see it.
   */
  public function isApproved(): bool {
    return $this->get('state')->value === self::APPROVED;
  }

  /**
   * Whether it is a house banner.
   */
  public function isHouse(): bool {
    return (bool) $this->get('house')->value;
  }

  /**
   * Whether a house banner may fill this space.
   */
  public function housesIn(string $slot_id): bool {
    $slots = $this->houseSlots();
    return $slots === [] || in_array($slot_id, $slots, TRUE);
  }

  /**
   * Space ids a house banner may fill; empty means all.
   *
   * Stored as {ids: [...]}: a map field keyed 0, 1… reads back on load as
   * one item per value and loses them.
   *
   * @return string[]
   *   Space ids.
   */
  public function houseSlots(): array {
    $value = $this->get('house_slots')->first()?->getValue() ?? [];
    return array_values(array_filter((array) ($value['ids'] ?? []), 'is_string'));
  }

  /**
   * Sets the spaces a house banner may fill.
   *
   * @param string[] $ids
   *   Space ids; empty for all.
   */
  public function setHouseSlots(array $ids): static {
    $this->set('house_slots', ['ids' => array_values($ids)]);
    return $this;
  }

  /**
   * The creative's kind.
   */
  public function kind(): string {
    return (string) ($this->get('kind')->value ?: self::PLAIN);
  }

  /**
   * Link target.
   */
  public function target(): string {
    return (string) $this->get('target_url')->value;
  }

}
