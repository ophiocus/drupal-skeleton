<?php

declare(strict_types=1);

namespace Drupal\ad_inventory;

use Drupal\ad_inventory\Entity\AdCreativeInterface;
use Drupal\ad_inventory\Entity\AdFormatInterface;
use Drupal\ad_inventory\Entity\AdSlot;
use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\Routing\AdminContext;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Template\Attribute;
use Drupal\Core\Url;
use Drupal\image\ImageStyleInterface;

/**
 * Builds a web slot: the blocked banner, and the fill in an inert template.
 *
 * The HTML is the same for every reader. It carries the site's blocked
 * banner in the reserved box and, inside a <template> (whose images and
 * scripts the browser never requests), the approved bookings in force today
 * plus the next one, the network units, and the house creatives. Only after
 * the reader's browser reports the advertising grant does the slot script
 * pick one: booking in force, then network providers in their order, then
 * house. Nothing here reads a cookie or a session, and nothing varies per
 * reader: the cache contexts are permissions (bypass advertising), the route
 * name (admin and error pages get no network unit) and the interface
 * language (the label).
 */
final class SlotRenderer {

  /**
   * Error routes, where no network unit may show.
   */
  public const ERROR_ROUTES = ['system.401', 'system.403', 'system.404', 'system.4xx'];

  public function __construct(
    private readonly Inventory $inventory,
    private readonly Overrides $overrides,
    private readonly Placements $placements,
    private readonly NetworkGate $gate,
    private readonly Calendar $calendar,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AccountInterface $currentUser,
    private readonly RouteMatchInterface $routeMatch,
    private readonly AdminContext $adminContext,
    private readonly StateInterface $state,
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly FileUrlGeneratorInterface $fileUrlGenerator,
  ) {}

