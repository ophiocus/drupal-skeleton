<?php

declare(strict_types=1);

namespace Drupal\site_seed\Handler;

use Drupal\site_seed\SeedContext;

/**
 * Converges one section of a seed file onto the database.
 *
 * Tag an implementation `site_seed.handler`. A handler must be idempotent: a
 * second run on an unchanged seed changes nothing and counts `unchanged` or
 * nothing at all.
 */
interface SeedHandlerInterface {

  /**
   * The top-level key of the seed file this handler reads.
   */
  public function section(): string;

  /**
   * Order among handlers: lower runs first (stores before products, and so on).
   */
  public function weight(): int;

  /**
   * Whether this site can take the section (its entity type exists).
   */
  public function applies(): bool;

  /**
   * Converges the rows of the section.
   *
   * @param array<int|string, mixed> $rows
   *   The section, as the seed file has it.
   * @param \Drupal\site_seed\SeedContext $context
   *   The run: the seed's directory, what other handlers found, the tally.
   *
   * @throws \Drupal\site_seed\SeedException
   *   When the seed cannot be converged.
   */
  public function converge(array $rows, SeedContext $context): void;

}
