<?php

declare(strict_types=1);

namespace Drupal\ga4_report\Google;

use Drupal\ga4_report\ReportException;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;

/**
 * The GA4 Data API, over REST, for exactly one call: batchRunReports.
 *
 * Plain HTTPS through Drupal's own Guzzle client rather than the
 * google/analytics-data library: one endpoint does not need gRPC, protobuf
 * and a generated client surface that has already changed shape once.
 */
final class Ga4Client {

  /**
   * The Data API's batch endpoint; %s is the numeric property ID.
   */
  public const ENDPOINT = 'https://analyticsdata.googleapis.com/v1beta/properties/%s:batchRunReports';

  /**
   * The API takes at most this many reports per batch.
   */
  public const BATCH = 5;

  public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly AccessTokenProviderInterface $tokens,
  ) {}

  /**
   * Runs every request and returns the reports under the same keys.
   *
   * @param string $propertyId
   *   The numeric GA4 property ID.
   * @param array<string, array> $requests
   *   runReport request bodies keyed by report id.
   *
   * @return array<string, array>
   *   The report responses keyed like the requests.
   *
   * @throws \Drupal\ga4_report\ReportException
   *   When Google cannot be reached, refuses, or answers short.
   */
  public function run(string $propertyId, array $requests): array {
    if (!preg_match('/^\d+$/', $propertyId)) {
      throw new ReportException('The GA4 property ID must be its number (Admin → Property details), not a measurement ID.');
    }
    $token = $this->tokens->token();
    $reports = [];
    foreach (array_chunk($requests, self::BATCH, TRUE) as $chunk) {
      $answer = $this->post(sprintf(self::ENDPOINT, $propertyId), $token, ['requests' => array_values($chunk)]);
      $got = $answer['reports'] ?? [];
      if (count($got) !== count($chunk)) {
        throw new ReportException(sprintf('Google answered %d reports for a batch of %d.', count($got), count($chunk)));
      }
      $reports += array_combine(array_keys($chunk), $got);
    }
    return $reports;
  }

  /**
   * One authenticated POST, decoded.
   */
  private function post(string $url, string $token, array $body): array {
    try {
      $response = $this->httpClient->request('POST', $url, [
        'headers' => ['Authorization' => 'Bearer ' . $token],
        'json' => $body,
        'timeout' => 60,
      ]);
    }
    catch (RequestException $e) {
      $error = $e->getResponse() ? json_decode((string) $e->getResponse()->getBody(), TRUE) : NULL;
      $message = $error['error']['message'] ?? $e->getMessage();
      $status = $e->getResponse() ? $e->getResponse()->getStatusCode() : 0;
      throw new ReportException(sprintf('Google Analytics answered %d: %s', $status, $message), 0, $e);
    }
    catch (GuzzleException $e) {
      throw new ReportException('Google Analytics could not be reached: ' . $e->getMessage(), 0, $e);
    }
    $data = json_decode((string) $response->getBody(), TRUE);
    if (!is_array($data)) {
      throw new ReportException('Google Analytics answered something that is not JSON.');
    }
    return $data;
  }

}
