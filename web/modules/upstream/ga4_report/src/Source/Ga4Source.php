<?php

declare(strict_types=1);

namespace Drupal\ga4_report\Source;

use Drupal\ga4_report\Google\AccessTokenProviderInterface;
use Drupal\ga4_report\Google\Ga4Client;
use Drupal\ga4_report\Report\Normalizer;
use Drupal\ga4_report\Report\ReportDefinitions;

/**
 * Visits from a Google Analytics 4 property (Data API, batchRunReports).
 */
final class Ga4Source implements SourceInterface {

  public function __construct(
    private readonly Ga4Client $client,
    private readonly AccessTokenProviderInterface $tokens,
    private readonly Normalizer $normalizer,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function id(): string {
    return 'ga4';
  }

  /**
   * {@inheritdoc}
   */
  public function label(): string {
    return 'Google Analytics';
  }

  /**
   * {@inheritdoc}
   */
  public function isConfigured(array $settings): bool {
    return trim((string) ($settings['property_id'] ?? '')) !== '' && $this->tokens->isConfigured();
  }

  /**
   * {@inheritdoc}
   */
  public function fetch(array $settings): array {
    $property = trim((string) $settings['property_id']);
    $reports = $this->client->run($property, ReportDefinitions::build($settings));
    $snapshot = $this->normalizer->normalize($reports, $settings);
    return [
      'snapshot' => $snapshot,
      'meta' => [
        'property' => $property,
        'start_date' => $settings['start_date'],
        'end_date' => $settings['end_date'],
        'reports' => count($reports),
      ],
      'summary' => sprintf('%d reports from property %s: %d visits.', count($reports), $property, $snapshot['totals']['sessions']),
    ];
  }

}
