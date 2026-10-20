<?php

declare(strict_types=1);

namespace Drupal\ga4_report\Cloudflare;

use Drupal\Core\Site\Settings;
use Drupal\ga4_report\ReportException;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;

/**
 * Cloudflare's GraphQL Analytics API, with a token named by reference.
 *
 * The site's settings.php names the path of a file holding one API token in
 * $settings['ga4_report_cloudflare_token'] (production: from the environment).
 * The token needs one permission, Zone · Analytics · Read, on the zones the
 * site reports; it is read from the file on each call and never stored.
 */
final class CloudflareClient {

  /**
   * The GraphQL endpoint.
   */
  public const ENDPOINT = 'https://api.cloudflare.com/client/v4/graphql';

  public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly Settings $settings,
  ) {}

  /**
   * Whether a token file is named.
   */
  public function isConfigured(): bool {
    return $this->path() !== '';
  }

  /**
   * The token file's path, or ''.
   */
  public function path(): string {
    return (string) $this->settings->get('ga4_report_cloudflare_token', '');
  }

  /**
   * Runs one GraphQL query and returns its "data".
   *
   * @throws \Drupal\ga4_report\ReportException
   *   When the token is missing, Cloudflare refuses, or the query errs.
   */
  public function query(string $query): array {
    $token = $this->token();
    try {
      $response = $this->httpClient->request('POST', self::ENDPOINT, [
        'headers' => ['Authorization' => 'Bearer ' . $token],
        'json' => ['query' => $query],
        'timeout' => 60,
      ]);
    }
    catch (RequestException $e) {
      $body = $e->getResponse() ? json_decode((string) $e->getResponse()->getBody(), TRUE) : NULL;
      $status = $e->getResponse() ? $e->getResponse()->getStatusCode() : 0;
      throw new ReportException(sprintf('Cloudflare answered %d: %s', $status, self::errors($body) ?: $e->getMessage()), 0, $e);
    }
    catch (GuzzleException $e) {
      throw new ReportException('Cloudflare could not be reached: ' . $e->getMessage(), 0, $e);
    }
    $body = json_decode((string) $response->getBody(), TRUE);
    if (!is_array($body)) {
      throw new ReportException('Cloudflare answered something that is not JSON.');
    }
    // GraphQL reports a refused query with HTTP 200 and an "errors" list.
    if ($errors = self::errors($body)) {
      throw new ReportException('Cloudflare refused the query: ' . $errors);
    }
    return (array) ($body['data'] ?? []);
  }

  /**
   * The token, read from its file.
   */
  private function token(): string {
    $path = $this->path();
    if ($path === '') {
      throw new ReportException('No Cloudflare token is named: set $settings["ga4_report_cloudflare_token"] to the path of a file holding it.');
    }
    if (!is_readable($path)) {
      throw new ReportException(sprintf('The Cloudflare token file %s cannot be read.', $path));
    }
    $token = trim((string) file_get_contents($path));
    if ($token === '' || preg_match('/\s/', $token)) {
      throw new ReportException(sprintf('The Cloudflare token file %s must hold the token alone, on one line.', $path));
    }
    return $token;
  }

  /**
   * The messages of a GraphQL or v4 error list, joined.
   */
  private static function errors(?array $body): string {
    $messages = array_filter(array_map(
      static fn($e): string => is_array($e) ? (string) ($e['message'] ?? '') : '',
      (array) ($body['errors'] ?? []),
    ));
    return implode('; ', $messages);
  }

}
