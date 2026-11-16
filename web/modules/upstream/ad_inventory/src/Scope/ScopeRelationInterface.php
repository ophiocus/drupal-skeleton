<?php

declare(strict_types=1);

namespace Drupal\ad_inventory\Scope;

/**
 * A site's way of saying how two of its scope keys share pages.
 *
 * A scope resolver may implement this as well. ad_inventory knows only that
 * '' (every page) holds every key and that a key is the same as itself; for
 * any other pair it asks the resolvers, and with no answer it takes the
 * careful reading: the two may share some pages.
 */
interface ScopeRelationInterface {

  /**
   * The two keys are the same pages.
   */
  public const SAME = 'same';

  /**
   * The first key's pages hold every page of the second.
   */
  public const CONTAINS = 'contains';

  /**
   * Every page of the first key is a page of the second.
   */
  public const WITHIN = 'within';

  /**
   * Some pages carry both keys; neither holds the other.
   */
  public const OVERLAPS = 'overlaps';

  /**
   * No page carries both keys.
   */
  public const DISJOINT = 'disjoint';

  /**
   * How key $a relates to key $b, or NULL when this resolver doesn't know.
   *
   * @param string $a
   *   A scope key (never '').
   * @param string $b
   *   Another scope key (never '', never the same as $a).
   *
   * @return string|null
   *   One of the class constants, or NULL.
   */
  public function relation(string $a, string $b): ?string;

}
