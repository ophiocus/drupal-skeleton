<?php

declare(strict_types=1);

namespace Drupal\ad_inventory\Controller;

use Drupal\ad_inventory\AdCounter;
use Drupal\ad_inventory\Entity\AdBooking;
use Drupal\ad_inventory\Entity\AdCreative;
use Drupal\ad_inventory\Entity\AdSlot;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Views and clicks per booking and house banner, on screen and as CSV.
 *
 * A view is a space half on screen for a second, counted only for readers
 * who granted advertising consent, so every figure is a floor.
 */
final class ReportController extends ControllerBase {

  public function __construct(
    private readonly AdCounter $counter,
    private readonly RendererInterface $renderer,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('ad_inventory.counter'), $container->get('renderer'));
  }

  /**
   * The report page.
   *
   * @return array<string, mixed>
   *   Render array.
   */
  public function page(): array {
    $rows = [];
    foreach ($this->lines() as $line) {
      $rows[] = [
        $line['label'],
        $line['advertiser'],
        $line['slots'],
        $line['days'],
        ['data' => number_format($line['views']), 'class' => ['num']],
        ['data' => number_format($line['clicks']), 'class' => ['num']],
        [
          'data' => $line['views'] > 0 ? number_format(100 * $line['clicks'] / $line['views'], 2) . ' %' : '—',
          'class' => ['num'],
        ],
      ];
    }
    return [
      '#attached' => ['library' => ['ad_inventory/admin']],
      'note' => [
        '#markup' => '<p>' . $this->t('Views count spaces at least half on screen for one second, for readers who granted advertising consent; each figure is a floor.') . '</p>',
      ],
      'csv' => [
        '#type' => 'link',
        '#title' => $this->t('Download every day as CSV'),
        '#url' => Url::fromRoute('ad_inventory.report_csv'),
      ],
      'table' => [
        '#type' => 'table',
        '#attributes' => ['class' => ['ad-inventory-report']],
        '#header' => [
          $this->t('Booking or house banner'),
          $this->t('Advertiser'),
          $this->t('Spaces'),
          $this->t('Days'),
          ['data' => $this->t('Views'), 'class' => ['num']],
          ['data' => $this->t('Clicks'), 'class' => ['num']],
          ['data' => $this->t('Click rate'), 'class' => ['num']],
        ],
        '#rows' => $rows,
        '#empty' => $this->t('No booking or house banner yet.'),
      ],
      '#cache' => ['max-age' => 0],
    ];
  }

  /**
   * Every counted day as CSV.
   */
  public function csv(): Response {
    $out = fopen('php://temp', 'w+');
    fputcsv($out, ['line', 'advertiser', 'day', 'space', 'source', 'views', 'clicks'], ',', '"', '');
    foreach ($this->lines() as $line) {
      foreach ($line['rows'] as $row) {
        $values = [$line['label'], $line['advertiser']];
        array_push($values, $row['day'], $row['slot'], $row['source'], $row['views'], $row['clicks']);
        fputcsv($out, $values, ',', '"', '');
      }
    }
    rewind($out);
    $csv = (string) stream_get_contents($out);
    fclose($out);
    return new Response($csv, 200, [
      'Content-Type' => 'text/csv; charset=utf-8',
      'Content-Disposition' => 'attachment; filename="banners-report.csv"',
      'Cache-Control' => 'no-store',
    ]);
  }

  /**
   * The snippet to paste into a mail template for an email space.
   *
   * @return array<string, mixed>
   *   Render array.
   */
  public function emailSnippet(AdSlot $ad_slot): array {
    $image = Url::fromRoute('ad_inventory.email_image', ['ad_slot' => $ad_slot->id()], ['absolute' => TRUE])->toString();
    $link = Url::fromRoute('ad_inventory.email_go', ['ad_slot' => $ad_slot->id()], ['absolute' => TRUE])->toString();
    $label = (string) ($this->config('ad_inventory.settings')->get('label') ?: $this->t('Sponsored Links'));
    $format = $this->entityTypeManager()->getStorage('ad_format')->load($ad_slot->desktopFormat());
    $snippet = [
      '#theme' => 'ad_inventory_email',
      '#label' => $label,
      '#image' => $image,
      '#link' => $link,
      '#width' => $format?->get('width') ?? 600,
      '#height' => $format?->get('height') ?? 200,
      '#alt' => $label,
    ];
    $html = (string) $this->renderer->renderInIsolation($snippet);
    return [
      'help' => [
        '#markup' => '<p>' . $this->t('Paste this into the mail template, once. The image always shows the booking in force on the day the mail is opened; clicks are counted.') . '</p>',
      ],
      'code' => [
        '#type' => 'textarea',
        '#title' => $this->t('HTML'),
        '#value' => trim($html),
        '#rows' => 8,
        '#attributes' => ['readonly' => 'readonly'],
      ],
    ];
  }

  /**
   * One line per booking and per house banner, with its counts.
   *
   * @return array<int, array{label: string, advertiser: string, slots: string, days: string, views: int, clicks: int, rows: array<int, array<string, mixed>>}>
   *   Lines.
   */
  private function lines(): array {
    $lines = [];
    $bookings = $this->entityTypeManager()->getStorage('ad_booking')->loadMultiple();
    foreach ($bookings as $booking) {
      if (!$booking instanceof AdBooking || $booking->creative() === NULL) {
        continue;
      }
      $rows = array_filter(
        $this->counter->rows((int) $booking->creative()->id(), $booking->slots(), $booking->startDay(), $booking->endDay()),
        static fn ($r) => $r['source'] === 'booking' || $r['source'] === 'email',
      );
      $lines[] = $this->line($booking->label(), (string) $booking->creative()->get('advertiser')->value, implode(', ', $booking->slots()), $booking->startDay() . ' → ' . $booking->endDay(), $rows);
    }
    $ids = $this->entityTypeManager()->getStorage('ad_creative')->getQuery()->accessCheck(FALSE)->condition('house', 1)->execute();
    foreach ($this->entityTypeManager()->getStorage('ad_creative')->loadMultiple($ids) as $creative) {
      if (!$creative instanceof AdCreative) {
        continue;
      }
      $rows = array_filter($this->counter->rows((int) $creative->id()), static fn ($r) => $r['source'] === 'house');
      $lines[] = $this->line($creative->label() . ' (' . $this->t('house') . ')', (string) $creative->get('advertiser')->value, '', '', $rows);
    }
    return $lines;
  }

  /**
   * A report line.
   *
   * @param string $label
   *   Label.
   * @param string $advertiser
   *   Advertiser.
   * @param string $slots
   *   Spaces.
   * @param string $days
   *   Days.
   * @param array<int, array<string, mixed>> $rows
   *   Count rows.
   *
   * @return array{label: string, advertiser: string, slots: string, days: string, views: int, clicks: int, rows: array<int, array<string, mixed>>}
   *   The line.
   */
  private function line(string $label, string $advertiser, string $slots, string $days, array $rows): array {
    return [
      'label' => $label,
      'advertiser' => $advertiser,
      'slots' => $slots,
      'days' => $days,
      'views' => array_sum(array_column($rows, 'views')),
      'clicks' => array_sum(array_column($rows, 'clicks')),
      'rows' => array_values($rows),
    ];
  }

}
