<?php

declare(strict_types=1);

namespace Drupal\ga4_report\Google;

/**
 * Supplies an OAuth access token for one Google API.
 */
interface AccessTokenProviderInterface {

  /**
   * Whether a credentials file is named for this site.
   */
  public function isConfigured(): bool;

  /**
   * A short-lived access token with the provider's one scope.
   *
   * @throws \Drupal\ga4_report\ReportException
   *   When no credentials are named, or Google refuses them.
   */
  public function token(): string;

}
