<?php

declare(strict_types=1);

namespace Drupal\ad_inventory;

use Drupal\ad_inventory\Entity\AdOverride;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * One page's own banner settings, read and written by staff forms.
 */
final class OverrideStore {

  /**
   * Settings already loaded this request, by "type:id".
   *
   * @var array<string, array<string, array<string, mixed>>>
   */
  private array $loaded = [];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Settings per space id for a page (empty when it has none).
   *
   * @return array<string, array<string, mixed>>
   *   Settings.
   */
  public function get(EntityInterface $page): array {
    $key = $page->getEntityTypeId() . ':' . $page->id();
    if (!isset($this->loaded[$key])) {
      $record = $this->record($page);
      $this->loaded[$key] = $record?->settings() ?? [];
    }
    return $this->loaded[$key];
  }

  /**
   * Saves one space's settings for a page; empty settings remove them.
   *
   * @param \Drupal\Core\Entity\EntityInterface $page
   *   The page.
   * @param string $slot_id
   *   The space.
   * @param array<string, mixed> $settings
   *   Keys: off (bool), desktop and phone (format ids).
   */
  public function set(EntityInterface $page, string $slot_id, array $settings): void {
    $settings = array_filter($settings, static fn ($v) => $v !== '' && $v !== NULL && $v !== FALSE);
    $record = $this->record($page);
    $all = $record?->settings() ?? [];
    if ($settings === []) {
      unset($all[$slot_id]);
    }
    else {
      $all[$slot_id] = $settings;
    }
    if ($record === NULL) {
      if ($all === []) {
        return;
      }
      $record = $this->entityTypeManager->getStorage('ad_override')->create([
        'target_type' => $page->getEntityTypeId(),
        'target_id' => $page->id(),
      ]);
    }
    if ($all === []) {
      $record->delete();
    }
    else {
      $record->set('settings', $all)->save();
    }
    unset($this->loaded[$page->getEntityTypeId() . ':' . $page->id()]);
  }

  /**
   * The stored record of a page.
   */
  private function record(EntityInterface $page): ?AdOverride {
    $ids = $this->entityTypeManager->getStorage('ad_override')->getQuery()
      ->accessCheck(FALSE)
      ->condition('target_type', $page->getEntityTypeId())
      ->condition('target_id', (int) $page->id())
      ->range(0, 1)
      ->execute();
    if ($ids === []) {
      return NULL;
    }
    $record = $this->entityTypeManager->getStorage('ad_override')->load(reset($ids));
    return $record instanceof AdOverride ? $record : NULL;
  }

}
