<?php

declare(strict_types=1);

namespace Drupal\Tests\ga4_report;

use Drupal\ga4_report\Google\AccessTokenProviderInterface;

/**
 * Credentials that are always there, for tests that answer Google themselves.
 */
final class StubTokens implements AccessTokenProviderInterface {

  /**
   * {@inheritdoc}
   */
  public function isConfigured(): bool {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function token(): string {
    return 'test-token';
  }

}
