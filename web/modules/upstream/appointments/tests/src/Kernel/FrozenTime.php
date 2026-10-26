<?php

declare(strict_types=1);

namespace Drupal\Tests\appointments\Kernel;

use Drupal\Component\Datetime\TimeInterface;

/**
 * A clock the tests set by hand.
 */
final class FrozenTime implements TimeInterface {

  /**
   * The current time, as a Unix timestamp.
   */
  public static int $now = 0;

  /**
   * {@inheritdoc}
   */
  public function getRequestTime(): int {
    return self::$now;
  }

  /**
   * {@inheritdoc}
   */
  public function getRequestMicroTime(): float {
    return (float) self::$now;
  }

  /**
   * {@inheritdoc}
   */
  public function getCurrentTime(): int {
    return self::$now;
  }

  /**
   * {@inheritdoc}
   */
  public function getCurrentMicroTime(): float {
    return (float) self::$now;
  }

}
