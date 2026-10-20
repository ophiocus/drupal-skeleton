<?php

declare(strict_types=1);

namespace Drupal\ga4_report\AdSense;

use Drupal\ga4_report\Google\AccessTokenProviderInterface;
use Drupal\ga4_report\ReportException;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Query;

/**
 * The AdSense Management API v2, for one call: accounts.reports.generate.
 */
final class AdSenseClient {

  /**
   * The report endpoint; %s is "accounts/pub-…".
   */
  public const ENDPOINT = 'https://adsense.googleapis.com/v2/%s/reports:generate';

  public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly AccessTokenProviderInterface $tokens,
  ) {}

  /**
   * Whether credentials are named.
   */
  public function isConfigured(): bool {
    return $this->tokens->isConfigured();
  }

  /**
   * Generates one report.
   *
   * @param string $account
   *   The account resource name, "accounts/pub-…".
   * @param array<string, string|list<string>> $params
   *   Query parameters; a list repeats its key (dimensions, metrics).
   *
   * @throws \Drupal\ga4_report\ReportException
   *   When AdSense cannot be reached or refuses.
   */
  public function generate(string $account, array $params): array {
    $token = $this->tokens->token();
    $url = sprintf(self::ENDPOINT, $account) . '?' . Query::build($params);
    try {
      $response = $this->httpClient->request('GET', $url, [
        'headers' => ['Authorization' => 'Bearer ' . $token],
        'timeout' => 60,
      ]);
    }
    catch (RequestException $e) {
      $error = $e->getResponse() ? json_decode((string) $e->getResponse()->getBody(), TRUE) : NULL;
      $status = $e->getResponse() ? $e->getResponse()->getStatusCode() : 0;
      throw new ReportException(sprintf('AdSense answered %d: %s', $status, $error['error']['message'] ?? $e->getMessage()), 0, $e);
    }
    catch (GuzzleException $e) {
      throw new ReportException('AdSense could not be reached: ' . $e->getMessage(), 0, $e);
    }
    $data = json_decode((string) $response->getBody(), TRUE);
    if (!is_array($data)) {
      throw new ReportException('AdSense answered something that is not JSON.');
    }
    return $data;
  }

}
