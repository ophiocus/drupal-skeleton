<?php

declare(strict_types=1);

namespace Drupal\ad_inventory\Element;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Render\Attribute\RenderElement;
use Drupal\Core\Render\Element\RenderElementBase;

/**
 * An ad slot a template prints itself, e.g. between items of its own list.
 *
 * Usage (build the array with self::build() so it carries cache keys; the
 * slot then renders, and records its placement, only on a cache miss):
 * @code
 * $build['ad_' . $i] = AdSlotElement::build('index_band', $node, $i);
 * // or, by hand:
 * $build['ad'] = [
 *   '#type' => 'ad_slot',
 *   '#slot' => 'index_band',
 *   // Optional: the page's entity, for its per-page controls.
 *   '#entity' => $node,
 *   // Optional: which repetition of a repeatable slot (0, 1, …).
 *   '#delta' => $i,
 *   // Optional: page context values for scoped bookings.
 *   '#scopes' => ['city:12'],
 * ];
 * @endcode
 */
#[RenderElement('ad_slot')]
class AdSlotElement extends RenderElementBase {

  /**
   * {@inheritdoc}
   */
  public function getInfo() {
    return [
      '#slot' => '',
      '#entity' => NULL,
      '#delta' => 0,
      '#scopes' => [],
      '#via' => 'template',
      '#pre_render' => [[static::class, 'preRenderSlot']],
    ];
  }

  /**
   * A cacheable slot element.
   *
   * @param string $slot
   *   The slot id.
   * @param \Drupal\Core\Entity\EntityInterface|null $entity
   *   The page's entity, or NULL for the current route's.
   * @param int $delta
   *   Which repetition of a repeatable slot.
   * @param string[] $scopes
   *   Page context values.
   * @param string $via
   *   How it is printed: "block" or "template".
   */
  public static function build(string $slot, ?EntityInterface $entity = NULL, int $delta = 0, array $scopes = [], string $via = 'template'): array {
    sort($scopes);
    return [
      '#type' => 'ad_slot',
      '#slot' => $slot,
      '#entity' => $entity,
      '#delta' => $delta,
      '#scopes' => $scopes,
      '#via' => $via,
      '#cache' => [
        'keys' => [
          'ad_inventory',
          'slot',
          $slot,
          $via,
          (string) $delta,
          $entity ? $entity->getEntityTypeId() . ':' . $entity->id() : '-',
          $scopes ? hash('xxh3', implode(',', $scopes)) : '-',
        ],
      ],
    ];
  }

  /**
   * Renders the slot.
   */
  public static function preRenderSlot(array $element): array {
    $entity = $element['#entity'] instanceof EntityInterface ? $element['#entity'] : NULL;
    $built = \Drupal::service('ad_inventory.slot_renderer')->build(
      (string) $element['#slot'],
      $entity,
      (int) $element['#delta'],
      (array) $element['#scopes'],
      (string) $element['#via'],
    );
    // Keep what the renderer already put on the element (its keys and the
    // required cache contexts) and add what the slot depends on.
    $metadata = CacheableMetadata::createFromRenderArray($element)->merge(CacheableMetadata::createFromRenderArray($built));
    unset($built['#cache']);
    $element = $built + $element;
    $metadata->applyTo($element);
    return $element;
  }

}