  /**
   * The render array of one slot.
   *
   * @param string $slot_id
   *   The slot id.
   * @param \Drupal\Core\Entity\EntityInterface|null $entity
   *   The page's entity (for per-page controls); NULL reads the route's.
   * @param int $delta
   *   Which repetition of a repeatable slot, from 0.
   * @param string[] $scopes
   *   Page context values for scoped bookings (hook_ad_inventory_scope_alter
   *   may add more).
   * @param string $via
   *   How it is printed, "block" or "template", for the placement map.
   */
  public function build(string $slot_id, ?EntityInterface $entity = NULL, int $delta = 0, array $scopes = [], string $via = 'template'): array {
    $cacheability = new CacheableMetadata();
    $cacheability->addCacheTags([AdSlot::fillTag($slot_id), 'config:ad_inventory.settings']);
    foreach (['ad_format', 'ad_provider', 'ad_creative'] as $type) {
      $cacheability->addCacheTags($this->entityTypeManager->getDefinition($type)->getListCacheTags());
    }
    $cacheability->addCacheContexts(['user.permissions', 'route.name', 'languages:language_interface']);
    $build = [];

    $slot = $this->inventory->slot($slot_id);
    if ($slot === NULL || $slot->channel() !== 'web') {
      $cacheability->addCacheTags($this->entityTypeManager->getDefinition('ad_slot')->getListCacheTags());
      $cacheability->applyTo($build);
      return $build;
    }
    $cacheability->addCacheableDependency($slot);

    $applied = Overrides::apply($this->overrides->forSlot($slot_id, $entity, $cacheability), $slot->desktopFormat(), $slot->phoneFormat());
    $desktop = $this->inventory->format($applied['desktop']) ?? $this->inventory->format($slot->desktopFormat());
    $phone = $this->inventory->format($applied['phone']) ?? $this->inventory->format($slot->phoneFormat());
    $hidden = $applied['off']
      || ($applied['count'] !== NULL && $delta >= $applied['count'])
      || ($delta > 0 && !$slot->isRepeatable());
    if ($hidden || $desktop === NULL || $phone === NULL) {
      $cacheability->applyTo($build);
      return $build;
    }

    $route = (string) $this->routeMatch->getRouteName();
    if ($route !== '') {
      $this->placements->record($slot_id, $route, $via);
    }

    $context = ['slot' => $slot, 'entity' => $entity, 'route_match' => $this->routeMatch];
    $this->moduleHandler->alter('ad_inventory_scope', $scopes, $context, $cacheability);
    $scopes = array_values(array_filter(array_map('strval', $scopes)));

    $formats = array_values(array_unique([(string) $desktop->id(), (string) $phone->id()]));
    $settings = $this->configFactory->get('ad_inventory.settings');
    $breakpoint = (int) $settings->get('breakpoint');
    $today = $this->calendar->today();
    $entries = [];

    if ($slot->allows('booking')) {
      foreach ($this->inventory->printable($slot, $today, $scopes) as $booking) {
        $creative = $booking->creative();
        $cacheability->addCacheableDependency($booking);
        if ($creative === NULL || !$this->inventory->fits($creative, $formats)) {
          continue;
        }
        $entries[] = $this->creativeEntry('booking', $creative, $desktop, $phone, $slot_id, $breakpoint, $cacheability, [
          'data-ad-booking' => (string) $booking->id(),
          'data-start' => $booking->start(),
          'data-end' => $booking->end(),
          'data-exclusive' => $booking->isExclusive() ? '1' : '0',
        ]);
      }
    }

    if ($slot->allows('network') && $desktop->networkSizes() && $phone->networkSizes() && $this->networkAllowed($cacheability)) {
      foreach ($this->inventory->providers() as $id => $provider) {
        $unit = $slot->units()[$id] ?? NULL;
        $plugin = $provider->plugin();
        if ($unit === NULL || $plugin === NULL || ($plugin->requiresAccount() && $plugin->account() === NULL)) {
          continue;
        }
        $markup = $plugin->unitMarkup($unit);
        if ($markup === '') {
          continue;
        }
        $entries[] = [
          'attributes' => new Attribute([
            'data-ad-kind' => 'network',
            'data-ad-creative' => 'provider:' . $id,
            'data-ad-provider' => (string) $id,
            'data-ad-handler' => $plugin->handler(),
            'data-ad-loader' => $plugin->loaderUrl(),
            'data-ad-max' => (string) $plugin->maxUnits(),
            'data-ad-house-behind' => $plugin->houseBehind() ? '1' : '0',
          ]),
          'content' => ['#markup' => Markup::create('<div class="ad-slot__unit">' . $markup . '</div>')],
        ];
      }
    }

    if ($slot->allows('house')) {
      foreach ($this->inventory->houseCreatives() as $creative) {
        if ($this->inventory->fits($creative, $formats)) {
          $entries[] = $this->creativeEntry('house', $creative, $desktop, $phone, $slot_id, $breakpoint, $cacheability);
        }
      }
    }

    $label = (string) $settings->get('label');
    $build = [
      '#theme' => 'ad_slot',
      '#slot' => $slot_id,
      '#label' => $label,
      '#blocked_text' => (string) $settings->get('blocked.text'),
      '#blocked_link_text' => (string) $settings->get('blocked.link_text'),
      '#blocked_link_url' => UrlHelper::stripDangerousProtocols((string) $settings->get('blocked.link_url')),
      '#entries' => $entries,
      '#attributes' => [
        'class' => ['ad-slot'],
        'data-ad-slot' => $slot_id,
        'data-ad-desktop' => (string) $desktop->id(),
        'data-ad-phone' => (string) $phone->id(),
        'aria-label' => $label,
      ],
      '#attached' => $this->attachments(),
    ];
    $cacheability->applyTo($build);
    return $build;
  }

  /**
   * What every slot attaches: the script, its settings, the format styles.
   */
  public function attachments(): array {
    $settings = $this->configFactory->get('ad_inventory.settings');
    $formats = [];
    foreach ($this->inventory->formats() as $id => $format) {
      $formats[$id] = [
        'width' => $format->width(),
        'height' => $format->height(),
        'network' => $format->networkSizes(),
      ];
    }
    return [
      'library' => ['ad_inventory/slot'],
      'drupalSettings' => [
        'adInventory' => [
          'beacon' => Url::fromRoute('ad_inventory.beacon')->toString(TRUE)->getGeneratedUrl(),
          'timezone' => $this->calendar->timezone(),
          'consent' => [
            'global' => (string) $settings->get('consent.global'),
            'property' => (string) $settings->get('consent.property'),
            'event' => (string) $settings->get('consent.event'),
          ],
        ],
      ],
      'html_head' => [
        [
          [
            '#tag' => 'style',
            '#value' => Markup::create(FormatStyles::css($formats, (int) $settings->get('breakpoint'))),
            '#attributes' => ['data-ad-inventory' => 'formats'],
          ],
          'ad_inventory_formats',
        ],
      ],
    ];
  }

