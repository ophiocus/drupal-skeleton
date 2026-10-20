<?php

declare(strict_types=1);

namespace Drupal\ga4_report\Source;

/**
 * One place the report reads from: Google Analytics, Cloudflare, AdSense.
 *
 * A source is fetched as a whole and stored as a whole: its snapshot is
 * replaced only when every request it makes has answered.
 */
interface SourceInterface {

  /**
   * The machine name the snapshot is stored under.
   */
  public function id(): string;

  /**
   * The name a person reads.
   */
  public function label(): string;

  /**
   * Whether the site named everything this source needs.
   *
   * @param array $settings
   *   The ga4_report.settings values.
   */
  public function isConfigured(array $settings): bool;

  /**
   * Fetches the source's data.
   *
   * @param array $settings
   *   The ga4_report.settings values.
   *
   * @return array{snapshot: array, meta: array, summary: string}
   *   The data to store, its provenance, and one line for a person.
   *
   * @throws \Drupal\ga4_report\ReportException
   *   When any request failed.
   */
  public function fetch(array $settings): array;

}
