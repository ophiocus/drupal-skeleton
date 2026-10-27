<?php

declare(strict_types=1);

namespace Drupal\ad_inventory_report\Controller;

use Drupal\ad_inventory\Calendar;
use Drupal\ad_inventory\Csv;
use Drupal\ad_inventory\EmailServer;
use Drupal\ad_inventory\Entity\AdBookingInterface;
use Drupal\ad_inventory\Entity\AdCreativeInterface;
use Drupal\ad_inventory\Entity\AdSlotInterface;
use Drupal\ad_inventory\Inventory;
use Drupal\ad_inventory\ListBuilder\AdProviderListBuilder;
use Drupal\ad_inventory\NetworkGate;
use Drupal\ad_inventory\Placements;
use Drupal\ad_inventory\ProviderPolicy;
use Drupal\ad_inventory\StatusChecker;
use Drupal\ad_inventory_report\DateRange;
use Drupal\ad_inventory_report\Money;
use Drupal\ad_inventory_report\ReportData;
use Drupal\ad_inventory_report\Rollup;
use Drupal\Component\Utility\Html;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\Sql\SqlEntityStorageInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The read-only ad inventory dashboard: one tab per page, every table as CSV.
 *
 * Reads only the rollups, configuration and entities, never the raw counts.
 * Adds nothing to public pages.
 */
final class DashboardController extends ControllerBase {

  /**
   * The tabs and their titles.
   */
  public const TABS = [
    'overview' => 'Overview',
    'inventory' => 'Inventory',
    'performance' => 'Performance',
    'fill' => 'Fill',
    'calendar' => 'Calendar',
    'providers' => 'Providers',
    'email' => 'Email',
    'health' => 'Health',
  ];

