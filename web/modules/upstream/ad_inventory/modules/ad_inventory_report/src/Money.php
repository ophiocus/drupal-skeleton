<?php

declare(strict_types=1);

namespace Drupal\ad_inventory_report;

use Drupal\ad_inventory\Entity\AdBookingInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Money, when a site has any to show. Never required, never invented.
 *
 * Network earnings come from ga4_report's stored AdSense snapshot when that
 * module is installed and its AdSense source configured (a soft dependency:
 * the service is optional). Booked value comes from an amount field a site
 * adds to bookings and names in the settings; without one there is none.
 */
final class Money {

  /**
   * The AdSense snapshot's source id in ga4_report.
   */
  public const ADSENSE = 'adsense';

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityFieldManagerInterface $entityFieldManager,
    private readonly ?object $snapshots = NULL,
  ) {}

  /**
   * Network earnings of a period, or NULL when no source is available.
   *
   * @return array{currency: string, earnings: float, page_views: int, rpm: float|null, days: array<string, float>, first: string, last: string}|null
   *   Earnings per day and in total; page RPM is earnings per 1,000 page
   *   views as AdSense counts them. first/last are the snapshot's own days.
   */
  public function network(string $from, string $to): ?array {
    if ($this->snapshots === NULL || !method_exists($this->snapshots, 'snapshot')) {
      return NULL;
    }
    $snapshot = $this->snapshots->snapshot(self::ADSENSE);
    if (!is_array($snapshot) || !isset($snapshot['days']) || !is_array($snapshot['days'])) {
      return NULL;
    }
    $days = [];
    $earnings = 0.0;
    $views = 0;
    $all = array_column($snapshot['days'], 0);
    foreach ($snapshot['days'] as $row) {
      $day = (string) ($row[0] ?? '');
      if ($day >= $from && $day <= $to) {
        $days[$day] = (float) ($row[1] ?? 0);
        $earnings += (float) ($row[1] ?? 0);
        $views += (int) ($row[2] ?? 0);
      }
    }
    return [
      'currency' => (string) ($snapshot['currency'] ?? ''),
      'earnings' => round($earnings, 2),
      'page_views' => $views,
      'rpm' => $views > 0 ? round($earnings / $views * 1000, 2) : NULL,
      'days' => $days,
      'first' => $all ? (string) min($all) : '',
      'last' => $all ? (string) max($all) : '',
    ];
  }

  /**
   * The amount field named in the settings, if bookings carry it.
   */
  public function amountField(): ?string {
    $field = (string) $this->configFactory->get('ad_inventory_report.settings')->get('amount_field');
    if ($field === '') {
      return NULL;
    }
    $definitions = $this->entityFieldManager->getFieldStorageDefinitions('ad_booking');
    return isset($definitions[$field]) ? $field : NULL;
  }

  /**
   * Booked value of the bookings whose first day falls in a period.
   *
   * @return array{field: string, total: float, bookings: array<int, float>}|null
   *   NULL when no amount field is configured.
   */
  public function booked(string $from, string $to): ?array {
    $field = $this->amountField();
    if ($field === NULL) {
      return NULL;
    }
    $storage = $this->entityTypeManager->getStorage('ad_booking');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('state', ['approved', 'stopped'], 'IN')
      ->condition('start_day', [$from, $to], 'BETWEEN')
      ->execute();
    $values = [];
    foreach ($storage->loadMultiple($ids) as $booking) {
      if ($booking instanceof AdBookingInterface && $booking->hasField($field) && ($item = $booking->get($field)->first())) {
        // A number field keeps "value", a price field "number".
        $property = $item->getFieldDefinition()->getFieldStorageDefinition()->getMainPropertyName() ?? 'value';
        $values[(int) $booking->id()] = (float) $item->get($property)->getValue();
      }
    }
    return ['field' => $field, 'total' => array_sum($values), 'bookings' => $values];
  }

}