  /**
   * Whether network units may be printed for this viewer on this page.
   */
  public function networkAllowed(CacheableMetadata $cacheability): bool {
    if (!$this->gate->enabled()) {
      return FALSE;
    }
    if ($this->currentUser->hasPermission('bypass advertising')) {
      return FALSE;
    }
    $route = $this->routeMatch->getRouteObject();
    if ($route !== NULL && $this->adminContext->isAdminRoute($route)) {
      return FALSE;
    }
    if (in_array($this->routeMatch->getRouteName(), self::ERROR_ROUTES, TRUE)) {
      return FALSE;
    }
    if ($this->state->get('system.maintenance_mode')) {
      // Only privileged accounts see pages now; do not keep this render.
      $cacheability->mergeCacheMaxAge(0);
      return FALSE;
    }
    return TRUE;
  }

  /**
   * One booked or house creative, as the template carries it.
   */
  private function creativeEntry(string $kind, AdCreativeInterface $creative, AdFormatInterface $desktop, AdFormatInterface $phone, string $slot_id, int $breakpoint, CacheableMetadata $cacheability, array $attributes = []): array {
    $cacheability->addCacheableDependency($creative);
    $href = '';
    if ($creative->targetUrl() !== '') {
      $url = Url::fromRoute('ad_inventory.click', ['ad_creative' => $creative->id()], ['query' => ['slot' => $slot_id]])->toString(TRUE);
      $cacheability->addCacheableDependency($url);
      $href = $url->getGeneratedUrl();
    }
    $picture = $this->picture($creative, $desktop, $phone, $breakpoint, $cacheability);
    return [
      'attributes' => new Attribute([
        'data-ad-kind' => $kind,
        'data-ad-creative' => (string) $creative->id(),
        'data-caption' => $creative->caption(),
      ] + $attributes),
      'content' => [
        '#theme' => 'ad_creative',
        '#href' => $href,
        '#alt' => $creative->alt(),
        '#desktop' => $picture['desktop'],
        '#phone' => $picture['phone'],
      ],
    ];
  }

  /**
   * The <picture> sources of a creative.
   *
   * @return array{desktop: array, phone: array|null}
   *   desktop: src, srcset, width, height; phone: media, srcset, width,
   *   height, or NULL when the phone format is the desktop one.
   */
  public function picture(AdCreativeInterface $creative, AdFormatInterface $desktop, AdFormatInterface $phone, int $breakpoint, CacheableMetadata $cacheability): array {
    $sources = [];
    foreach (['desktop' => $desktop, 'phone' => $phone] as $key => $format) {
      $media = $creative->rendition((string) $format->id());
      $uri = $media ? Inventory::mediaUri($media) : NULL;
      if ($media === NULL || $uri === NULL) {
        $sources[$key] = NULL;
        continue;
      }
      $cacheability->addCacheableDependency($media);
      $one = $this->styled($uri, $format->styleName(1), $cacheability);
      $two = $this->styled($uri, $format->styleName(2), $cacheability);
      $sources[$key] = [
        'src' => $one,
        'srcset' => $one . ' 1x, ' . $two . ' 2x',
        'width' => $format->width(),
        'height' => $format->height(),
      ];
    }
    $phone_source = NULL;
    if ($sources['phone'] !== NULL && $phone->id() !== $desktop->id() && $breakpoint > 0) {
      $phone_source = $sources['phone'] + ['media' => sprintf('(max-width: %dpx)', $breakpoint - 1)];
    }
    return ['desktop' => $sources['desktop'] ?? [], 'phone' => $phone_source];
  }

  /**
   * A file through an image style, as a root-relative URL.
   */
  private function styled(string $uri, string $style_name, CacheableMetadata $cacheability): string {
    $style = $this->entityTypeManager->getStorage('image_style')->load($style_name);
    if ($style instanceof ImageStyleInterface) {
      $cacheability->addCacheableDependency($style);
      return $this->fileUrlGenerator->transformRelative($style->buildUrl($uri));
    }
    return $this->fileUrlGenerator->generateString($uri);
  }

}
