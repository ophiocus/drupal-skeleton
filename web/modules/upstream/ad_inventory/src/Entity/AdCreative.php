<?php

declare(strict_types=1);

namespace Drupal\ad_inventory\Entity;

use Drupal\ad_inventory\Access\AdInventoryAccessControlHandler;
use Drupal\ad_inventory\Form\AdCreativeForm;
use Drupal\ad_inventory\ListBuilder\AdCreativeListBuilder;
use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\ContentEntityDeleteForm;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityPublishedTrait;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\Routing\AdminHtmlRouteProvider;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\media\MediaInterface;
use Drupal\user\UserInterface;

/**
 * An ad creative.
 *
 * Renditions are media images, one per format, uploaded at twice the size.
 * A creative marked "house" may fill any slot that allows house creatives;
 * otherwise it shows only through a booking.
 */
#[ContentEntityType(
  id: 'ad_creative',
  label: new TranslatableMarkup('Ad creative'),
  label_collection: new TranslatableMarkup('Ad creatives'),
  label_singular: new TranslatableMarkup('ad creative'),
  label_plural: new TranslatableMarkup('ad creatives'),
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
    'label' => 'label',
    'published' => 'status',
  ],
  handlers: [
    'access' => AdInventoryAccessControlHandler::class,
    'list_builder' => AdCreativeListBuilder::class,
    'form' => [
      'default' => AdCreativeForm::class,
      'add' => AdCreativeForm::class,
      'edit' => AdCreativeForm::class,
      'delete' => ContentEntityDeleteForm::class,
    ],
    'route_provider' => [
      'html' => AdminHtmlRouteProvider::class,
    ],
  ],
  links: [
    'collection' => '/admin/content/ad-creative',
    'add-form' => '/admin/content/ad-creative/add',
    'edit-form' => '/admin/content/ad-creative/{ad_creative}',
    'delete-form' => '/admin/content/ad-creative/{ad_creative}/delete',
  ],
  admin_permission: 'administer ad inventory',
  base_table: 'ad_creative',
  label_count: [
    'singular' => '@count ad creative',
    'plural' => '@count ad creatives',
  ],
)]
class AdCreative extends ContentEntityBase implements AdCreativeInterface {

