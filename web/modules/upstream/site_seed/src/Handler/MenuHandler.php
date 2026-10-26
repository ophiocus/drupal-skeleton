<?php

declare(strict_types=1);

namespace Drupal\site_seed\Handler;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\site_seed\SeedContext;
use Drupal\site_seed\SeedException;

/**
 * Section `menu:`, menu links matched by menu and link URI.
 *
 * Each row: menu, uri, title, weight; optionally `parent` (the URI of
 * another link in the same menu, whose row comes earlier in the file) and
 * `expanded` (a bool, default FALSE).
 */
final class MenuHandler implements SeedHandlerInterface {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function section(): string {
    return 'menu';
  }

  /**
   * {@inheritdoc}
   */
  public function weight(): int {
    return 30;
  }

  /**
   * {@inheritdoc}
   */
  public function applies(): bool {
    return $this->entityTypeManager->hasDefinition('menu_link_content');
  }

  /**
   * {@inheritdoc}
   */
  public function converge(array $rows, SeedContext $context): void {
    $storage = $this->entityTypeManager->getStorage('menu_link_content');
    // This run's links by menu and URI: a parent created earlier in the file
    // is found here even in a check, where it is never saved.
    $seeded = [];
    foreach ($rows as $row) {
      $weight = (int) ($row['weight'] ?? 0);
      $expanded = (bool) ($row['expanded'] ?? FALSE);
      $parent = isset($row['parent']) ? $this->parent($seeded, $row) : '';
      $link = $this->find($storage, $row['menu'], $row['uri']);
      if (!$link) {
        $context->save($link = $storage->create([
          'title' => $row['title'],
          'link' => ['uri' => $row['uri']],
          'menu_name' => $row['menu'],
          'weight' => $weight,
          'expanded' => $expanded,
          'parent' => $parent,
        ]));
        $context->count('menu_links');
      }
      elseif ((string) $link->label() !== $row['title']
        || (int) $link->get('weight')->value !== $weight
        || (bool) $link->get('expanded')->value !== $expanded
        || (string) $link->get('parent')->value !== $parent) {
        $link->set('title', $row['title'])
          ->set('weight', $weight)
          ->set('expanded', $expanded)
          ->set('parent', $parent);
        $context->save($link);
        $context->count('updated');
      }
      $seeded[$row['menu']][$row['uri']] = $link;
    }
  }

  /**
   * The link in a menu with a URI, if any.
   */
  private function find(EntityStorageInterface $storage, string $menu, string $uri): ?ContentEntityInterface {
    $hit = $storage->loadByProperties(['menu_name' => $menu, 'link__uri' => $uri]);
    $link = $hit ? reset($hit) : NULL;
    return $link instanceof ContentEntityInterface ? $link : NULL;
  }

  /**
   * A row's parent as a menu plugin id, from the rows seeded before it.
   *
   * @param array<string, array<string, \Drupal\Core\Entity\ContentEntityInterface>> $seeded
   *   This run's links by menu and URI.
   * @param array<string, mixed> $row
   *   The seed row.
   *
   * @throws \Drupal\site_seed\SeedException
   *   When no earlier row in the same menu has the parent's URI.
   */
  private function parent(array $seeded, array $row): string {
    $link = $seeded[$row['menu']][(string) $row['parent']] ?? NULL;
    if ($link) {
      return 'menu_link_content:' . $link->uuid();
    }
    throw new SeedException(sprintf('menu: link "%s" names parent "%s", which no earlier row in menu "%s" seeds.', $row['uri'], $row['parent'], $row['menu']));
  }

}
