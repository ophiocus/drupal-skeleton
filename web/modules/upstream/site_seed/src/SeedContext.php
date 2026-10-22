<?php

declare(strict_types=1);

namespace Drupal\site_seed;

use Drupal\Core\Entity\ContentEntityInterface;

/**
 * One run of the seeder: the seed file, what handlers share, and the tally.
 */
final class SeedContext {

  /**
   * What changed, by kind, in the order the seeders have always printed it.
   *
   * @var array<string, int>
   */
  public array $tally = [
    'stores' => 0,
    'terms' => 0,
    'products' => 0,
    'variations' => 0,
    'menu_links' => 0,
    'updated' => 0,
    'unchanged' => 0,
  ];

  /**
   * Stores by name, filled by the stores handler, read by products.
   *
   * @var array<string, \Drupal\Core\Entity\ContentEntityInterface>|null
   */
  public ?array $stores = NULL;

  /**
   * Terms by vocabulary, then by name.
   *
   * @var array<string, array<string, \Drupal\Core\Entity\ContentEntityInterface>>
   */
  public array $terms = [];

  /**
   * Starts a run.
   *
   * @param string $dir
   *   The directory of the seed file; images/ and the like are relative to it.
   * @param array<string, mixed> $options
   *   The seed file's `site_seed:` section: field names and the like.
   */
  public function __construct(
    public readonly string $dir,
    public readonly array $options = [],
  ) {}

  /**
   * Counts one change (or several) of a kind.
   */
  public function count(string $kind, int $n = 1): void {
    $this->tally[$kind] = ($this->tally[$kind] ?? 0) + $n;
  }

  /**
   * An option from the seed file's `site_seed:` section, or its default.
   */
  public function option(string $name, mixed $default): mixed {
    return $this->options[$name] ?? $default;
  }

  /**
   * Finds the entity a row describes, by its name or a name it used to have.
   *
   * Stores and terms carry no other stable key in the seed, so a rename would
   * otherwise create a second entity beside the first. A row lists its old
   * names under `formerly:` and the entity is renamed in place; the current
   * name wins when both exist.
   *
   * @param array<string, \Drupal\Core\Entity\ContentEntityInterface> $by_name
   *   The existing entities, keyed by name.
   * @param array<string, mixed> $row
   *   The seed row.
   */
  public static function match(array $by_name, array $row): ?ContentEntityInterface {
    foreach ([$row['name'], ...($row['formerly'] ?? [])] as $name) {
      if (isset($by_name[$name])) {
        return $by_name[$name];
      }
    }
    return NULL;
  }

}