  public function __construct(
    private readonly ReportData $data,
    private readonly Money $money,
    private readonly Inventory $inventory,
    private readonly StatusChecker $status,
    private readonly Placements $placements,
    private readonly Calendar $calendar,
    private readonly EmailServer $email,
    private readonly NetworkGate $gate,
    private readonly Connection $database,
    private readonly EntityFieldManagerInterface $fieldManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('ad_inventory_report.data'),
      $container->get('ad_inventory_report.money'),
      $container->get('ad_inventory.inventory'),
      $container->get('ad_inventory.status'),
      $container->get('ad_inventory.placements'),
      $container->get('ad_inventory.calendar'),
      $container->get('ad_inventory.email'),
      $container->get('ad_inventory.network_gate'),
      $container->get('database'),
      $container->get('entity_field.manager'),
    );
  }

  /**
   * A tab of the dashboard.
   */
  public function page(Request $request, string $tab): array {
    if (!isset(self::TABS[$tab])) {
      throw new NotFoundHttpException();
    }
    $range = DateRange::fromQuery($request->query->all(), $this->calendar->today());
    $content = $this->content($tab, $range, $request);
    // The chart library only where its file is (the tables carry every
    // number either way).
    $libraries = is_file(DRUPAL_ROOT . '/libraries/echarts/dist/echarts.min.js') ? ['ad_inventory_report/echarts'] : [];
    $libraries[] = 'ad_inventory_report/dashboard';
    $build = [
      '#attached' => ['library' => $libraries],
      '#attributes' => ['class' => ['air']],
      'range' => $this->rangeBar($tab, $range),
      'before' => $content['before'] ?? [],
    ];
    foreach ($content['tables'] ?? [] as $name => $table) {
      $build['table_' . $name] = $this->renderTable($tab, $name, $table, $range);
    }
    $build['after'] = $content['after'] ?? [];
    $metadata = new CacheableMetadata();
    foreach (['ad_slot', 'ad_booking', 'ad_creative', 'ad_provider', 'ad_format', 'block'] as $type) {
      $metadata->addCacheTags($this->entityTypeManager()->getDefinition($type)->getListCacheTags());
    }
    $metadata->addCacheTags([Rollup::CACHE_TAG, 'config:ad_inventory.settings', 'config:ad_inventory_report.settings']);
    $metadata->addCacheContexts(['url.query_args', 'user.permissions']);
    // Today moves; the environment gates are read live.
    $metadata->setCacheMaxAge(3600);
    $metadata->applyTo($build);
    return ['#type' => 'container'] + $build;
  }

  /**
   * One table of a tab as CSV.
   */
  public function csv(Request $request, string $tab, string $table): Response {
    if (!isset(self::TABS[$tab])) {
      throw new NotFoundHttpException();
    }
    $range = DateRange::fromQuery($request->query->all(), $this->calendar->today());
    $tables = $this->content($tab, $range, $request)['tables'] ?? [];
    if (!isset($tables[$table])) {
      throw new NotFoundHttpException();
    }
    $csv = Csv::build($tables[$table]['header'], $tables[$table]['rows']);
    return new Response($csv, 200, [
      'Content-Type' => 'text/csv; charset=utf-8',
      'Content-Disposition' => sprintf('attachment; filename="ad-inventory-%s-%s-%s-%s.csv"', $tab, $table, $range->from, $range->to),
      'Cache-Control' => 'no-store',
    ]);
  }

  /**
   * What a tab shows: tables (name => table), and render arrays around them.
   */
  private function content(string $tab, DateRange $range, Request $request): array {
    return match ($tab) {
      'overview' => $this->overview($range),
      'inventory' => $this->inventory(),
      'performance' => $this->performance($range, $request),
      'fill' => $this->fill($range),
      'calendar' => $this->calendar(),
      'providers' => $this->providers(),
      'email' => $this->email($range),
      'health' => $this->health(),
    };
  }

  /**
   * The range links and the custom range form.
   */
  private function rangeBar(string $tab, DateRange $range): array {
    $labels = [
      'today' => $this->t('Today'),
      'yesterday' => $this->t('Yesterday'),
      '7d' => $this->t('7 days'),
      '28d' => $this->t('28 days'),
      'month' => $this->t('This month'),
      'last_month' => $this->t('Last month'),
    ];
    $links = [];
    foreach ($labels as $key => $label) {
      $links[$key] = [
        'title' => $label,
        'url' => Url::fromRoute('ad_inventory_report.' . $tab, [], ['query' => ['range' => $key]]),
        'attributes' => $range->key === $key ? ['aria-current' => 'true', 'class' => ['is-active']] : [],
      ];
    }
    $action = Url::fromRoute('ad_inventory_report.' . $tab)->toString(TRUE)->getGeneratedUrl();
    $form = sprintf(
      '<form class="air-range__custom" method="get" action="%s"><input type="hidden" name="range" value="custom"><label>%s <input type="date" name="from" value="%s"></label> <label>%s <input type="date" name="to" value="%s"></label> <button type="submit">%s</button></form>',
      Html::escape($action),
      $this->t('From'),
      Html::escape($range->from),
      $this->t('to'),
      Html::escape($range->to),
      $this->t('Show'),
    );
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['air-range']],
      'links' => ['#theme' => 'links', '#links' => $links, '#attributes' => ['class' => ['air-range__links']]],
      'custom' => ['#markup' => Markup::create($form)],
      'shown' => [
        '#markup' => '<p class="air-range__shown">' . $this->t('@from to @to, compared with @pfrom to @pto. Days in @zone.', [
          '@from' => $range->from,
          '@to' => $range->to,
          '@pfrom' => $range->previousFrom,
          '@pto' => $range->previousTo,
          '@zone' => $this->calendar->timezone(),
        ]) . '</p>',
      ],
    ];
  }

  /**
   * A data table as a render array, with its CSV link.
   */
  private function renderTable(string $tab, string $name, array $table, DateRange $range): array {
    $header = $table['header'];
    if (isset($table['spark'])) {
      $header[] = $this->t('Trend');
    }
    $rows = [];
    foreach ($table['rows'] as $i => $row) {
      $cells = [];
      foreach (array_values($row) as $j => $value) {
        $url = $table['links'][$i][$j] ?? NULL;
        $cells[] = match (TRUE) {
          $url instanceof Url => ['data' => ['#type' => 'link', '#title' => (string) $value, '#url' => $url]],
          // Markup drawn beside the value on the page; CSV gets the value.
          isset($table['markup'][$i][$j]) => ['data' => ['#markup' => Markup::create($table['markup'][$i][$j] . Html::escape((string) $value))]],
          default => (string) $value,
        };
      }
      if (isset($table['spark'])) {
        $cells[] = ['data' => ['#markup' => Markup::create(self::sparkline($table['spark'][$i] ?? []))]];
      }
      $rows[] = $cells;
    }
    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['air-table']],
      'title' => ['#markup' => '<h2>' . Html::escape($table['title']) . '</h2>'],
    ];
    if (!empty($table['help'])) {
      $build['help'] = ['#markup' => '<p class="air-help">' . Html::escape($table['help']) . '</p>'];
    }
    $build['table'] = [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => $rows,
      '#empty' => $table['empty'] ?? $this->t('Nothing to show for this range.'),
      '#sticky' => FALSE,
    ];
    $build['csv'] = [
      '#type' => 'link',
      '#title' => $this->t('Export CSV'),
      '#url' => Url::fromRoute('ad_inventory_report.csv', ['tab' => $tab, 'table' => $name], ['query' => $range->query()]),
      '#access' => $this->currentUser()->hasPermission('export ad inventory report'),
      '#attributes' => ['class' => ['air-csv']],
    ];
    return $build;
  }

  /**
   * An inline SVG sparkline; no script.
   *
   * @param int[] $values
   *   One value per day.
   */
  public static function sparkline(array $values): string {
    if (count($values) < 2) {
      return '';
    }
    $max = max(1, max($values));
    $step = 100 / (count($values) - 1);
    $points = [];
    foreach (array_values($values) as $i => $value) {
      $points[] = sprintf('%.1f,%.1f', $i * $step, 19 - $value / $max * 18);
    }
    return sprintf(
      '<svg class="air-spark" viewBox="0 0 100 20" width="100" height="20" role="img" aria-label="%s"><polyline fill="none" stroke="currentColor" stroke-width="1.5" points="%s"/></svg>',
      Html::escape(implode(', ', $values)),
      implode(' ', $points),
    );
  }

  /**
   * Click-through rate as a percentage.
   */
  private static function ctr(int $views, int $clicks): string {
    return $views > 0 ? number_format($clicks / $views * 100, 2) . '%' : '–';
  }

  /**
   * A change against the previous range.
   */
  private static function delta(float $now, float $before): string {
    if ($before == 0.0) {
      return $now > 0 ? 'new' : '–';
    }
    $change = ($now - $before) / $before * 100;
    return sprintf('%+.0f%%', $change);
  }

  /**
   * A name for a creative id or "provider:<id>".
   */
  private function creativeName(string $creative): string {
    if (str_starts_with($creative, 'provider:')) {
      $provider = $this->entityTypeManager()->getStorage('ad_provider')->load(substr($creative, 9));
      return $provider ? (string) $this->t('Network: @label', ['@label' => $provider->label()]) : $creative;
    }
    $entity = $this->entityTypeManager()->getStorage('ad_creative')->load($creative);
    return $entity ? (string) $entity->label() : (string) $this->t('Creative @id (deleted)', ['@id' => $creative]);
  }

  /**
   * A name for a slot id.
   */
  private function slotName(string $slot): string {
    if ($slot === '') {
      return (string) $this->t('(no slot given)');
    }
    $entity = $this->inventory->slot($slot);
    return $entity ? (string) $entity->label() : $slot;
  }

  /**
   * Bookings whose days overlap a period.
   *
   * @param string $from
   *   First day.
   * @param string $to
   *   Last day.
   * @param string[] $states
   *   States to include.
   *
   * @return \Drupal\ad_inventory\Entity\AdBookingInterface[]
   *   Bookings.
   */
  private function bookings(string $from, string $to, array $states = ['approved', 'stopped']): array {
    $storage = $this->entityTypeManager()->getStorage('ad_booking');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('state', $states, 'IN')
      ->condition('start_day', $to, '<=')
      ->condition('end_day', $from, '>=')
      ->sort('start_day')
      ->execute();
    return array_values(array_filter($storage->loadMultiple($ids), static fn($b): bool => $b instanceof AdBookingInterface));
  }

  /**
   * Booked slot-days over available slot-days in a period.
   *
   * @return array{booked: int, available: int}
   *   Slot-days.
   */
  private function sellThrough(string $from, string $to): array {
    $slots = array_keys($this->inventory->slots());
    $available = count($slots) * DateRange::length($from, $to);
    $booked = [];
    foreach ($this->bookings($from, $to, ['approved']) as $booking) {
      $first = max($from, $booking->start());
      $last = min($to, $booking->end());
      foreach (array_intersect($this->inventory->expandSlots($booking->slotValues()), $slots) as $slot) {
        for ($day = $first; $day <= $last; $day = Calendar::shift($day, 1)) {
          $booked[$slot . '|' . $day] = TRUE;
        }
      }
    }
    return ['booked' => count($booked), 'available' => $available];
  }

  /**
   * The Overview tab.
   */
  private function overview(DateRange $range): array {
    $now = $this->data->totals($range->from, $range->to);
    $before = $this->data->totals($range->previousFrom, $range->previousTo);
    $active = count($this->bookings($range->from, $range->to, ['approved']));
    $active_before = count($this->bookings($range->previousFrom, $range->previousTo, ['approved']));
    $sell = $this->sellThrough($range->from, $range->to);
    $sell_before = $this->sellThrough($range->previousFrom, $range->previousTo);
    $ratio = static fn(array $s): float => $s['available'] > 0 ? $s['booked'] / $s['available'] * 100 : 0.0;

    $cards = [
      [$this->t('Viewed impressions'), number_format($now['views']), self::delta($now['views'], $before['views'])],
      [$this->t('Clicks'), number_format($now['clicks']), self::delta($now['clicks'], $before['clicks'])],
      [
        $this->t('CTR'),
        self::ctr($now['views'], $now['clicks']),
        $before['views'] > 0 ? 'was ' . self::ctr($before['views'], $before['clicks']) : '–',
      ],
      [$this->t('Active bookings'), (string) $active, self::delta($active, $active_before)],
      [
        $this->t('Sell-through'),
        sprintf('%.1f%%', $ratio($sell)),
        sprintf('%d of %d slot-days; was %.1f%%', $sell['booked'], $sell['available'], $ratio($sell_before)),
      ],
    ];
    $sources = array_column($this->data->sum($range->from, $range->to, ['source']), 'views', 'source');
    $total = max(1, array_sum($sources));
    foreach ([
      'booking' => $this->t('From bookings'),
      'network' => $this->t('From networks'),
      'house' => $this->t('From house'),
    ] as $key => $label) {
      $cards[] = [
        $label,
        sprintf('%.0f%%', ($sources[$key] ?? 0) / $total * 100),
        number_format($sources[$key] ?? 0) . ' views',
      ];
    }
    $network = $this->money->network($range->from, $range->to);
    if ($network !== NULL) {
      $cards[] = [
        $this->t('Network earnings'),
        number_format($network['earnings'], 2) . ' ' . $network['currency'],
        'AdSense, via ga4_report',
      ];
      $cards[] = [
        $this->t('Page RPM'),
        $network['rpm'] === NULL ? '–' : number_format($network['rpm'], 2),
        'per 1,000 AdSense page views',
      ];
    }
    $booked = $this->money->booked($range->from, $range->to);
    if ($booked !== NULL) {
      $cards[] = [$this->t('Booked value'), number_format($booked['total'], 2), 'bookings starting in the range'];
    }

    $card_items = [];
    foreach ($cards as [$label, $value, $note]) {
      $card_items[] = Markup::create(sprintf(
        '<div class="air-card"><span class="air-card__label">%s</span><strong class="air-card__value">%s</strong><span class="air-card__note">%s</span></div>',
        Html::escape((string) $label),
        Html::escape((string) $value),
        Html::escape((string) $note),
      ));
    }

    $alerts = [];
    foreach ($this->status->gates() as $gate) {
      if ($gate['severity'] !== 'ok') {
        $alerts[] = Markup::create('<strong>' . Html::escape($gate['severity']) . '</strong> ' . Html::escape($gate['message']));
      }
    }

    $days = $range->days();
    $views = $this->data->series($days, '', 'views')[''];
    $clicks = $this->data->series($days, '', 'clicks')[''];
    $daily_rows = [];
    foreach ($days as $i => $day) {
      $daily_rows[] = [$day, $views[$i], $clicks[$i], self::ctr($views[$i], $clicks[$i])];
    }

    $top_slots = [];
    foreach (array_slice($this->data->sum($range->from, $range->to, ['slot']), 0, 5) as $row) {
      $top_slots[] = [
        $this->slotName((string) $row['slot']),
        $row['views'],
        $row['clicks'],
        self::ctr($row['views'], $row['clicks']),
      ];
    }
    $top_creatives = [];
    foreach (array_slice($this->data->sum($range->from, $range->to, ['creative']), 0, 5) as $row) {
      $top_creatives[] = [
        $this->creativeName((string) $row['creative']),
        $row['views'],
        $row['clicks'],
        self::ctr($row['views'], $row['clicks']),
      ];
    }

    $before_build = [
      'cards' => [
        '#theme' => 'item_list',
        '#items' => $card_items,
        '#attributes' => ['class' => ['air-cards']],
      ],
      'chart' => [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#attributes' => [
          'class' => ['air-chart'],
          'data-air-chart' => json_encode([
            'days' => $days,
            'series' => [
              ['name' => (string) $this->t('Viewed'), 'data' => $views],
              ['name' => (string) $this->t('Clicks'), 'data' => $clicks],
            ],
          ]),
        ],
      ],
      'consent' => [
        '#markup' => '<p class="air-help">' . $this->t('Views count only readers who allowed advertising: nothing is sent before that grant, so readers who decline leave no trace and no consent rate can be measured. Every figure here is a floor.') . '</p>',
      ],
    ];
    if (!$this->data->hasData()) {
      $before_build['empty'] = [
        '#markup' => '<p class="air-empty">' . $this->t('No counts yet. Counts arrive once slots are placed, a reader allows advertising and views one for a second; cron rolls them up for this dashboard.') . '</p>',
        '#weight' => -10,
      ];
    }
    if ($alerts) {
      $before_build['alerts'] = [
        '#theme' => 'item_list',
        '#title' => $this->t('Closed gates'),
        '#items' => $alerts,
        '#attributes' => ['class' => ['air-alerts']],
      ];
    }
    if ($network === NULL) {
      $before_build['money'] = [
        '#markup' => '<p class="air-help">' . $this->t('No network earnings: they show when the GA4 report module is installed and its AdSense source is configured.') . '</p>',
      ];
    }

    return [
      'before' => $before_build,
      'tables' => [
        'daily' => [
          'title' => (string) $this->t('Per day'),
          'header' => ['day', 'viewed', 'clicks', 'ctr'],
          'rows' => $daily_rows,
        ],
        'top_slots' => [
          'title' => (string) $this->t('Top 5 slots'),
          'header' => ['slot', 'viewed', 'clicks', 'ctr'],
          'rows' => $top_slots,
          'empty' => (string) $this->t('No slot was viewed in this range.'),
        ],
        'top_creatives' => [
          'title' => (string) $this->t('Top 5 creatives'),
          'header' => ['creative', 'viewed', 'clicks', 'ctr'],
          'rows' => $top_creatives,
          'empty' => (string) $this->t('No creative was viewed in this range.'),
        ],
      ],
    ];
  }

  /**
   * Block placements per slot: theme, region, visibility.
   *
   * @return array<string, string[]>
   *   Descriptions keyed by slot id.
   */
  private function blockPlacements(): array {
    $placed = [];
    foreach ($this->entityTypeManager()->getStorage('block')->loadMultiple() as $block) {
      $plugin = (string) $block->get('plugin');
      if (!str_starts_with($plugin, 'ad_inventory_slot:')) {
        continue;
      }
      $visibility = array_keys((array) $block->get('visibility'));
      $placed[substr($plugin, 18)][] = sprintf(
        '%s / %s%s%s',
        $block->get('theme'),
        $block->get('region'),
        $visibility ? ' (' . implode(', ', $visibility) . ')' : '',
        $block->status() ? '' : ' [disabled]',
      );
    }
    return $placed;
  }

  /**
   * Per-page controls per slot, counted over every field that holds them.
   *
   * @return array<string, array{off: int, format: int, count: int}>
   *   Entity counts keyed by slot id.
   */
  private function overrideCounts(): array {
    $counts = [];
    $map = $this->fieldManager->getFieldMapByFieldType('ad_inventory_overrides');
    foreach ($map as $entity_type => $fields) {
      $storage = $this->entityTypeManager()->getStorage($entity_type);
      if (!$storage instanceof SqlEntityStorageInterface) {
        continue;
      }
      $mapping = $storage->getTableMapping();
      $definitions = $this->fieldManager->getFieldStorageDefinitions($entity_type);
      foreach (array_keys($fields) as $field) {
        if (!isset($definitions[$field])) {
          continue;
        }
        $table = $mapping->getFieldTableName($field);
        $column = $mapping->getFieldColumnName($definitions[$field], 'value');
        $query = $this->database->select($table, 't')->fields('t', [$column])->isNotNull($column);
        foreach ($query->execute()->fetchCol() as $value) {
          foreach ((array) json_decode((string) $value, TRUE) as $slot => $control) {
            $counts[$slot] ??= ['off' => 0, 'format' => 0, 'count' => 0];
            $counts[$slot]['off'] += !empty($control['off']) ? 1 : 0;
            $counts[$slot]['format'] += isset($control['format']) || isset($control['phone_format']) ? 1 : 0;
            $counts[$slot]['count'] += isset($control['count']) ? 1 : 0;
          }
        }
      }
    }
    return $counts;
  }

  /**
   * A format's size and ratio, for people.
   */
  private function formatText(string $id): string {
    $format = $this->inventory->format($id);
    if ($format === NULL) {
      return $id . ' (missing)';
    }
    $gcd = static function (int $a, int $b) use (&$gcd): int {
      return $b === 0 ? $a : $gcd($b, $a % $b);
    };
    $d = max(1, $gcd($format->width(), $format->height()));
    return sprintf('%s %d×%d (%d:%d)', $format->id(), $format->width(), $format->height(), $format->width() / $d, $format->height() / $d);
  }

  /**
   * A box drawn to a format's ratio, 40px on its longer side.
   */
  private function ratioBox(string $id): string {
    $format = $this->inventory->format($id);
    if ($format === NULL || $format->width() < 1 || $format->height() < 1) {
      return '';
    }
    $scale = 40 / max($format->width(), $format->height());
    $w = max(2, (int) round($format->width() * $scale));
    $h = max(2, (int) round($format->height() * $scale));
    return sprintf(
      '<svg class="air-ratio" viewBox="0 0 %1$d %2$d" width="%1$d" height="%2$d" aria-hidden="true"><rect x="0.5" y="0.5" width="%3$d" height="%4$d" fill="none" stroke="currentColor"/></svg> ',
      $w,
      $h,
      $w - 1,
      $h - 1,
    );
  }

  /**
   * Where a block placement shows, for people.
   */
  private static function blockReach(array $visibility): string {
    $pages = trim((string) ($visibility['request_path']['pages'] ?? ''));
    if ($pages !== '') {
      $list = implode(', ', preg_split('/\R+/', $pages));
      return empty($visibility['request_path']['negate']) ? $list : 'every page except ' . $list;
    }
    foreach ($visibility as $condition => $settings) {
      if (str_starts_with((string) $condition, 'entity_bundle:') && !empty($settings['bundles'])) {
        return substr((string) $condition, 14) . ' pages: ' . implode(', ', array_keys($settings['bundles']));
      }
    }
    return $visibility ? 'pages matching ' . implode(', ', array_keys($visibility)) : 'every page';
  }

  /**
   * The space grid: every slot, its desktop and phone format, where it shows.
   */
  private function spaces(array $routes): array {
    $reach = [];
    $regions = [];
    foreach ($this->entityTypeManager()->getStorage('block')->loadMultiple() as $block) {
      $plugin = (string) $block->get('plugin');
      if (!str_starts_with($plugin, 'ad_inventory_slot:') || !$block->status()) {
        continue;
      }
      $slot = substr($plugin, 18);
      $reach[$slot][] = self::blockReach((array) $block->get('visibility'));
      $regions[$slot][] = (string) $block->get('region');
    }
    $grouped = [];
    foreach ($this->inventory->slots() as $id => $slot) {
      $group = $slot->group() ?: (isset($regions[$id]) ? implode(', ', array_unique($regions[$id])) : (string) $this->t('(no group)'));
      $grouped[$group][$id] = $slot;
    }
    ksort($grouped);
    $rows = $markup = [];
    foreach ($grouped as $group => $slots) {
      foreach ($slots as $id => $slot) {
        $i = count($rows);
        $formats = [2 => $slot->desktopFormat(), 3 => $slot->channel() === 'web' ? $slot->phoneFormat() : ''];
        $text = [];
        // The desktop and phone columns: the format with its box, or "none".
        foreach ($formats as $column => $format) {
          $text[$column] = $format === '' ? 'none' : $this->formatText($format);
          if ($format !== '') {
            $markup[$i][$column] = $this->ratioBox($format);
          }
        }
        $pages = array_merge($reach[$id] ?? [], array_map(static fn(string $r): string => 'route ' . $r, array_unique($routes[$id] ?? [])));
        if ($slot->channel() === 'email') {
          $pages = ['email'];
        }
        $rows[] = [
          $group,
          $slot->label(),
          $text[2],
          $text[3],
          $pages ? implode('; ', array_unique($pages)) : (string) $this->t('not placed'),
          implode(', ', $slot->sources()),
        ];
      }
    }
    return [
      'title' => (string) $this->t('Spaces'),
      'help' => (string) $this->t('Every slot by page group, with the format it shows on desktop and on a phone, drawn to its ratio.'),
      'header' => ['page group', 'slot', 'desktop', 'phone', 'pages', 'filled by'],
      'rows' => $rows,
      'markup' => $markup,
      'empty' => (string) $this->t('No slot is defined. An administrator adds slots under Structure › Ad inventory.'),
    ];
  }

  /**
   * The Inventory tab.
   */
  private function inventory(): array {
    $blocks = $this->blockPlacements();
    $routes = [];
    foreach ($this->placements->all() as $placement) {
      if ($placement['via'] === 'template') {
        $routes[$placement['slot']][] = $placement['route'];
      }
    }
    $overrides = $this->overrideCounts();
    $slots = [];
    foreach ($this->inventory->slots() as $id => $slot) {
      $units = [];
      foreach ($slot->units() as $provider => $fields) {
        $pairs = [];
        foreach ($fields as $field => $value) {
          $pairs[] = $field === 'markup' ? $field . '=(code)' : $field . '=' . $value;
        }
        $units[] = $provider . ': ' . implode(' ', $pairs);
      }
      $o = $overrides[$id] ?? ['off' => 0, 'format' => 0, 'count' => 0];
      $slots[] = [
        $slot->label(),
        $id,
        $slot->channel(),
        $this->formatText($slot->desktopFormat()),
        $slot->channel() === 'web' ? $this->formatText($slot->phoneFormat()) : '–',
        implode(', ', $slot->sources()),
        $slot->bookingKinds() ? implode(', ', $slot->bookingKinds()) : 'any',
        $slot->group() ?: '–',
        $units ? implode('; ', $units) : '–',
        isset($blocks[$id]) ? implode('; ', $blocks[$id]) : '–',
        isset($routes[$id]) ? implode(', ', array_unique($routes[$id])) : '–',
        sprintf('%d off, %d resized, %d counted', $o['off'], $o['format'], $o['count']),
      ];
    }
    $formats = [];
    foreach ($this->inventory->formats() as $id => $format) {
      $network = [];
      foreach ($format->networkSizes() as $row) {
        $network[] = sprintf('from %dpx: %d×%d', $row['min_width'], $row['width'], $row['height']);
      }
      $formats[] = [
        $format->label(),
        $id,
        $this->formatText($id),
        sprintf('%d×%d', $format->width() * 2, $format->height() * 2),
        $network ? implode('; ', $network) : 'no network unit',
      ];
    }
    return [
      'tables' => [
        'spaces' => $this->spaces($routes),
        'slots' => [
          'title' => (string) $this->t('Slots'),
          'help' => (string) $this->t('Template use is recorded when a template prints a slot (route names only) and kept 90 days.'),
          'header' => [
            'slot',
            'id',
            'channel',
            'desktop',
            'phone',
            'filled by',
            'booking kinds',
            'group',
            'network units',
            'blocks',
            'template routes',
            'per-page controls',
          ],
          'rows' => $slots,
          'empty' => (string) $this->t('No slot is defined. An administrator adds slots under Structure › Ad inventory.'),
        ],
        'formats' => [
          'title' => (string) $this->t('Formats'),
          'header' => ['format', 'id', 'size', 'upload at', 'network size'],
          'rows' => $formats,
        ],
      ],
    ];
  }

  /**
   * The Performance tab.
   */
  private function performance(DateRange $range, Request $request): array {
    $days = $range->days();
    $tables = [];

    $slot_spark = $this->data->series($days, 'slot', 'views');
    $rows = [];
    $spark = [];
    $links = [];
    $groups = [];
    $blocks = $this->blockPlacements();
    foreach ($this->data->sum($range->from, $range->to, ['slot']) as $i => $row) {
      $id = (string) $row['slot'];
      $rows[] = [$this->slotName($id), $row['views'], $row['clicks'], self::ctr($row['views'], $row['clicks'])];
      $spark[$i] = $slot_spark[$id] ?? [];
      $links[$i][0] = Url::fromRoute('ad_inventory_report.performance', [], ['query' => $range->query() + ['slot' => $id]]);
      $slot = $this->inventory->slot($id);
      $group = $slot && $slot->group() !== '' ? 'group: ' . $slot->group() : (isset($blocks[$id]) ? 'region: ' . implode(', ', $blocks[$id]) : '(none)');
      $groups[$group] ??= [0, 0];
      $groups[$group][0] += $row['views'];
      $groups[$group][1] += $row['clicks'];
    }
    $tables['slots'] = [
      'title' => (string) $this->t('Per slot'),
      'header' => ['slot', 'viewed', 'clicks', 'ctr'],
      'rows' => $rows,
      'spark' => $spark,
      'links' => $links,
      'empty' => (string) $this->t('No slot was viewed in this range.'),
    ];
    $group_rows = [];
    foreach ($groups as $group => [$views, $clicks]) {
      $group_rows[] = [$group, $views, $clicks, self::ctr($views, $clicks)];
    }
    $tables['groups'] = [
      'title' => (string) $this->t('Per page group (slot group, else block region)'),
      'header' => ['group', 'viewed', 'clicks', 'ctr'],
      'rows' => $group_rows,
    ];

    $creative_spark = $this->data->series($days, 'creative', 'views');
    $rows = [];
    $spark = [];
    foreach ($this->data->sum($range->from, $range->to, ['creative']) as $i => $row) {
      $rows[] = [
        $this->creativeName((string) $row['creative']),
        $row['views'],
        $row['clicks'],
        self::ctr($row['views'], $row['clicks']),
      ];
      $spark[$i] = $creative_spark[(string) $row['creative']] ?? [];
    }
    $tables['creatives'] = [
      'title' => (string) $this->t('Per creative'),
      'header' => ['creative', 'viewed', 'clicks', 'ctr'],
      'rows' => $rows,
      'spark' => $spark,
    ];

    $rows = [];
    $storage = $this->entityTypeManager()->getStorage('ad_booking');
    foreach ($this->data->sum($range->from, $range->to, ['booking'], ['source' => 'booking']) as $row) {
      $booking = $storage->load($row['booking']);
      $rows[] = [
        $booking ? $booking->label() : '#' . $row['booking'],
        $row['views'],
        $row['clicks'],
        self::ctr($row['views'], $row['clicks']),
      ];
    }
    $tables['bookings'] = [
      'title' => (string) $this->t('Per booking'),
      'header' => ['booking', 'viewed', 'clicks', 'ctr'],
      'rows' => $rows,
      'empty' => (string) $this->t('No booked creative was viewed in this range.'),
    ];

    $drill = (string) $request->query->get('slot', '');
    if ($drill !== '' && $this->inventory->slot($drill)) {
      $views = $this->data->series($days, '', 'views', ['slot' => $drill])[''];
      $clicks = $this->data->series($days, '', 'clicks', ['slot' => $drill])[''];
      $daily = [];
      foreach ($days as $i => $day) {
        $daily[] = [$day, $views[$i], $clicks[$i], self::ctr($views[$i], $clicks[$i])];
      }
      $tables['slot_daily'] = [
        'title' => (string) $this->t('@slot per day', ['@slot' => $this->slotName($drill)]),
        'header' => ['day', 'viewed', 'clicks', 'ctr'],
        'rows' => $daily,
      ];
    }
    return ['tables' => $tables];
  }

  /**
   * The Fill tab.
   */
  private function fill(DateRange $range): array {
    $pivot = [];
    foreach ($this->data->sum($range->from, $range->to, ['slot', 'day', 'source']) as $row) {
      $key = $row['slot'] . '|' . $row['day'];
      $pivot[$key] ??= [
        'slot' => $row['slot'],
        'day' => $row['day'],
        'booking' => 0,
        'network' => 0,
        'house' => 0,
        'unknown' => 0,
        'unfilled' => 0,
      ];
      $pivot[$key][$row['source']] = ($pivot[$key][$row['source']] ?? 0) + $row['views'];
      $pivot[$key]['unfilled'] += $row['unfilled'];
    }
    uasort($pivot, static fn(array $a, array $b): int => [$a['day'], $a['slot']] <=> [$b['day'], $b['slot']]);
    $rows = [];
    foreach ($pivot as $row) {
      $rows[] = [
        $row['day'],
        $this->slotName((string) $row['slot']),
        $row['booking'],
        $row['network'],
        $row['house'],
        $row['unfilled'],
      ];
    }
    $providers = [];
    foreach ($this->data->sum($range->from, $range->to, ['creative'], ['source' => 'network']) as $row) {
      $providers[] = [$this->creativeName((string) $row['creative']), $row['views'], $row['unfilled']];
    }
    return [
      'before' => [
        '#markup' => '<p class="air-help">' . $this->t('Viewed impressions by what filled the slot. An unfilled network unit is counted when the network marks it so (AdSense does); a house creative behind it is then the one counted as viewed.') . '</p>',
      ],
      'tables' => [
        'by_day' => [
          'title' => (string) $this->t('Per slot and day'),
          'header' => ['day', 'slot', 'booking', 'network', 'house', 'unfilled network units'],
          'rows' => $rows,
        ],
        'providers' => [
          'title' => (string) $this->t('Per network provider'),
          'header' => ['provider', 'viewed', 'unfilled'],
          'rows' => $providers,
          'empty' => (string) $this->t('No network unit was viewed in this range.'),
        ],
      ],
    ];
  }

  /**
   * The Calendar tab: the next 12 weeks, whatever the range.
   */
  private function calendar(): array {
    $today = $this->calendar->today();
    $monday = Calendar::shift($today, 1 - (int) (new \DateTimeImmutable($today . ' 12:00', new \DateTimeZone('UTC')))->format('N'));
    $weeks = [];
    for ($i = 0; $i < 12; $i++) {
      $weeks[] = Calendar::shift($monday, 7 * $i);
    }
    $end = Calendar::shift(end($weeks), 6);
    $bookings = $this->bookings($monday, $end);
    $grid = [];
    foreach ($this->inventory->slots() as $id => $slot) {
      $row = [$slot->label()];
      foreach ($weeks as $week) {
        $last = Calendar::shift($week, 6);
        $cell = 'free';
        foreach ($bookings as $booking) {
          if ($booking->start() > $last || $booking->end() < $week || !in_array($id, $this->inventory->expandSlots($booking->slotValues()), TRUE)) {
            continue;
          }
          $creative = $booking->creative();
          if ($booking->state() === 'approved') {
            $cell = sprintf('booked: %s – %s', $creative?->advertiserName() ?: '?', $creative?->label() ?? '?');
            break;
          }
          $cell = 'blocked (stopped booking)';
        }
        $row[] = $cell;
      }
      $grid[] = $row;
    }

    $soon = Calendar::shift($today, 7);
    $starting = [];
    $ending = [];
    foreach ($this->bookings($today, $soon, ['approved']) as $booking) {
      if ($booking->start() >= $today && $booking->start() <= $soon) {
        $starting[] = $this->bookingRow($booking);
      }
      if ($booking->end() >= $today && $booking->end() <= $soon) {
        $ending[] = $this->bookingRow($booking);
      }
    }
    $storage = $this->entityTypeManager()->getStorage('ad_booking');
    $drafts = [];
    foreach ($storage->loadMultiple($storage->getQuery()->accessCheck(FALSE)->condition('state', 'draft')->condition('end_day', $today, '>=')->sort('start_day')->execute()) as $booking) {
      $drafts[] = $this->bookingRow($booking) + [5 => $booking->reason() ?: '–'];
    }
    $stopped = [];
    foreach ($storage->loadMultiple($storage->getQuery()->accessCheck(FALSE)->condition('state', 'stopped')->condition('end_day', $today, '>=')->sort('start_day')->execute()) as $booking) {
      $stopped[] = $this->bookingRow($booking) + [5 => $booking->reason() ?: '–'];
    }
    $booking_header = ['booking', 'advertiser', 'creative', 'slots', 'days'];
    return [
      'tables' => [
        'weeks' => [
          'title' => (string) $this->t('The next 12 weeks'),
          'header' => array_merge(['slot'], array_map(static fn(string $w): string => 'week of ' . $w, $weeks)),
          'rows' => $grid,
          'empty' => (string) $this->t('No slot is defined.'),
        ],
        'starting' => [
          'title' => (string) $this->t('Starting in the next 7 days'),
          'header' => $booking_header,
          'rows' => $starting,
        ],
        'ending' => [
          'title' => (string) $this->t('Ending in the next 7 days'),
          'header' => $booking_header,
          'rows' => $ending,
        ],
        'drafts' => [
          'title' => (string) $this->t('Drafts awaiting approval'),
          'header' => array_merge($booking_header, ['why not approved']),
          'rows' => $drafts,
        ],
        'stopped' => [
          'title' => (string) $this->t('Stopped bookings, with the reason'),
          'header' => array_merge($booking_header, ['reason']),
          'rows' => $stopped,
        ],
      ],
    ];
  }

  /**
   * A booking as a row.
   */
  private function bookingRow(AdBookingInterface $booking): array {
    $creative = $booking->creative();
    return [
      (string) $booking->label(),
      $creative?->advertiserName() ?: '–',
      $creative?->label() ?? '–',
      implode(', ', $booking->slotValues()),
      $booking->start() . ' – ' . $booking->end(),
    ];
  }

  /**
   * The Providers tab.
   */
  private function providers(): array {
    $label = (string) $this->config('ad_inventory.settings')->get('label');
    $ads_txt = $this->status->adsTxt();
    $rows = [];
    $lines = [];
    $order = 0;
    foreach ($this->inventory->providers(FALSE) as $provider) {
      $plugin = $provider->plugin();
      $order++;
      if ($plugin === NULL) {
        $rows[] = [$order, $provider->label(), $provider->pluginId() . ' (missing)', '–', '–', '–', '–', '–'];
        continue;
      }
      $rows[] = [
        $order,
        $provider->label(),
        $provider->pluginId(),
        $provider->status() ? 'yes' : 'no',
        $this->gate->enabled() ? 'on' : 'off',
        $plugin->requiresAccount() ? AdProviderListBuilder::mask($plugin->account()) : 'not needed',
        ProviderPolicy::labelAllowed($label, $plugin->allowedLabels()) ? 'ok' : 'not allowed: ' . $label,
        $plugin->consent(),
      ];
      foreach ($plugin->adsTxtLines() as $line) {
        $lines[] = [$provider->label(), $line, StatusChecker::adsTxtHas($ads_txt, $line) ? 'present' : 'MISSING'];
      }
    }
    return [
      'tables' => [
        'providers' => [
          'title' => (string) $this->t('Providers in fill order'),
          'help' => (string) $this->t('Bookings fill first, then these, then house creatives. Account ids show their last four characters only.'),
          'header' => [
            'order',
            'provider',
            'plugin',
            'enabled',
            'environment gate',
            'account',
            'label',
            'consent it needs',
          ],
          'rows' => $rows,
          'empty' => (string) $this->t('No network provider is configured: slots fill from bookings and house creatives only.'),
        ],
        'ads_txt' => [
          'title' => (string) $this->t('ads.txt lines'),
          'header' => ['provider', 'line', 'in /ads.txt'],
          'rows' => $lines,
          'empty' => (string) $this->t('No provider declares an ads.txt line (or none has its account id here).'),
        ],
      ],
    ];
  }

  /**
   * The Email tab.
   */
  private function email(DateRange $range): array {
    $email_slots = array_filter($this->inventory->slots(), static fn(AdSlotInterface $s): bool => $s->channel() === 'email');
    $rows = [];
    if ($email_slots) {
      foreach ($this->data->sum($range->from, $range->to, ['slot', 'day'], ['slot' => array_keys($email_slots)]) as $row) {
        $rows[] = [$row['day'], $this->slotName((string) $row['slot']), $row['clicks']];
      }
      usort($rows, static fn(array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
    }
    $today = [];
    foreach ($email_slots as $id => $slot) {
      $creative = $this->email->pick($slot, new CacheableMetadata());
      $today[] = [
        $slot->label(),
        $creative instanceof AdCreativeInterface ? $creative->label() : (string) $this->t('nothing (a blank image)'),
        '/ad/e/' . $id . '.png',
      ];
    }
    return [
      'before' => [
        '#markup' => '<p class="air-help">' . $this->t('Clicks are counted at /ad/e/&lt;slot&gt;/go. Image fetches (opens) reach the edge, not this site, so they are not counted here; mail clients also prefetch images, which would overcount them.') . '</p>',
      ],
      'tables' => [
        'clicks' => [
          'title' => (string) $this->t('Clicks per email slot and day'),
          'header' => ['day', 'slot', 'clicks'],
          'rows' => $rows,
          'empty' => $email_slots ? (string) $this->t('No click in this range.') : (string) $this->t('No email slot is defined.'),
        ],
        'today' => [
          'title' => (string) $this->t('In force today'),
          'header' => ['slot', 'creative', 'image address'],
          'rows' => $today,
          'empty' => (string) $this->t('No email slot is defined.'),
        ],
      ],
    ];
  }

  /**
   * The Health tab: what is wrong, and where an editor fixes it.
   */
  private function health(): array {
    $items = [];
    $links = [];
    $add = function (string $problem, ?Url $url) use (&$items, &$links): void {
      $items[] = [$problem, $url ? $url->toString() : '–'];
      if ($url) {
        $links[count($items) - 1][1] = $url;
      }
    };
    $today = $this->calendar->today();

    // Creatives booked (or to be) whose formats lack a rendition.
    $storage = $this->entityTypeManager()->getStorage('ad_booking');
    $ids = $storage->getQuery()->accessCheck(FALSE)->condition('state', ['draft', 'approved'], 'IN')->condition('end_day', $today, '>=')->execute();
    foreach ($storage->loadMultiple($ids) as $booking) {
      if (!$booking instanceof AdBookingInterface || !($creative = $booking->creative())) {
        continue;
      }
      foreach ($this->inventory->renditionProblems($creative, $this->inventory->neededFormats($booking)) as $problem) {
        $add(sprintf('%s (booking %s): %s', $creative->label(), $booking->label(), $problem), $creative->toUrl('edit-form'));
      }
    }
    // Every rendition off its format's ratio.
    foreach ($this->entityTypeManager()->getStorage('ad_creative')->loadMultiple() as $creative) {
      if ($creative instanceof AdCreativeInterface) {
        foreach ($this->inventory->renditionProblems($creative, $creative->renditionFormats()) as $problem) {
          $add(sprintf('%s: %s', $creative->label(), $problem), $creative->toUrl('edit-form'));
        }
      }
    }
    // Slots never placed, and placements naming unknown slots.
    $placed = $this->status->placedSlots();
    foreach ($this->inventory->slots() as $id => $slot) {
      if ($slot->channel() === 'web' && !isset($placed[$id])) {
        $add(sprintf('The slot %s is configured but never placed (no block; no template has printed it).', $slot->label()), $slot->toUrl('edit-form'));
      }
    }
    foreach (array_keys($placed) as $id) {
      if ($this->inventory->slot((string) $id) === NULL) {
        $add(sprintf('A placement names the unknown slot "%s".', $id), Url::fromRoute('block.admin_display'));
      }
    }
    // Slots with nothing to fill them today.
    $network_on = $this->gate->enabled() && $this->inventory->providers();
    $house = $this->inventory->houseCreatives();
    foreach ($this->inventory->slots() as $id => $slot) {
      $formats = array_unique([$slot->desktopFormat(), $slot->phoneFormat()]);
      $booked = $slot->allows('booking') && $this->inventory->inForce($slot, $today);
      $networked = $slot->channel() === 'web' && $slot->allows('network') && $network_on && array_intersect_key($slot->units(), $this->inventory->providers());
      $housed = FALSE;
      if ($slot->allows('house')) {
        foreach ($house as $creative) {
          if ($this->inventory->fits($creative, $formats)) {
            $housed = TRUE;
            break;
          }
        }
      }
      if (!$booked && !$networked && !$housed) {
        $add(sprintf('The slot %s has no fill source in force today: an empty box after the grant.', $slot->label()), $slot->toUrl('edit-form'));
      }
    }
    // Providers whose label, account or exclusions fail.
    foreach ($this->status->gates() as $gate) {
      if ($gate['severity'] === 'error' && preg_match('/^(label|provider|conflict|ads_txt)/', $gate['gate'])) {
        $add($gate['message'], Url::fromRoute('entity.ad_provider.collection'));
      }
    }
    return [
      'before' => [
        '#markup' => '<p class="air-help">' . $this->t('Each problem links to the page where an editor fixes it. This dashboard reports; someone with editing rights acts.') . '</p>',
      ],
      'tables' => [
        'problems' => [
          'title' => (string) $this->t('Problems'),
          'header' => ['problem', 'where to fix it'],
          'rows' => $items,
          'links' => $links,
          'empty' => (string) $this->t('Nothing is wrong.'),
        ],
      ],
    ];
  }

}
