<?php

declare(strict_types=1);

namespace Drupal\ga4_report\Google;

use Drupal\Core\Site\Settings;
use Drupal\ga4_report\ReportException;
use Google\Auth\Credentials\ServiceAccountCredentials;
use Google\Auth\Credentials\UserRefreshCredentials;
use Google\Auth\HttpHandler\HttpHandlerFactory;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;

/**
 * Access tokens from a Google credentials file named by reference.
 *
 * The file is never in config or the repository: settings.php names its path
 * under one key per source ($settings['ga4_report_credentials'] for
 * Analytics, $settings['ga4_report_adsense_credentials'] for AdSense; in
 * production from environment variables). Either a service account key or an
 * authorized-user file (a refresh token and the OAuth client that minted it)
 * works; nothing else is accepted.
 */
final class GoogleAccessTokenProvider implements AccessTokenProviderInterface {

  /**
   * Read-only access to Analytics reports.
   */
  public const ANALYTICS = 'https://www.googleapis.com/auth/analytics.readonly';

  /**
   * Read-only access to AdSense reports.
   */
  public const ADSENSE = 'https://www.googleapis.com/auth/adsense.readonly';

  /**
   * Builds a provider for one source.
   *
   * @param \GuzzleHttp\ClientInterface $httpClient
   *   Drupal's HTTP client.
   * @param \Drupal\Core\Site\Settings $settings
   *   The site's settings.
   * @param string $settingKey
   *   The $settings key naming the credentials file.
   * @param string $scope
   *   The one OAuth scope asked for.
   * @param string $envFallback
   *   An environment variable read when the setting is empty, or ''.
   */
  public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly Settings $settings,
    private readonly string $settingKey,
    private readonly string $scope,
    private readonly string $envFallback = '',
  ) {}

  /**
   * {@inheritdoc}
   */
  public function isConfigured(): bool {
    return $this->path() !== '';
  }

  /**
   * {@inheritdoc}
   */
  public function token(): string {
    $path = $this->path();
    if ($path === '') {
      throw new ReportException(sprintf('No Google credentials are named: set $settings["%s"] to the path of a credentials file.', $this->settingKey));
    }
    if (!is_readable($path)) {
      throw new ReportException(sprintf('The Google credentials file %s cannot be read.', $path));
    }
    $json = json_decode((string) file_get_contents($path), TRUE);
    if (!is_array($json) || empty($json['type'])) {
      throw new ReportException(sprintf('The Google credentials file %s is not a credentials JSON file.', $path));
    }
    // Only the two kinds this module documents. Google's generic loader also
    // takes external-account files, which can make the server fetch from
    // URLs written in the file; nothing here needs that.
    if (!in_array($json['type'], ['service_account', 'authorized_user'], TRUE)) {
      throw new ReportException(sprintf('The Google credentials file %s holds "%s" credentials; use a service account key or an authorized-user file.', $path, $json['type']));
    }
    try {
      $credentials = $json['type'] === 'service_account'
        ? new ServiceAccountCredentials([$this->scope], $json)
        : new UserRefreshCredentials([$this->scope], $json);
      $token = $credentials->fetchAuthToken(HttpHandlerFactory::build($this->httpClient));
    }
    catch (\Throwable $e) {
      throw new ReportException('Google refused the credentials: ' . self::reason($e), 0, $e);
    }
    if (empty($token['access_token'])) {
      throw new ReportException('Google returned no access token for the credentials.');
    }
    return (string) $token['access_token'];
  }

  /**
   * The credentials path, or '' when none is named.
   */
  public function path(): string {
    $path = (string) $this->settings->get($this->settingKey, '');
    if ($path === '' && $this->envFallback !== '') {
      $path = (string) getenv($this->envFallback);
    }
    return $path;
  }

  /**
   * Google's own short reason, without the request that carried a secret.
   */
  private static function reason(\Throwable $e): string {
    if ($e instanceof RequestException && $e->getResponse()) {
      $body = json_decode((string) $e->getResponse()->getBody(), TRUE);
      $reason = $body['error_description'] ?? $body['error'] ?? NULL;
      if (is_string($reason)) {
        return $reason;
      }
      return 'HTTP ' . $e->getResponse()->getStatusCode();
    }
    return get_class($e);
  }

}
