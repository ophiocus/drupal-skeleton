<?php

declare(strict_types=1);

namespace Drupal\Tests\ad_inventory\Kernel;

use Drupal\Component\Datetime\TimeInterface;

/**
 * A clock the test sets.
 */
final class FrozenTime implements TimeInterface {

  /**
   * The frozen time.
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
