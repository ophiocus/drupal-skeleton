<?php

declare(strict_types=1);

namespace Drupal\ad_inventory\Entity;

use Drupal\ad_inventory\Form\AdFormatForm;
use Drupal\ad_inventory\ListBuilder\AdFormatListBuilder;
use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\Core\Entity\Attribute\ConfigEntityType;
use Drupal\Core\Entity\EntityDeleteForm;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\Routing\AdminHtmlRouteProvider;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\image\Entity\ImageStyle;

/**
 * An ad format: a reserved size, and how network units fill it.
 *
 * Each format owns two image styles, "ad_inventory_<id>_1x" and "_2x"
 * (scale and crop to the size and to twice the size), created when the
 * format is saved outside a configuration import. A creative's rendition is
 * uploaded at twice the size and printed through both.
 */
#[ConfigEntityType(
  id: 'ad_format',
  label: new TranslatableMarkup('Ad format'),
  label_collection: new TranslatableMarkup('Ad formats'),
  label_singular: new TranslatableMarkup('ad format'),
  label_plural: new TranslatableMarkup('ad formats'),
  config_prefix: 'format',
  entity_keys: [
    'id' => 'id',
    'label' => 'label',
  ],
  handlers: [
    'list_builder' => AdFormatListBuilder::class,
    'form' => [
      'add' => AdFormatForm::class,
      'edit' => AdFormatForm::class,
      'delete' => EntityDeleteForm::class,
    ],
    'route_provider' => [
      'html' => AdminHtmlRouteProvider::class,
    ],
  ],
  links: [
    'collection' => '/admin/structure/ad-inventory/formats',
    'add-form' => '/admin/structure/ad-inventory/formats/add',
    'edit-form' => '/admin/structure/ad-inventory/formats/{ad_format}',
    'delete-form' => '/admin/structure/ad-inventory/formats/{ad_format}/delete',
  ],
  admin_permission: 'administer ad inventory',
  label_count: [
    'singular' => '@count ad format',
    'plural' => '@count ad formats',
  ],
  config_export: [
    'id',
    'label',
    'width',
    'height',
    'network_sizes',
  ],
)]
class AdFormat extends ConfigEntityBase implements AdFormatInterface {

  /**
   * The machine name.
   *
   * @var string
   */
  protected $id;

  /**
   * The name editors read.
   *
   * @var string
   */
  protected $label;

  /**
   * The width in CSS pixels.
   *
   * @var int
   */
  protected $width = 0;

  /**
   * The height in CSS pixels.
   *
   * @var int
   */
  protected $height = 0;

  /**
   * Network sizes per breakpoint.
   *
   * @var array
   */
  protected $network_sizes = [];

  /**
   * {@inheritdoc}
   */
  public function width(): int {
    return (int) $this->width;
  }

  /**
   * {@inheritdoc}
   */
  public function height(): int {
    return (int) $this->height;
  }

  /**
   * {@inheritdoc}
   */
  public function networkSizes(): array {
    $sizes = [];
    foreach ((array) $this->network_sizes as $row) {
      $sizes[] = [
        'min_width' => (int) ($row['min_width'] ?? 0),
        'width' => (int) ($row['width'] ?? 0),
        'height' => (int) ($row['height'] ?? 0),
      ];
    }
    usort($sizes, static fn(array $a, array $b): int => $a['min_width'] <=> $b['min_width']);
    return $sizes;
  }

  /**
   * {@inheritdoc}
   */
  public function styleName(int $density): string {
    return 'ad_inventory_' . $this->id() . '_' . $density . 'x';
  }

  /**
   * {@inheritdoc}
   */
  public function postSave(EntityStorageInterface $storage, $update = TRUE): void {
    parent::postSave($storage, $update);
    // A configuration import brings the styles with it.
    if (!$this->isSyncing()) {
      $this->ensureStyles();
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function postDelete(EntityStorageInterface $storage, array $entities): void {
    parent::postDelete($storage, $entities);
    foreach ($entities as $format) {
      if ($format instanceof AdFormatInterface && !$format->isSyncing()) {
        foreach ([1, 2] as $density) {
          ImageStyle::load($format->styleName($density))?->delete();
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function ensureStyles(): void {
    if ($this->width() < 1 || $this->height() < 1) {
      return;
    }
    foreach ([1, 2] as $density) {
      $name = $this->styleName($density);
      $width = $this->width() * $density;
      $height = $this->height() * $density;
      $style = ImageStyle::load($name);
      if ($style === NULL) {
        $style = ImageStyle::create([
          'name' => $name,
          'label' => sprintf('Ad: %s %dx', $this->label(), $density),
        ]);
      }
      $current = [];
      foreach ($style->getEffects() as $effect) {
        $current[] = [$effect->getPluginId(), $effect->getConfiguration()['data'] ?? []];
      }
      $wanted = ['width' => $width, 'height' => $height, 'anchor' => 'center-center'];
      if (count($current) === 1 && $current[0][0] === 'image_scale_and_crop' && $current[0][1] == $wanted && !$style->isNew()) {
        continue;
      }
      foreach ($style->getEffects() as $effect) {
        $style->deleteImageEffect($effect);
      }
      $style->addImageEffect([
        'id' => 'image_scale_and_crop',
        'weight' => 0,
        'data' => $wanted,
      ]);
      $style->save();
    }
  }

}
