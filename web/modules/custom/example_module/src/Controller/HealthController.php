<?php

declare(strict_types=1);

namespace Drupal\example_module\Controller;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Skeleton health endpoint.
 *
 * Convention: every custom module exposes /<module-prefix>/health
 * returning a small JSON envelope. This is what smoke tests, deploy
 * gates, and "did the bundle load?" checks hit. Keep it cheap —
 * never query the DB or call out to a service.
 *
 * Returns CacheableJsonResponse with no_cache so the result is
 * always fresh; metadata still carries through any cache contexts
 * the caller is aware of.
 *
 * Dependencies are injected via create() — never reach for the
 * \Drupal static in a class (DrupalPractice.Objects.GlobalDrupal).
 */
final class HealthController extends ControllerBase {

  public function __construct(
    private readonly TimeInterface $time,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('datetime.time'),
    );
  }

  /**
   * Returns a tiny JSON envelope confirming the module is loaded.
   */
  public function check(): CacheableJsonResponse {
    $payload = [
      'module' => 'example_module',
      'status' => 'ok',
      'time' => $this->time->getCurrentTime(),
    ];
    $response = new CacheableJsonResponse($payload);
    $response->addCacheableDependency((new CacheableMetadata())->setCacheMaxAge(0));
    return $response;
  }

}
