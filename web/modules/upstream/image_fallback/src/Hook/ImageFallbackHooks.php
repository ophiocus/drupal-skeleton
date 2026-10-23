<?php

declare(strict_types=1);

namespace Drupal\image_fallback\Hook;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Extension\ThemeSettingsProvider;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StreamWrapper\StreamWrapperManager;
use Drupal\Core\Theme\ThemeInitializationInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Renders the fallback image in place of an empty, listed field.
 */
final class ImageFallbackHooks {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly ThemeSettingsProvider $themeSettings,
    private readonly ThemeInitializationInterface $themeInitialization,
    #[Autowire(param: 'app.root')]
    private readonly string $root,
  ) {}

  /**
   * Implements hook_entity_display_build_alter().
   *
   * Every display build passes here: an entity's view mode, a Views field,
   * a Layout Builder field block. Only a listed field that the display shows
   * and that is empty is touched, and only its render array gets this
   * module's cache tags: a tag on every entity would be a tag on nearly every
   * page (DEPLOY.md §7, "whole-page tags"). Renders cached before a field was
   * listed are invalidated by the settings save (SettingsSaved).
   */
  #[Hook('entity_display_build_alter')]
  public function entityDisplayBuildAlter(array &$build, array $context): void {
    $entity = $context['entity'];
    if (!$entity instanceof FieldableEntityInterface) {
      return;
    }
    $settings = $this->configFactory->get('image_fallback.settings');
    $prefix = $entity->getEntityTypeId() . '.' . $entity->bundle() . '.';
    foreach ($settings->get('fields') ?? [] as $field) {
      $key = (string) ($field['field'] ?? '');
      if (!str_starts_with($key, $prefix)) {
        continue;
      }
      $field_name = substr($key, strlen($prefix));
      $component = $context['display']->getComponent($field_name);
      if ($component === NULL || !$entity->hasField($field_name)) {
        continue;
      }
      $items = $entity->get($field_name);
      if (!$items->isEmpty()) {
        continue;
      }

      // A field with no source of its own takes the default whole.
      $image = ($field['source'] ?? '') === '' ? $settings->get('default') : $field;
      $access = $items->access('view', NULL, TRUE);
      $cacheability = CacheableMetadata::createFromRenderArray($build[$field_name] ?? [])
        ->addCacheableDependency($access)
        ->addCacheableDependency($settings);
      $uri = $this->uri($image['source'] ?? 'none', (string) ($image['path'] ?? ''), $cacheability);
      $element = [];
      if ($access->isAllowed() && $uri !== NULL) {
        $element = $this->element($items, $component, $context['view_mode'], $uri, (string) ($image['alt'] ?? ''));
      }
      $cacheability->applyTo($element);
      $build[$field_name] = $element;
    }
  }

  /**
   * The fallback image's URI, or NULL when there is none to show.
   *
   * @param string $source
   *   The source: logo, path or none.
   * @param string $path
   *   The path, for the path source.
   * @param \Drupal\Core\Cache\CacheableMetadata $cacheability
   *   Collects what the answer depends on.
   */
  private function uri(string $source, string $path, CacheableMetadata $cacheability): ?string {
    if ($source === 'logo') {
      $theme = (string) $this->configFactory->get('system.theme')->get('default');
      $cacheability->addCacheTags(['config:system.theme']);
      if ($theme === '') {
        return NULL;
      }
      $cacheability->addCacheTags(['config:system.theme.global', 'config:' . $theme . '.settings']);
      $path = $this->themeSettings->getSetting('logo.use_default', $theme)
        ? $this->themeInitialization->getActiveThemeByName($theme)->getLogo()
        : (string) $this->themeSettings->getSetting('logo.path', $theme);
    }
    elseif ($source !== 'path') {
      return NULL;
    }
    // A stream URI (public://) as theme settings may hold, or a path relative
    // to the Drupal root.
    $stream = (bool) StreamWrapperManager::getScheme($path);
    $path = $stream ? $path : ltrim($path, '/');
    if ($path === '') {
      return NULL;
    }
    return is_file($stream ? $path : $this->root . '/' . $path) ? $path : NULL;
  }

  /**
   * A field render array, as the field's formatter would build it.
   *
   * The field template draws the field's own wrapper and classes; the
   * image-fallback class is added to them, so a theme can size the image as
   * it sizes a real one, or tell the two apart.
   *
   * @param \Drupal\Core\Field\FieldItemListInterface $items
   *   The empty field.
   * @param array $component
   *   The field's display component.
   * @param string $view_mode
   *   The view mode being built.
   * @param string $uri
   *   The fallback image.
   * @param string $alt
   *   Alternative text; empty for a decorative image.
   */
  private function element(FieldItemListInterface $items, array $component, string $view_mode, string $uri, string $alt): array {
    $definition = $items->getFieldDefinition();
    $entity = $items->getEntity();
    $content = [
      '#theme' => 'image',
      '#uri' => $uri,
      '#alt' => $alt,
    ];
    // The image and media thumbnail formatters' "link to content" setting.
    if (($component['settings']['image_link'] ?? '') === 'content' && !$entity->isNew() && $entity->hasLinkTemplate('canonical')) {
      $content = [
        '#type' => 'link',
        '#title' => $content,
        '#url' => $entity->toUrl(),
      ];
    }
    return [
      '#theme' => 'field',
      '#title' => $definition->getLabel(),
      '#label_display' => $component['label'] ?? 'above',
      '#view_mode' => $view_mode,
      '#language' => $items->getLangcode(),
      '#field_name' => $definition->getName(),
      '#field_type' => $definition->getType(),
      '#field_translatable' => $definition->isTranslatable(),
      '#entity_type' => $entity->getEntityTypeId(),
      '#bundle' => $entity->bundle(),
      '#object' => $entity,
      '#items' => $items,
      '#formatter' => $component['type'] ?? '',
      '#is_multiple' => $definition->getFieldStorageDefinition()->isMultiple(),
      '#third_party_settings' => $component['third_party_settings'] ?? [],
      '#weight' => $component['weight'] ?? 0,
      '#attributes' => ['class' => ['image-fallback']],
      0 => $content,
    ];
  }

}
