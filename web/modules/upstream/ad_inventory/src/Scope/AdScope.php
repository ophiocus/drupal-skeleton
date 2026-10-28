<?php

declare(strict_types=1);

namespace Drupal\ad_inventory\Scope;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityInterface;

/**
 * What a page is about: its own entity, and the scope keys bookings target.
 *
 * Built only from the URL (route and its parameters), never from the reader,
 * so every reader of a page gets the same scope.
 */
final class AdScope {

  /**
   * Builds a page's scope.
   *
   * @param \Drupal\Core\Entity\EntityInterface|null $page
   *   The page's own entity (a node, a term), if it has one.
   * @param string[] $keys
   *   Scope keys, for example "city:12" or "genre:405".
   * @param \Drupal\Core\Cache\CacheableMetadata $cacheability
   *   What the keys depend on.
   */
  public function __construct(
    public readonly ?EntityInterface $page,
    public readonly array $keys,
    public readonly CacheableMetadata $cacheability,
  ) {}

  /**
   * Whether a booking's scope applies here ('' applies everywhere).
   */
  public function matches(string $key): bool {
    return $key === '' || in_array($key, $this->keys, TRUE);
  }

}
