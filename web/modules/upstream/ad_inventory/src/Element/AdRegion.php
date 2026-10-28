<?php

declare(strict_types=1);

namespace Drupal\ad_inventory\Element;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Render\Attribute\RenderElement;
use Drupal\Core\Render\Element\RenderElementBase;

/**
 * The blocks placed in a theme region, printed inside another template.
 *
 * Banner spaces are only ever placed as configured blocks. When a space
 * belongs inside a template that owns a list or a column (not in a page
 * region), the theme declares a region the page template does not print,
 * staff place blocks in it like in any region, and the template prints
 * this element where the region goes. Code names the region, never a space.
 *
 * Usage:
 * @code
 * $variables['column'] = ['#type' => 'ad_inventory_region', '#region' => 'poster_column'];
 * @endcode
 */
#[RenderElement('ad_inventory_region')]
final class AdRegion extends RenderElementBase {

  /**
   * {@inheritdoc}
   */
  public function getInfo(): array {
    return [
      '#region' => '',
      '#pre_render' => [[self::class, 'preRender']],
    ];
  }

  /**
   * Replaces the element with the region's visible blocks.
   *
   * @param array<string, mixed> $element
   *   The element.
   *
   * @return array<string, mixed>
   *   The blocks, with what their visibility depends on.
   */
  public static function preRender(array $element): array {
    $region = (string) $element['#region'];
    /** @var \Drupal\block\BlockRepositoryInterface $repository */
    $repository = \Drupal::service('block.repository');
    $cacheability = [];
    $regions = $repository->getVisibleBlocksPerRegion($cacheability);
    $build = [];
    $view_builder = \Drupal::entityTypeManager()->getViewBuilder('block');
    foreach ($regions[$region] ?? [] as $id => $block) {
      $build[$id] = $view_builder->view($block);
    }
    ($cacheability[$region] ?? new CacheableMetadata())
      ->addCacheTags(['config:block_list'])
      ->applyTo($build);
    return $build;
  }

}
