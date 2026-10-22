<?php

declare(strict_types=1);

namespace Drupal\ga4_report\Controller;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Url;
use Drupal\ga4_report\Form\RefreshForm;
use Drupal\ga4_report\Refresher;
use Drupal\ga4_report\Report\PageKinds;
use Drupal\ga4_report\SnapshotStore;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The report page: every chart drawn from the stored snapshot.
 *
 * Nothing here calls Google. The page is a render array cached under the
 * snapshot's tag; a refresh invalidates it. Each card carries its numbers as
 * a table behind a disclosure, so nothing depends on the charts' script.
 */
final class ReportController extends ControllerBase {

  public function __construct(
    private readonly SnapshotStore $store,
    private readonly Refresher $refresher,
    private readonly DateFormatterInterface $dateFormatter,
    private readonly TimeInterface $clock,
    private readonly string $appRoot,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('ga4_report.store'),
      $container->get('ga4_report.refresher'),
      $container->get('date.formatter'),
      $container->get('datetime.time'),
      $container->getParameter('app.root'),
    );
  }

  /**
   * Builds the page.
   */
  public function report(): array {
    $config = $this->config('ga4_report.settings');
    $ga4 = $this->store->snapshot('ga4');
    $cloudflare = $this->store->snapshot('cloudflare');
    $adsense = $this->store->snapshot('adsense');

    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['ga4r']],
      '#cache' => [
        'tags' => [SnapshotStore::CACHE_TAG, 'config:ga4_report.settings'],
        'contexts' => ['user.permissions'],
      ],
    ];
    $build['head'] = $this->head();

    if (!is_file($this->appRoot . '/libraries/echarts/dist/echarts.min.js')) {
      $build['library'] = $this->notice('warning', $this->t('The charts library is missing: add <code>"npm-asset/echarts": "6.1.0"</code> to the root composer.json (asset-packagist) and run composer install. The tables below still hold every number.'));
    }
    if ($ga4 === NULL && $cloudflare === NULL && $adsense === NULL) {
      $build['empty'] = $this->notice('status', $this->refresher->isConfigured()
        ? $this->t('No snapshot yet. Refresh to fetch the first one.')
        : $this->t('No snapshot yet. Name a source and its credentials in the <a href=":url">settings</a> first.', [
          ':url' => Url::fromRoute('ga4_report.settings')->toString(),
        ]));
      return $build;
    }

    $build['tiles'] = $this->tiles($ga4, $cloudflare, $adsense);
    if ($cloudflare !== NULL) {
      $build['edge'] = $this->edgeCards($cloudflare);
    }
    if ($ga4 !== NULL) {
      $build['ga4'] = $this->ga4Cards($ga4, $this->kindLabels());
    }
    if ($adsense !== NULL) {
      $build['ads'] = $this->adCards($adsense);
    }

    $build['#attached']['library'][] = 'ga4_report/report';
    $build['#attached']['drupalSettings']['ga4Report'] = [
      'snapshot' => $ga4,
      'cloudflare' => $cloudflare,
      'adsense' => $adsense,
      'kinds' => $this->kindLabels(),
      'markers' => $config->get('markers') ?? [],
    ];
    return $build;
  }

  /**
   * Kind labels by id, "other" included.
   */
  private function kindLabels(): array {
    $labels = [PageKinds::OTHER => (string) $this->t('Other pages')];
    foreach ($this->config('ga4_report.settings')->get('page_kinds') ?? [] as $kind) {
      $labels[$kind['id']] = $kind['label'];
    }
    return $labels;
  }

  /**
   * The Google Analytics cards.
   */
  private function ga4Cards(array $snapshot, array $kindLabels): array {
    $build = [];
    $daily = array_map(static fn(array $d): array => $d + [
      'internal' => isset($d['externalSessions']) ? $d['sessions'] - $d['externalSessions'] : '–',
    ], $snapshot['daily']);
    $build['daily'] = $this->card('daily', $this->t('Visits per day'),
      $snapshot['internal']
        ? $this->t('Visitors in colour; internal traffic in grey on top. The last day may be partial.')
        : $this->t('Visits per day. The last day may be partial.'),
      $this->table($daily, [
        'date' => $this->t('Day'),
        'sessions' => $this->t('Visits'),
        'internal' => $this->t('Internal visits'),
        'users' => $this->t('Users'),
        'newUsers' => $this->t('New users'),
        'views' => $this->t('Page views'),
        'engaged' => $this->t('Engaged visits'),
        'duration' => $this->t('Average visit (s)'),
      ]));
    $build['flow'] = $this->card('flow', $this->t('How visits arrive'),
      $this->t('Channel, then the site that sent the visit, then the device. Ribbon width is visits; small sources are folded together.'),
      $this->table($snapshot['channels'], [
        'channel' => $this->t('Channel'),
        'source' => $this->t('Source'),
        'device' => $this->t('Device'),
        'sessions' => $this->t('Visits'),
        'engaged' => $this->t('Engaged'),
        'users' => $this->t('Users'),
      ]), TRUE);

    $cities = array_map(fn(array $c): array => $c + [
      'placed' => $c['lat'] === NULL ? $this->t('no') : $this->t('yes'),
    ], $snapshot['cities']);
    // The plot shows the country most visits come from; the others are chips.
    $home = $snapshot['countries'][0]['country'] ?? '';
    $homeCities = array_filter($snapshot['cities'], static fn(array $c): bool => $c['country'] === $home);
    $unplaced = array_filter($homeCities, static fn(array $c): bool => $c['lat'] === NULL);
    $countries = array_map(
      static fn(array $c): string => $c['country'] . ' · ' . number_format($c['sessions']),
      array_slice($snapshot['countries'], 0, 12),
    );
    $placesNote = $this->t('Cities in @country, where most visits come from, placed by latitude and longitude; circle area is visits.', ['@country' => $home]);
    if ($snapshot['internal']) {
      $placesNote .= ' ' . $this->t('Dashed rings are internal visits.');
    }
    $rhythmNote = $this->t('Visits by weekday and hour, in the property’s time zone.');
    if ($snapshot['internal']) {
      $rhythmNote .= ' ' . $this->t('Internal traffic left out.');
    }
    $hours = array_map(
      static fn(array $c): array => ['day' => $c[0], 'hour' => $c[1], 'sessions' => $c[2]],
      array_values(array_filter($snapshot['weekHours'], static fn(array $c): bool => $c[2] > 0)),
    );
    $build['pair1'] = $this->pair(
      $this->card('places', $this->t('Where visitors are'), $placesNote,
        $this->table($cities, [
          'city' => $this->t('City'),
          'region' => $this->t('Region'),
          'country' => $this->t('Country'),
          'sessions' => $this->t('Visits'),
          'internalSessions' => $this->t('Internal'),
          'views' => $this->t('Page views'),
          'placed' => $this->t('Placed'),
        ]), TRUE, [
          'countries' => [
            '#theme' => 'item_list',
            '#items' => $countries,
            '#attributes' => ['class' => ['ga4r-chips']],
          ],
          'credit' => [
            '#markup' => '<p class="ga4r-note">' . $this->t('@placed of @all cities in @country placed (@unplaced visits not placed). Positions: <a href=":url">GeoNames</a>, CC BY 4.0.', [
              '@placed' => count($homeCities) - count($unplaced),
              '@all' => count($homeCities),
              '@country' => $home,
              '@unplaced' => array_sum(array_column($unplaced, 'sessions')),
              ':url' => 'https://www.geonames.org/',
            ]) . '</p>',
          ],
        ]),
      $this->card('rhythm', $this->t('When they come'), $rhythmNote . ' ' . $this->t('Darker is more.'),
        $this->table($hours, [
          'day' => $this->t('Weekday (0 = Sunday)'),
          'hour' => $this->t('Hour'),
          'sessions' => $this->t('Visits'),
        ])),
    );
    $build['pair2'] = $this->pair(
      $this->card('tech', $this->t('Devices, systems, browsers'),
        $this->t('Inner ring device, then operating system, then browser. Visits.'),
        $this->table($snapshot['tech'], [
          'device' => $this->t('Device'),
          'os' => $this->t('System'),
          'browser' => $this->t('Browser'),
          'sessions' => $this->t('Visits'),
          'users' => $this->t('Users'),
        ]), TRUE),
      $this->card('audience', $this->t('New or returning, by language'),
        $this->t('Column width is the browser language’s share of visits; the split inside is new against returning.'),
        $this->table($snapshot['audience'], [
          'type' => $this->t('New or returning'),
          'language' => $this->t('Language'),
          'sessions' => $this->t('Visits'),
          'users' => $this->t('Users'),
        ])),
    );
    $kindOf = static fn(array $row): array => ['kindLabel' => $kindLabels[$row['kind']] ?? $row['kind']] + $row;
    $build['pages'] = $this->card('pages', $this->t('What they read'),
      $this->t('The most viewed pages, grouped by kind; tile area is page views.'),
      $this->table(array_map($kindOf, $snapshot['pages']), [
        'path' => $this->t('Page'),
        'kindLabel' => $this->t('Kind'),
        'views' => $this->t('Views'),
        'users' => $this->t('Users'),
        'engagement' => $this->t('Engagement (s)'),
      ]), TRUE);
    $landing = $this->card('landing', $this->t('First page of a visit, by channel'),
      $snapshot['kinds']
        ? $this->t('The most common first pages, grouped by kind of page.')
        : $this->t('The most common first pages. Name kinds of page in the settings to group them.'),
      $this->table(array_map($kindOf, $snapshot['landing']), [
        'page' => $this->t('First page'),
        'kindLabel' => $this->t('Kind'),
        'channel' => $this->t('Channel'),
        'sessions' => $this->t('Visits'),
        'engaged' => $this->t('Engaged'),
      ]));
    // Without kinds of page there is nothing to count by kind: no empty card.
    if ($snapshot['kinds']) {
      $build['pair3'] = $this->pair(
        $this->card('kinds', $this->t('Page views by kind of page'),
          $this->t('Every page on the site, not only the most viewed. A page can count in more than one kind when patterns overlap.'),
          $this->table($snapshot['kinds'], [
            'label' => $this->t('Kind'),
            'views' => $this->t('Views'),
            'users' => $this->t('Users'),
          ])),
        $landing,
      );
    }
    else {
      $build['landing'] = $landing;
    }
    $build['events'] = $this->card('events', $this->t('What Analytics records'),
      $this->t('Every event name the property recorded in the period.'),
      NULL, FALSE, [
        'list' => $this->table($snapshot['events'], [
          'event' => $this->t('Event'),
          'count' => $this->t('Count'),
          'users' => $this->t('Users'),
        ]),
      ]);

    return $build;
  }

  /**
   * The Cloudflare cards: the last 24 hours by minute, then days.
   */
  private function edgeCards(array $cloudflare): array {
    $minutes = [];
    foreach ($cloudflare['minutes'] as [$minute, $cached, $missed, $other, $beacons]) {
      $all = $cached + $missed + $other;
      if ($all + $beacons === 0) {
        continue;
      }
      $minutes[] = [
        'minute' => str_replace('T', ' ', $minute),
        'cached' => $cached,
        'missed' => $missed,
        'other' => $other,
        'beacons' => $beacons,
        'rate' => $all ? round(100 * $cached / $all, 1) . ' %' : '–',
      ];
    }
    $days = array_map(static fn(array $d): array => [
      'date' => $d[0],
      'requests' => $d[1],
      'cached' => $d[2],
      'rate' => $d[1] ? round(100 * $d[2] / $d[1], 1) . ' %' : '–',
      'gb' => round($d[3] / 1e9, 2),
      'pageViews' => $d[5],
      'uniques' => $d[6],
    ], $cloudflare['days']);
    return [
      'minutes' => $this->card('edge', $this->t('Edge cache and visits, minute by minute'),
        $this->t("The last 24 hours, one point per minute. Hover a minute for its card. Hit rate is requests served from the edge cache against all cacheable ones; Cloudflare's own beacons are left out. Visits come from Google Analytics, which can lag a few hours."),
        $this->table($minutes, [
          'minute' => $this->t('Minute (UTC)'),
          'cached' => $this->t('From cache'),
          'missed' => $this->t('Missed'),
          'other' => $this->t('Dynamic or bypass'),
          'beacons' => $this->t('Beacons'),
          'rate' => $this->t('Hit rate'),
        ]), TRUE),
      'days' => $this->card('edgeDays', $this->t('Edge cache per day'),
        $this->t("Cloudflare's daily totals, beacons included, as its dashboard counts them; at most the last 30 days."),
        $this->table($days, [
          'date' => $this->t('Day'),
          'requests' => $this->t('Requests'),
          'cached' => $this->t('From cache'),
          'rate' => $this->t('Hit rate'),
          'gb' => $this->t('GB served'),
          'pageViews' => $this->t('Page views'),
          'uniques' => $this->t('Unique visitors'),
        ])),
    ];
  }

  /**
   * The AdSense card.
   */
  private function adCards(array $adsense): array {
    $days = array_map(static fn(array $d): array => [
      'date' => $d[0],
      'earnings' => number_format($d[1], 2),
      'pageViews' => $d[2],
      'rpm' => number_format($d[3], 2),
      'impressions' => $d[4],
      'clicks' => $d[5],
    ], $adsense['days']);
    return [
      'days' => $this->card('ads', $this->t('Ad earnings per day'),
        $this->t('Estimated earnings in @currency, and page RPM (earnings per thousand page views). AdSense finalises earnings at the end of each month.', ['@currency' => $adsense['currency'] ?: '?']),
        $this->table($days, [
          'date' => $this->t('Day'),
          'earnings' => $this->t('Earnings'),
          'pageViews' => $this->t('Page views'),
          'rpm' => $this->t('Page RPM'),
          'impressions' => $this->t('Impressions'),
          'clicks' => $this->t('Clicks'),
        ])),
    ];
  }

  /**
   * Access to the refresh button.
   */
  private function canRefresh(): bool {
    return AccessResult::allowedIfHasPermission($this->currentUser(), 'refresh ga4 report')->isAllowed();
  }

  /**
   * One line per source saying what its snapshot is, then the button.
   */
  private function head(): array {
    $head = [
      '#type' => 'container',
      '#attributes' => ['class' => ['ga4r-head']],
      'lines' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['ga4r-meta']],
      ],
    ];
    foreach ($this->refresher->sources() as $id => $source) {
      $meta = $this->store->meta($id);
      if (!empty($meta['fetched']) && $this->store->snapshot($id) !== NULL) {
        $head['lines'][$id] = [
          '#markup' => '<p>' . $this->t('@source (@what): snapshot taken @when, @ago ago.', [
            '@source' => $source->label(),
            '@what' => $this->provenance($id, $meta),
            '@when' => $this->dateFormatter->format((int) $meta['fetched'], 'short'),
            '@ago' => $this->dateFormatter->formatDiff((int) $meta['fetched'], $this->clock->getRequestTime()),
          ]) . '</p>',
        ];
      }
      if (!empty($meta['error'])) {
        $head['error_' . $id] = $this->notice('warning', $this->t('@source: the last refresh, @when, failed, and its previous snapshot stays: @error', [
          '@source' => $source->label(),
          '@when' => $this->dateFormatter->format((int) ($meta['attempted'] ?? 0), 'short'),
          '@error' => $meta['error'],
        ]));
      }
    }
    if ($this->canRefresh()) {
      $head['refresh'] = $this->formBuilder()->getForm(RefreshForm::class);
    }
    return $head;
  }

  /**
   * What a source's snapshot covers, in a few words.
   */
  private function provenance(string $id, array $meta): string {
    return match ($id) {
      'ga4' => sprintf('property %s, %s to %s', $meta['property'] ?? '?', $meta['start_date'] ?? '?', $meta['end_date'] ?? '?'),
      'cloudflare' => sprintf('zone %s…, last 24 hours and %s to %s', substr((string) ($meta['zone'] ?? '?'), 0, 8), $meta['days'][0] ?? '?', $meta['days'][1] ?? '?'),
      'adsense' => sprintf('%s, %s to %s', $meta['account'] ?? '?', $meta['start_date'] ?? '?', $meta['end_date'] ?? '?'),
      default => $id,
    };
  }

  /**
   * The headline numbers of every source that has a snapshot.
   */
  private function tiles(?array $ga4, ?array $cloudflare, ?array $adsense): array {
    $tiles = [];
    if ($ga4 !== NULL) {
      $t = $ga4['totals'];
      $tiles[] = [$this->t('Visits'), number_format($t['sessions'])];
      if ($t['externalSessions'] !== NULL) {
        $tiles[] = [$this->t('Visits without internal traffic'), number_format($t['externalSessions'])];
      }
      $tiles[] = [$this->t('Pages viewed'), number_format($t['views'])];
      if ($t['pageViewUsers'] !== NULL) {
        $tiles[] = [$this->t('Browsers that viewed a page'), number_format($t['pageViewUsers'])];
      }
      if ($t['searchShare'] !== NULL) {
        $tiles[] = [$this->t('Visits from search'), round($t['searchShare'] * 100) . ' %'];
      }
      if ($t['internalShare'] !== NULL) {
        $tiles[] = [$this->t('Internal visits'), round($t['internalShare'] * 100) . ' %'];
      }
    }
    if ($cloudflare !== NULL && $cloudflare['totals']['minuteHitRate'] !== NULL) {
      $tiles[] = [$this->t('Edge hit rate, 24 h'), round($cloudflare['totals']['minuteHitRate'] * 100, 1) . ' %'];
      $tiles[] = [$this->t('Edge requests, 24 h'), number_format($cloudflare['totals']['minuteRequests'])];
    }
    if ($adsense !== NULL) {
      $earnings = number_format($adsense['totals']['earnings'], 2) . ' ' . $adsense['currency'];
      $tiles[] = [$this->t('Estimated ad earnings'), $earnings];
      if ($adsense['totals']['rpm'] !== NULL) {
        $tiles[] = [$this->t('Page RPM'), number_format($adsense['totals']['rpm'], 2) . ' ' . $adsense['currency']];
      }
    }
    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['ga4r-tiles'], 'aria-label' => $this->t('Totals')],
    ];
    foreach ($tiles as $i => [$label, $value]) {
      $build[$i] = [
        '#markup' => '<div class="ga4r-tile"><span class="ga4r-tile-label">' . $label . '</span><span class="ga4r-tile-value">' . $value . '</span></div>',
      ];
    }
    return $build;
  }

  /**
   * One card: a title, a note, the chart's place and its table.
   */
  private function card(string $id, $title, $note, ?array $table, bool $tall = FALSE, array $extra = []): array {
    $card = [
      '#type' => 'html_tag',
      '#tag' => 'section',
      '#attributes' => ['class' => ['ga4r-card'], 'id' => 'ga4r-' . $id],
      'title' => ['#markup' => '<h2>' . $title . '</h2>'],
      'note' => ['#markup' => '<p class="ga4r-note">' . $note . '</p>'],
    ];
    if ($table !== NULL) {
      $card['chart'] = [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#attributes' => [
          'class' => array_filter(['ga4r-chart', $tall ? 'ga4r-tall' : NULL]),
          'data-ga4r-chart' => $id,
          'role' => 'img',
          'aria-label' => (string) $title,
        ],
      ];
    }
    $card += $extra;
    if ($table !== NULL) {
      $card['table'] = [
        '#type' => 'details',
        '#title' => $this->t('Show as table'),
        'table' => $table,
      ];
    }
    return $card;
  }

  /**
   * Two cards side by side on a wide screen.
   */
  private function pair(array $a, array $b): array {
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['ga4r-pair']],
      'a' => $a,
      'b' => $b,
    ];
  }

  /**
   * A table of snapshot rows, one column per key.
   *
   * @param array $items
   *   Snapshot rows.
   * @param array $columns
   *   Column headers keyed by the row key they show.
   */
  private function table(array $items, array $columns): array {
    $keys = array_keys($columns);
    return [
      '#type' => 'table',
      '#header' => array_values($columns),
      '#rows' => array_map(static fn(array $item): array => array_map(
        static fn(string $key) => is_int($item[$key] ?? NULL) ? number_format($item[$key]) : ($item[$key] ?? ''),
        $keys,
      ), $items),
      '#empty' => $this->t('No rows.'),
      '#attributes' => ['class' => ['ga4r-table']],
    ];
  }

  /**
   * A message box in the page (not the session messages).
   */
  private function notice(string $type, $message): array {
    return [
      '#markup' => '<div class="messages messages--' . $type . '" role="status">' . $message . '</div>',
    ];
  }

}
