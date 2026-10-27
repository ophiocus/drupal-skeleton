<?php

declare(strict_types=1);

namespace Drupal\ad_inventory\Controller;

use Drupal\ad_inventory\Calendar;
use Drupal\ad_inventory\Csv;
use Drupal\ad_inventory\Entity\AdBookingInterface;
use Drupal\ad_inventory\Inventory;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * A booking's views and clicks by day, on a page and as CSV.
 *
 * Counts are kept per day, slot and creative, so a booking's report is the
 * rows of its creative in its slots between its first and last day. Views
 * are counted only for readers who granted advertising consent: a floor.
 */
final class BookingReportController extends ControllerBase {

  public function __construct(
    private readonly Connection $database,
    private readonly Inventory $inventory,
    private readonly Calendar $calendar,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('database'),
      $container->get('ad_inventory.inventory'),
      $container->get('ad_inventory.calendar'),
    );
  }

  /**
   * Who may read a booking's report.
   *
   * "view ad booking reports", or any module's
   * hook_ad_inventory_report_access() allowing it (to show an advertiser
   * their own bookings, for example).
   */
  public function access(AdBookingInterface $ad_booking, AccountInterface $account): AccessResultInterface {
    $result = AccessResult::allowedIfHasPermission($account, 'view ad booking reports');
    foreach ($this->moduleHandler()->invokeAll('ad_inventory_report_access', [$ad_booking, $account]) as $answer) {
      if ($answer instanceof AccessResultInterface) {
        $result = $result->orIf($answer);
      }
    }
    return $result->addCacheableDependency($ad_booking);
  }

  /**
   * The daily rows of a booking.
   *
   * @return array<int, array{day: string, views: int, clicks: int}>
   *   Rows by day.
   */
  public function rows(AdBookingInterface $booking): array {
    $creative = $booking->creative();
    $slots = $this->inventory->expandSlots($booking->slotValues());
    if ($creative === NULL || !$slots || $booking->start() === '') {
      return [];
    }
    $last = min($booking->end(), $this->calendar->today());
    $query = $this->database->select('ad_inventory_count', 'c')
      ->condition('creative', (string) $creative->id())
      ->condition('slot', $slots, 'IN')
      ->condition('day', [$booking->start(), $last], 'BETWEEN')
      ->groupBy('day')
      ->orderBy('day');
    $query->addField('c', 'day');
    $query->addExpression('SUM([views])', 'views');
    $query->addExpression('SUM([clicks])', 'clicks');
    $rows = [];
    foreach ($query->execute() as $row) {
      $rows[] = ['day' => (string) $row->day, 'views' => (int) $row->views, 'clicks' => (int) $row->clicks];
    }
    return $rows;
  }

  /**
   * The report page.
   */
  public function page(AdBookingInterface $ad_booking): array {
    $rows = $this->rows($ad_booking);
    $table = [];
    $views = 0;
    $clicks = 0;
    foreach ($rows as $row) {
      $views += $row['views'];
      $clicks += $row['clicks'];
      $table[] = [$row['day'], $row['views'], $row['clicks'], self::ctr($row['views'], $row['clicks'])];
    }
    return [
      'summary' => [
        '#markup' => '<p>' . $this->t('@start to @end. Viewed: @views. Clicks: @clicks. CTR: @ctr.', [
          '@start' => $ad_booking->start(),
          '@end' => $ad_booking->end(),
          '@views' => $views,
          '@clicks' => $clicks,
          '@ctr' => self::ctr($views, $clicks),
        ]) . '</p><p>' . $this->t('A view is a slot at least half on screen for one second, counted only for readers who allowed advertising: the figures are a floor.') . '</p>',
      ],
      'table' => [
        '#type' => 'table',
        '#header' => [$this->t('Day'), $this->t('Viewed'), $this->t('Clicks'), $this->t('CTR')],
        '#rows' => $table,
        '#empty' => $this->t('No views or clicks counted yet.'),
      ],
      'csv' => [
        '#type' => 'link',
        '#title' => $this->t('Download CSV'),
        '#url' => Url::fromRoute('ad_inventory.booking_report_csv', ['ad_booking' => $ad_booking->id()]),
      ],
      '#cache' => ['max-age' => 0],
    ];
  }

  /**
   * The report as CSV.
   */
  public function csv(AdBookingInterface $ad_booking): Response {
    $rows = [];
    foreach ($this->rows($ad_booking) as $r) {
      $rows[] = [$r['day'], $r['views'], $r['clicks'], self::ctr($r['views'], $r['clicks'])];
    }
    $csv = Csv::build(['day', 'views', 'clicks', 'ctr'], $rows);
    return new Response($csv, 200, [
      'Content-Type' => 'text/csv; charset=utf-8',
      'Content-Disposition' => sprintf('attachment; filename="ad-booking-%d.csv"', $ad_booking->id()),
      'Cache-Control' => 'no-store',
    ]);
  }

  /**
   * Click-through rate as a percentage with two decimals.
   */
  public static function ctr(int $views, int $clicks): string {
    return $views > 0 ? number_format($clicks / $views * 100, 2) . '%' : '–';
  }

}
