<?php

declare(strict_types=1);

namespace Drupal\ad_inventory\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\Core\Entity\Attribute\ConfigEntityType;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * A banner size: its width and height in CSS pixels.
 *
 * A creative is uploaded at twice this size for sharp screens. A space keeps
 * the format's proportion and shrinks with a narrower column, so its box is
 * known before anything loads.
 */
#[ConfigEntityType(
  id: 'ad_format',
  label: new TranslatableMarkup('Banner format'),
  label_collection: new TranslatableMarkup('Banner formats'),
  config_prefix: 'format',
  entity_keys: [
    'id' => 'id',
    'label' => 'label',
    'weight' => 'weight',
  ],
  handlers: [
    'list_builder' => 'Drupal\ad_inventory\ListBuilder\AdFormatListBuilder',
    'form' => [
      'default' => 'Drupal\ad_inventory\Form\AdFormatForm',
      'delete' => 'Drupal\Core\Entity\EntityDeleteForm',
    ],
    'route_provider' => [
      'html' => 'Drupal\Core\Entity\Routing\AdminHtmlRouteProvider',
    ],
  ],
  links: [
    'collection' => '/admin/structure/ad-inventory/formats',
    'add-form' => '/admin/structure/ad-inventory/formats/add',
    'edit-form' => '/admin/structure/ad-inventory/formats/{ad_format}',
    'delete-form' => '/admin/structure/ad-inventory/formats/{ad_format}/delete',
  ],
  admin_permission: 'administer ad inventory',
  config_export: ['id', 'label', 'width', 'height', 'weight'],
)]
final class AdFormat extends ConfigEntityBase {

  /**
   * Machine name.
   */
  protected string $id;

  /**
   * Human name.
   */
  protected string $label;

  /**
   * Width in CSS pixels.
   */
  protected int $width = 0;

  /**
   * Height in CSS pixels.
   */
  protected int $height = 0;

  /**
   * Sort order in lists.
   */
  protected int $weight = 0;

  /**
   * Width in CSS pixels.
   */
  public function width(): int {
    return $this->width;
  }

  /**
   * Height in CSS pixels.
   */
  public function height(): int {
    return $this->height;
  }

  /**
   * The size as text, for example "728×90".
   */
  public function size(): string {
    return $this->width . '×' . $this->height;
  }

  /**
   * Whether an image of these pixel dimensions fits this format.
   *
   * The proportion must match within 1%, and the image must be at least the
   * format's own size (twice is asked for).
   */
  public function fits(int $width, int $height): bool {
    if ($this->width <= 0 || $this->height <= 0 || $width < $this->width || $height < $this->height) {
      return FALSE;
    }
    $expected = $this->width / $this->height;
    $actual = $width / $height;
    return abs($actual - $expected) / $expected <= 0.01;
  }

}