  use EntityChangedTrait;
  use EntityPublishedTrait;

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);
    $fields += static::publishedBaseFieldDefinitions($entity_type);

    $fields['label'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Name'))
      ->setDescription(new TranslatableMarkup('For editors and reports; readers never see it.'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 255)
      ->setDisplayOptions('form', ['type' => 'string_textfield', 'weight' => -10])
      ->setDisplayConfigurable('form', TRUE);

    $fields['status']
      ->setLabel(new TranslatableMarkup('Active'))
      ->setDescription(new TranslatableMarkup('An inactive creative shows nowhere, booked or not.'))
      ->setDisplayOptions('form', ['type' => 'boolean_checkbox', 'weight' => 20])
      ->setDisplayConfigurable('form', TRUE);

    $fields['house'] = BaseFieldDefinition::create('boolean')
      ->setLabel(new TranslatableMarkup('House creative'))
      ->setDescription(new TranslatableMarkup('Fills any slot that allows house creatives when no booking or network unit does.'))
      ->setDefaultValue(FALSE)
      ->setDisplayOptions('form', ['type' => 'boolean_checkbox', 'weight' => 21])
      ->setDisplayConfigurable('form', TRUE);

    $fields['renditions'] = BaseFieldDefinition::create('ad_rendition')
      ->setLabel(new TranslatableMarkup('Renditions'))
      ->setDescription(new TranslatableMarkup('One image per format, uploaded at twice its size (1456×180 for a 728×90 leaderboard). Its ratio must match the format within 1%. A phone rendition is its own design, not the desktop image shrunk.'))
      ->setCardinality(FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED)
      ->setSetting('target_type', 'media')
      ->setDisplayOptions('form', ['type' => 'ad_rendition_default', 'weight' => -5])
      ->setDisplayConfigurable('form', TRUE);

    $fields['target_url'] = BaseFieldDefinition::create('uri')
      ->setLabel(new TranslatableMarkup('Target address'))
      ->setDescription(new TranslatableMarkup('The absolute address a click leads to, through the counted redirect.'))
      ->setDisplayOptions('form', ['type' => 'uri', 'weight' => 0])
      ->setDisplayConfigurable('form', TRUE);

    $fields['alt'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Alternative text'))
      ->setDescription(new TranslatableMarkup('What the image says, for readers who cannot see it.'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 255)
      ->setDisplayOptions('form', ['type' => 'string_textfield', 'weight' => 1])
      ->setDisplayConfigurable('form', TRUE);

    $fields['caption'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Caption'))
      ->setDescription(new TranslatableMarkup('One line under the image (a registration code a law requires, for example).'))
      ->setSetting('max_length', 255)
      ->setDisplayOptions('form', ['type' => 'string_textfield', 'weight' => 2])
      ->setDisplayConfigurable('form', TRUE);

    $fields['kind'] = BaseFieldDefinition::create('list_string')
      ->setLabel(new TranslatableMarkup('Kind'))
      ->setDescription(new TranslatableMarkup('"Plain", or a kind the site defines (a promoted event); a slot may limit the kinds it takes.'))
      ->setRequired(TRUE)
      ->setDefaultValue('plain')
      ->setSetting('allowed_values_function', 'Drupal\ad_inventory\Entity\AdCreative::kindOptions')
      ->setDisplayOptions('form', ['type' => 'options_select', 'weight' => 3])
      ->setDisplayConfigurable('form', TRUE);

    $fields['advertiser'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Advertiser account'))
      ->setDescription(new TranslatableMarkup("The account a site may let see this creative's bookings."))
      ->setSetting('target_type', 'user')
      ->setDisplayOptions('form', ['type' => 'entity_reference_autocomplete', 'weight' => 5])
      ->setDisplayConfigurable('form', TRUE);

    $fields['advertiser_name'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Advertiser'))
      ->setDescription(new TranslatableMarkup("The advertiser's name, when there is no account."))
      ->setSetting('max_length', 255)
      ->setDisplayOptions('form', ['type' => 'string_textfield', 'weight' => 6])
      ->setDisplayConfigurable('form', TRUE);

    $fields['promoted_type'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Promoted entity type'))
      ->setDescription(new TranslatableMarkup('Optional: the type of the content this creative promotes (node, for example).'))
      ->setSetting('max_length', 64)
      ->setDisplayOptions('form', ['type' => 'string_textfield', 'weight' => 7])
      ->setDisplayConfigurable('form', TRUE);

    $fields['promoted_id'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Promoted entity id'))
      ->setSetting('max_length', 64)
      ->setDisplayOptions('form', ['type' => 'string_textfield', 'weight' => 8])
      ->setDisplayConfigurable('form', TRUE);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(new TranslatableMarkup('Created'));

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(new TranslatableMarkup('Changed'));

    return $fields;
  }

  /**
   * Allowed values of the kind field: "plain" and the site's kinds.
   *
   * @return array<string, string>
   *   Labels keyed by kind id.
   */
  public static function kindOptions(): array {
    $options = ['plain' => (string) new TranslatableMarkup('Plain')];
    foreach ((array) \Drupal::config('ad_inventory.settings')->get('kinds') as $kind) {
      if (!empty($kind['id'])) {
        $options[(string) $kind['id']] = (string) ($kind['label'] ?? $kind['id']);
      }
    }
    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function isHouse(): bool {
    return (bool) $this->get('house')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function rendition(string $format): ?MediaInterface {
    foreach ($this->get('renditions') as $item) {
      if ((string) $item->format === $format && $item->entity instanceof MediaInterface) {
        return $item->entity;
      }
    }
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function renditionFormats(): array {
    $formats = [];
    foreach ($this->get('renditions') as $item) {
      if ((string) $item->format !== '' && $item->target_id) {
        $formats[] = (string) $item->format;
      }
    }
    return array_values(array_unique($formats));
  }

  /**
   * {@inheritdoc}
   */
  public function targetUrl(): string {
    return (string) $this->get('target_url')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function alt(): string {
    return (string) $this->get('alt')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function caption(): string {
    return (string) $this->get('caption')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function kind(): string {
    return (string) ($this->get('kind')->value ?: 'plain');
  }

  /**
   * {@inheritdoc}
   */
  public function advertiserName(): string {
    $name = trim((string) $this->get('advertiser_name')->value);
    if ($name !== '') {
      return $name;
    }
    $account = $this->get('advertiser')->entity;
    return $account instanceof UserInterface ? (string) $account->getDisplayName() : '';
  }

}
