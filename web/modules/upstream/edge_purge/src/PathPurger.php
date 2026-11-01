<?php

declare(strict_types=1);

namespace Drupal\edge_purge;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityPublishedInterface;
use Drupal\Core\Entity\TranslatableInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\path_alias\AliasManagerInterface;
use Drupal\path_alias\PathAliasInterface;

/**
 * Queues the path tags of addresses that may answer differently now.
 *
 * A 404, 403 or 410 stays at the edge under its path's tag (EdgeTags::pathTag)
 * until one of these happens at that address:
 * - an entity with a page is created (its own path, and its aliases);
 * - an entity is published or unpublished;
 * - an alias is created, changed or deleted (the old address and the new).
 * Each in every language prefix the address can carry.
 */
final class PathPurger {

  public function __construct(
    private readonly PurgeQueue $queue,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly ?AliasManagerInterface $aliasManager = NULL,
  ) {}

  /**
   * An entity was created.
   */
  public function inserted(EntityInterface $entity): void {
    if ($entity instanceof PathAliasInterface) {
      $this->queuePaths($this->aliasPaths($entity));
      return;
    }
    $this->queuePaths($this->entityPaths($entity));
  }

  /**
   * An entity was saved again.
   */
  public function updated(EntityInterface $entity): void {
    $original = method_exists($entity, 'getOriginal') ? $entity->getOriginal() : NULL;
    if ($entity instanceof PathAliasInterface) {
      $paths = $this->aliasPaths($entity);
      if ($original instanceof PathAliasInterface) {
        $paths = array_merge($paths, $this->aliasPaths($original));
      }
      $this->queuePaths($paths);
      return;
    }
    // Only a change of who may see it changes what its address answers.
    if ($entity instanceof EntityPublishedInterface && $original instanceof EntityPublishedInterface && $entity->isPublished() !== $original->isPublished()) {
      $this->queuePaths($this->entityPaths($entity));
    }
  }

  /**
   * An entity was deleted.
   */
  public function deleted(EntityInterface $entity): void {
    // A deleted entity's own page purges by its tags; only an alias's
    // address can start answering something else.
    if ($entity instanceof PathAliasInterface) {
      $this->queuePaths($this->aliasPaths($entity));
    }
  }

  /**
   * Queues the path tags of these addresses.
   *
   * @param string[] $paths
   *   Paths as the site serves them, language prefix included.
   */
  public function queuePaths(array $paths): void {
    $tags = array_values(array_unique(array_map(EdgeTags::pathTag(...), $paths)));
    if (!$tags) {
      return;
    }
    try {
      $this->queue->add($tags);
    }
    catch (\Exception) {
      // No table while the module is being installed. A missed purge costs
      // one CDN lifetime, never the save.
    }
  }

  /**
   * The addresses of an entity's page: its system path and its aliases.
   *
   * @return string[]
   *   Paths, each with every language prefix the entity can be read under.
   */
  private function entityPaths(EntityInterface $entity): array {
    if ($entity->isNew() || !$entity->hasLinkTemplate('canonical')) {
      return [];
    }
    try {
      $system = '/' . ltrim($entity->toUrl('canonical')->getInternalPath(), '/');
    }
    catch (\Exception) {
      return [];
    }
    $langcodes = $entity instanceof TranslatableInterface
      ? array_keys($entity->getTranslationLanguages())
      : [$entity->language()->getId()];
    $paths = [];
    foreach ($langcodes as $langcode) {
      $aliases = [$system];
      if ($this->aliasManager) {
        $aliases[] = $this->aliasManager->getAliasByPath($system, $langcode);
      }
      foreach (array_unique($aliases) as $path) {
        $paths = array_merge($paths, $this->prefixed($path, $langcode));
      }
    }
    return $paths;
  }

  /**
   * The addresses an alias answers at.
   *
   * @return string[]
   *   The alias, with its language's prefix (every prefix for an alias in no
   *   particular language).
   */
  private function aliasPaths(PathAliasInterface $alias): array {
    return $this->prefixed($alias->getAlias(), $alias->language()->getId());
  }

  /**
   * A path as each language prefix serves it.
   *
   * @return string[]
   *   One path per prefix: the path itself where the prefix is empty or the
   *   site has no path prefixes.
   */
  private function prefixed(string $path, string $langcode): array {
    $path = '/' . ltrim($path, '/');
    $negotiation = $this->configFactory->get('language.negotiation');
    $prefixes = $negotiation->get('url.source') === 'path_prefix' ? (array) $negotiation->get('url.prefixes') : [];
    if (!$prefixes) {
      return [$path];
    }
    $any = [LanguageInterface::LANGCODE_NOT_SPECIFIED, LanguageInterface::LANGCODE_NOT_APPLICABLE];
    $chosen = in_array($langcode, $any, TRUE) || !isset($prefixes[$langcode])
      ? array_values($prefixes)
      : [$prefixes[$langcode]];
    $paths = [];
    foreach (array_unique($chosen) as $prefix) {
      $paths[] = $prefix === '' ? $path : '/' . $prefix . $path;
    }
    return $paths;
  }

}
