<?php

declare(strict_types=1);

namespace Drupal\example_module\Service;

use Psr\Log\LoggerInterface;

/**
 * Skeleton service.
 *
 * Pattern to follow: services are framework-agnostic, taking only
 * what they need via constructor injection. They log to their
 * module's channel, never to the global logger. They don't reach
 * for the Drupal container themselves — anything they need must
 * arrive via the constructor.
 *
 * This makes them unit-testable: pass a NullLogger, drive the
 * service, assert the result. No Drupal kernel needed.
 */
final readonly class Greeter {

  public function __construct(
    private LoggerInterface $logger,
  ) {}

  /**
   * Returns a greeting and logs that it was called.
   */
  public function greet(string $name): string {
    $message = "Hello, $name.";
    $this->logger->info('Greeted {name}.', ['name' => $name]);
    return $message;
  }

}
