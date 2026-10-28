<?php

declare(strict_types=1);

namespace Drupal\ad_inventory;

use Drupal\ad_inventory\Entity\AdCreative;
use Drupal\ad_inventory\Entity\AdFormat;
use Drupal\ad_inventory\Entity\AdSlot;
use Drupal\ad_inventory\Scope\ScopeCollector;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\image\ImageStyleInterface;
use Drupal\media\MediaInterface;

/**
 * Builds the render array of a space on the current page.
 *
 * The markup is the same for every reader of the page: a labelled box of
 * the format's proportion holding the "turned off" notice, with the creative
 * (or the provider's unit) inert inside a <template>; house banners get one
 * each and the browser shows one of them at random. The browser swaps it in
 * once the reader's advertising consent is known to be granted. A space with
 * nothing to show renders nothing.
 */
final class SlotBuilder {

  use StringTranslationTrait;

  /**
   * Which placements a space prints in.
   */
  public const BOTH = 'both';
  public const DESKTOP = 'desktop';
  public const PHONE = 'phone';

  public function __construct(
    private readonly AdResolver $resolver,
    private readonly ScopeCollector $scopes,
    private readonly AccountInterface $account,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly FileUrlGeneratorInterface $fileUrlGenerator,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * The render array of a space, or an empty array carrying its cacheability.
   *
   * @return array<string, mixed>
   *   Render array.
   */
  public function build(string $slot_id, string $devices = self::BOTH): array {
    $cacheability = new CacheableMetadata();
    $build = [];
    $slot = $this->entityTypeManager->getStorage('ad_slot')->load($slot_id);
    if ($slot instanceof AdSlot && $slot->status() && $slot->channel() === AdSlot::WEB) {
      $fill = $this->resolver->resolve($slot, $this->scopes->current(), $this->account, $cacheability);
      if ($fill !== NULL) {
        $build = $this->render($slot, $fill, $devices, $cacheability);
      }
    }
    else {
      $cacheability->addCacheTags(['config:ad_slot_list']);
    }
    $cacheability->applyTo($build);
    return $build;
  }

  /**
   * The markup for a resolved fill.
   *
   * @return array<string, mixed>
   *   Render array.
   */
  private function render(AdSlot $slot, Fill $fill, string $devices, CacheableMetadata $cacheability): array {
    // A missing format renders nothing, and must renew once the format
    // arrives (a config import adding formats after spaces).
    $cacheability->addCacheTags(['config:ad_format_list']);
    $desktop = $this->resolver->format($fill->desktop);
    $phone = $this->resolver->format($fill->phone) ?? $desktop;
    if ($desktop === NULL || $phone === NULL) {
      return [];
    }
    $settings = $this->configFactory->get('ad_inventory.settings');
    $cacheability->addCacheableDependency($settings);
    $breakpoint = (int) ($settings->get('breakpoint') ?: 900);

    $candidates = [];
    $loaders = [];
    if ($fill->creative !== NULL) {
      foreach ($fill->candidates ?: [$fill->creative] as $creative) {
        $picture = $this->picture($creative, $desktop, $phone, $breakpoint, (string) $slot->id(), $fill->countSource(), $cacheability);
        if ($picture !== []) {
          $candidates[] = [
            'id' => (int) $creative->id(),
            'content' => $picture,
            'caption' => (string) $creative->get('caption')->value,
          ];
        }
      }
      if ($candidates === []) {
        return [];
      }
    }
    elseif ($fill->provider !== NULL) {
      $plugin = $fill->provider->instance();
      // Provider markup is pasted by someone holding a restricted permission
      // and must reach the page as written (scripts included): it is inert
      // inside the <template> until the browser fills the space.
      $candidates[] = [
        'id' => 0,
        'content' => [
          '#type' => 'inline_template',
          '#template' => '{{ unit|raw }}',
          '#context' => ['unit' => $plugin->unit($slot, $desktop)],
        ],
        'caption' => '',
      ];
      $loaders = $plugin->loaders();
    }

    return [
      '#theme' => 'ad_inventory_slot',
      '#slot_id' => (string) $slot->id(),
      '#source' => $fill->countSource(),
      '#creative_id' => $candidates[0]['id'] ?? 0,
      '#devices' => $devices,
      '#draft' => $fill->draft,
      '#desktop' => ['width' => $desktop->width(), 'height' => $desktop->height()],
      '#phone' => ['width' => $phone->width(), 'height' => $phone->height()],
      '#label' => (string) ($settings->get('label') ?: $this->t('Sponsored Links')),
      '#blocked_reason' => $this->t('Turned off by your cookie choice'),
      '#blocked_link' => $this->t('Change my cookies'),
      '#consent_attribute' => (string) ($settings->get('consent.open_attribute') ?: 'data-ad-consent-open'),
      '#candidates' => $candidates,
      '#attached' => [
        'library' => ['ad_inventory/slot'],
        'drupalSettings' => [
          'adInventory' => [
            'consent' => [
              'global' => (string) ($settings->get('consent.global') ?: 'adConsent'),
              'category' => (string) ($settings->get('consent.category') ?: 'ads'),
              'event' => (string) ($settings->get('consent.event') ?: 'ad:consent'),
            ],
            'beacon' => Url::fromRoute('ad_inventory.beacon')->toString(),
            'loaders' => array_values($loaders),
          ],
        ],
      ],
    ];
  }

  /**
   * A creative as <picture>: the phone rendition below the breakpoint.
   *
   * @return array<string, mixed>
   *   Render array.
   */
  private function picture(AdCreative $creative, AdFormat $desktop, AdFormat $phone, int $breakpoint, string $slot_id, string $source, CacheableMetadata $cacheability): array {
    $d = $this->sources($creative, $desktop, $cacheability);
    $p = $this->sources($creative, $phone, $cacheability);
    if ($d === NULL || $p === NULL) {
      return [];
    }
    return [
      '#theme' => 'ad_inventory_picture',
      '#href' => Url::fromRoute('ad_inventory.click', ['ad_creative' => $creative->id()], [
        'query' => ['s' => $slot_id, 'f' => $source],
      ])->toString(),
      '#alt' => (string) $creative->get('alt')->value,
      '#desktop' => $d + ['width' => $desktop->width(), 'height' => $desktop->height()],
      '#phone' => $p + ['width' => $phone->width(), 'height' => $phone->height()],
      '#media' => '(max-width: ' . $breakpoint . 'px)',
    ];
  }

  /**
   * The 1x (image style) and 2x (original) URLs of a rendition.
   *
   * @return array{x1: string, x2: string}|null
   *   URLs, or NULL when the rendition is missing.
   */
  private function sources(AdCreative $creative, AdFormat $format, CacheableMetadata $cacheability): ?array {
    $media = $creative->rendition((string) $format->id());
    if (!$media instanceof MediaInterface) {
      return NULL;
    }
    $cacheability->addCacheableDependency($media);
    $source = $media->getSource()->getConfiguration()['source_field'] ?? '';
    $file = $source !== '' ? $media->get($source)->entity : NULL;
    if ($file === NULL) {
      return NULL;
    }
    $cacheability->addCacheableDependency($file);
    $uri = $file->getFileUri();
    $x2 = $this->fileUrlGenerator->generateString($uri);
    $style = $this->entityTypeManager->getStorage('image_style')->load('ad_inventory_' . $format->id());
    $x1 = $x2;
    if ($style instanceof ImageStyleInterface) {
      $cacheability->addCacheableDependency($style);
      $x1 = $this->fileUrlGenerator->transformRelative($style->buildUrl($uri));
    }
    return ['x1' => $x1, 'x2' => $x2];
  }

}
