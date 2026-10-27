<?php

declare(strict_types=1);

namespace Drupal\ad_inventory;

use Drupal\ad_inventory\Plugin\Field\FieldType\AdOverridesItem;
use Drupal\Core\Cache\RefinableCacheableDependencyInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Routing\RouteMatchInterface;

/**
 * Per-page slot controls: reading them and normalising what a form sends.
 *
 * Controls live on the page's entity (field type `ad_inventory_overrides`),
 * never on the reader, so a page stays one HTML for everyone.
 */
final class Overrides {

  /**
   * The field type that stores them.
   */
  public const FIELD_TYPE = 'ad_inventory_overrides';

  public function __construct(
    private readonly EntityFieldManagerInterface $fieldManager,
    private readonly RouteMatchInterface $routeMatch,
  ) {}

  /**
   * The controls for one slot on an entity's page, or on the current page.
   *
   * @param string $slot
   *   The slot id.
   * @param \Drupal\Core\Entity\EntityInterface|null $entity
   *   The page's entity; NULL reads the current route's.
   * @param \Drupal\Core\Cache\RefinableCacheableDependencyInterface $cacheability
   *   Receives what the answer depends on.
   *
   * @return array
   *   Keys (each optional): off, format, phone_format, count.
   */
  public function forSlot(string $slot, ?EntityInterface $entity, RefinableCacheableDependencyInterface $cacheability): array {
    $all = $entity !== NULL ? $this->ofEntity($entity, $cacheability) : $this->ofRoute($cacheability);
    return is_array($all[$slot] ?? NULL) ? $all[$slot] : [];
  }

  /**
   * Every slot's controls on an entity.
   */
  public function ofEntity(EntityInterface $entity, RefinableCacheableDependencyInterface $cacheability): array {
    if (!$entity instanceof FieldableEntityInterface) {
      return [];
    }
    $found = [];
    foreach ($entity->getFieldDefinitions() as $name => $definition) {
      if ($definition->getType() === self::FIELD_TYPE) {
        $cacheability->addCacheableDependency($entity);
        $found += AdOverridesItem::decode((string) $entity->get($name)->value);
      }
    }
    return $found;
  }

  /**
   * Every slot's controls on the current route's entity.
   */
  public function ofRoute(RefinableCacheableDependencyInterface $cacheability): array {
    // Nothing varies by page until some entity type carries the field; adding
    // one invalidates this tag, so slots rendered before it learn the route.
    $cacheability->addCacheTags(['entity_field_info']);
    if (!$this->fieldManager->getFieldMapByFieldType(self::FIELD_TYPE)) {
      return [];
    }
    $cacheability->addCacheContexts(['route']);
    foreach ($this->routeMatch->getParameters()->all() as $parameter) {
      if ($parameter instanceof FieldableEntityInterface) {
        $found = $this->ofEntity($parameter, $cacheability);
        if ($found) {
          return $found;
        }
      }
    }
    return [];
  }

  /**
   * What a slot does on a page once its controls are applied.
   *
   * @param array $override
   *   The slot's controls.
   * @param string $desktop
   *   The slot's desktop format.
   * @param string $phone
   *   The slot's phone format.
   *
   * @return array{off: bool, desktop: string, phone: string, count: int|null}
   *   count NULL means no limit.
   */
  public static function apply(array $override, string $desktop, string $phone): array {
    return [
      'off' => !empty($override['off']),
      'desktop' => is_string($override['format'] ?? NULL) && $override['format'] !== '' ? $override['format'] : $desktop,
      'phone' => is_string($override['phone_format'] ?? NULL) && $override['phone_format'] !== '' ? $override['phone_format'] : $phone,
      'count' => isset($override['count']) && is_numeric($override['count']) ? max(0, (int) $override['count']) : NULL,
    ];
  }

  /**
   * Keeps only what differs from each slot, and only allowed values.
   *
   * @param array $input
   *   Per slot id: show (bool), format, phone_format, count ('' for no
   *   limit).
   * @param array $slots
   *   Per slot id: desktop, phone, desktop_options, phone_options (format
   *   ids), repeatable (bool).
   *
   * @return array
   *   The controls to store, keyed by slot id.
   */
  public static function normalize(array $input, array $slots): array {
    $out = [];
    foreach ($slots as $id => $slot) {
      $row = is_array($input[$id] ?? NULL) ? $input[$id] : [];
      $control = [];
      if (array_key_exists('show', $row) && !$row['show']) {
        $control['off'] = TRUE;
      }
      $format = (string) ($row['format'] ?? '');
      if ($format !== '' && $format !== $slot['desktop'] && in_array($format, $slot['desktop_options'], TRUE)) {
        $control['format'] = $format;
      }
      $phone = (string) ($row['phone_format'] ?? '');
      if ($phone !== '' && $phone !== $slot['phone'] && in_array($phone, $slot['phone_options'], TRUE)) {
        $control['phone_format'] = $phone;
      }
      $count = $row['count'] ?? '';
      if (!empty($slot['repeatable']) && $count !== '' && $count !== NULL && is_numeric($count)) {
        $control['count'] = max(0, (int) $count);
      }
      if ($control) {
        $out[$id] = $control;
      }
    }
    return $out;
  }

}
