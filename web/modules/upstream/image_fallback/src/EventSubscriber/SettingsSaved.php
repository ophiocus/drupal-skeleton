<?php

declare(strict_types=1);

namespace Drupal\image_fallback\EventSubscriber;

use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Config\ConfigCrudEvent;
use Drupal\Core\Config\ConfigEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Re-renders entities whose type gained a listed field.
 *
 * The settings' cache tag is only on entities the fallback touched, so a
 * render cached before its field was listed does not carry it, and a deploy
 * keeps the render cache (DEPLOY.md §7). Listing a field therefore
 * invalidates its entity type's renders (<entity_type>_view); any other
 * change (a source, a path, an alt) reaches the touched entities through the
 * settings' tag.
 */
final class SettingsSaved implements EventSubscriberInterface {

  public function __construct(
    private readonly CacheTagsInvalidatorInterface $invalidator,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [ConfigEvents::SAVE => 'onSave'];
  }

  /**
   * Invalidates the view tags of entity types with a newly listed field.
   */
  public function onSave(ConfigCrudEvent $event): void {
    $config = $event->getConfig();
    if ($config->getName() !== 'image_fallback.settings' || !$event->isChanged('fields')) {
      return;
    }
    $names = static fn (?array $fields): array => array_map(
      static fn (array $field): string => (string) ($field['field'] ?? ''),
      $fields ?? [],
    );
    $added = array_diff($names($config->get('fields')), $names($config->getOriginal('fields')));
    $tags = [];
    foreach ($added as $key) {
      $tags[] = strtok($key, '.') . '_view';
    }
    if ($tags) {
      $this->invalidator->invalidateTags(array_values(array_unique($tags)));
    }
  }

}
