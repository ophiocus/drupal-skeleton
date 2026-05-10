<?php

declare(strict_types=1);

namespace Drupal\Tests\example_module\Unit;

use Drupal\example_module\Service\Greeter;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Smoke test for the skeleton's example service.
 *
 * Pattern: pure unit tests extend PHPUnit's TestCase (NOT Drupal's
 * UnitTestCase), accept a null logger, and assert against the
 * service's outputs. No Drupal kernel needed — the test runs in
 * milliseconds and survives any service-container churn.
 *
 * @coversDefaultClass \Drupal\example_module\Service\Greeter
 */
final class GreeterTest extends TestCase {

  public function testGreet(): void {
    $greeter = new Greeter(new NullLogger());
    $this->assertSame('Hello, world.', $greeter->greet('world'));
  }

  public function testGreetWithDifferentName(): void {
    $greeter = new Greeter(new NullLogger());
    $this->assertSame('Hello, Carlos.', $greeter->greet('Carlos'));
  }

}
