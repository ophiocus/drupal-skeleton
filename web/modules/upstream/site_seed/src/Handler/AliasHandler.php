<?php

declare(strict_types=1);

namespace Drupal\site_seed\Handler;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\site_seed\SeedContext;

/**
 * Section `aliases:`, path aliases matched by system path.
 *
 * Each row: path (/node/1, /agendar…), alias, optional langcode (und).
 */
final class AliasHandler implements SeedHandlerInterface {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function section(): string {
    return 'aliases';
  }

  /**
   * {@inheritdoc}
   */
  public function weight(): int {
    return 50;
  }

  /**
   * {@inheritdoc}
   */
  public function applies(): bool {
    return $this->entityTypeManager->hasDefinition('path_alias');
  }

  /**
   * {@inheritdoc}
   */
  public function converge(array $rows, SeedContext $context): void {
    $storage = $this->entityTypeManager->getStorage('path_alias');
    foreach ($rows as $row) {
      $hit = $storage->loadByProperties(['path' => $row['path']]);
      $alias = $hit ? reset($hit) : NULL;
      if (!$alias instanceof ContentEntityInterface) {
        $context->save($storage->create([
          'path' => $row['path'],
          'alias' => $row['alias'],
          'langcode' => $row['langcode'] ?? 'und',
        ]));
        $context->count('aliases');
      }
      elseif ((string) $alias->get('alias')->value !== $row['alias']) {
        $alias->set('alias', $row['alias']);
        $context->save($alias);
        $context->count('updated');
      }
    }
  }

}
