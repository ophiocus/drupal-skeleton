<?php

declare(strict_types=1);

namespace Drupal\ad_inventory\Scope;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Routing\RouteMatchInterface;

/**
 * Asks every tagged scope resolver what the current page is about.
 */
final class ScopeCollector {

  /**
   * The resolvers sites add.
   *
   * @var \Drupal\ad_inventory\Scope\ScopeResolverInterface[]
   */
  private array $resolvers = [];

  public function __construct(
    private readonly RouteMatchInterface $routeMatch,
  ) {}

  /**
   * Adds a resolver (service collector).
   */
  public function addResolver(ScopeResolverInterface $resolver): void {
    $this->resolvers[] = $resolver;
  }

  /**
   * The current page's scope.
   */
  public function current(): AdScope {
    return $this->forRoute($this->routeMatch);
  }

  /**
   * A route's scope.
   */
  public function forRoute(RouteMatchInterface $route_match): AdScope {
    $cacheability = (new CacheableMetadata())->addCacheContexts(['route']);
    $keys = [];
    foreach ($this->resolvers as $resolver) {
      foreach ($resolver->keys($route_match, $cacheability) as $key) {
        $keys[$key] = $key;
      }
    }
    return new AdScope(self::page($route_match), array_values($keys), $cacheability);
  }

  /**
   * Bookable keys from every resolver.
   *
   * @return array<string, string>
   *   Key → label.
   */
  public function options(): array {
    $options = [];
    foreach ($this->resolvers as $resolver) {
      $options += $resolver->options();
    }
    return $options;
  }

  /**
   * The page's own entity: the first content entity among route parameters.
   */
  public static function page(RouteMatchInterface $route_match): ?ContentEntityInterface {
    foreach ($route_match->getParameters()->all() as $parameter) {
      if ($parameter instanceof ContentEntityInterface) {
        return $parameter;
      }
    }
    return NULL;
  }

}
