<?php

declare(strict_types=1);

namespace Drupal\site_seed\Handler;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\site_seed\SeedContext;

/**
 * Section `taxonomy:`, terms by vocabulary matched by name (or a former one).
 *
 * The section is keyed by vocabulary id; each row has `name`, an optional
 * `weight` and an optional `formerly:` list.
 */
final class TaxonomyHandler implements SeedHandlerInterface {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function section(): string {
    return 'taxonomy';
  }

  /**
   * {@inheritdoc}
   */
  public function weight(): int {
    return 10;
  }

  /**
   * {@inheritdoc}
   */
  public function applies(): bool {
    return $this->entityTypeManager->hasDefinition('taxonomy_term');
  }

  /**
   * {@inheritdoc}
   */
  public function converge(array $rows, SeedContext $context): void {
    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    foreach ($rows as $vid => $terms) {
      $by_name = [];
      foreach ($storage->loadByProperties(['vid' => $vid]) as $term) {
        if ($term instanceof ContentEntityInterface) {
          $by_name[(string) $term->label()] = $term;
        }
      }
      foreach ((array) $terms as $row) {
        $weight = (int) ($row['weight'] ?? 0);
        $term = SeedContext::match($by_name, $row);
        if (!$term) {
          $term = $storage->create(['vid' => $vid, 'name' => $row['name'], 'weight' => $weight]);
          assert($term instanceof ContentEntityInterface);
          $context->save($term);
          $by_name[$row['name']] = $term;
          $context->count('terms');
        }
        elseif ((string) $term->label() !== $row['name'] || (int) $term->get('weight')->value !== $weight) {
          unset($by_name[(string) $term->label()]);
          $term->set('name', $row['name'])->set('weight', $weight);
          $context->save($term);
          $by_name[$row['name']] = $term;
          $context->count('updated');
        }
      }
      $context->terms[(string) $vid] = $by_name;
    }
  }

}
