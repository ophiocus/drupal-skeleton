<?php

declare(strict_types=1);

namespace Drupal\site_seed\Handler;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\site_seed\SeedContext;

/**
 * Section `menu:`, menu links matched by menu and link URI.
 *
 * Each row: menu, uri, title, weight.
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
    foreach ($rows as $row) {
      $weight = (int) ($row['weight'] ?? 0);
      $hit = $storage->loadByProperties(['menu_name' => $row['menu'], 'link__uri' => $row['uri']]);
      $link = $hit ? reset($hit) : NULL;
      if (!$link instanceof ContentEntityInterface) {
        $storage->create([
          'title' => $row['title'],
          'link' => ['uri' => $row['uri']],
          'menu_name' => $row['menu'],
          'weight' => $weight,
          'expanded' => FALSE,
        ])->save();
        $context->count('menu_links');
      }
      elseif ((string) $link->label() !== $row['title'] || (int) $link->get('weight')->value !== $weight) {
        $link->set('title', $row['title'])->set('weight', $weight)->save();
        $context->count('updated');
      }
    }
  }

}
