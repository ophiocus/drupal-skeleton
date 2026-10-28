<?php

declare(strict_types=1);

namespace Drupal\ad_inventory;

use Drupal\ad_inventory\Entity\AdBooking;
use Drupal\ad_inventory\Entity\AdCreative;
use Drupal\ad_inventory\Entity\AdFormat;
use Drupal\ad_inventory\Entity\AdOverride;
use Drupal\ad_inventory\Entity\AdProvider;
use Drupal\ad_inventory\Entity\AdSlot;
use Drupal\ad_inventory\Scope\AdScope;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Decides what fills a space on a page, or that nothing does.
 *
 * In order: a booking in force for the space and the page's scope, then the
 * providers in their order, then the house banners (the browser shows one of
 * them at random). With none of them approved
 * the space is not printed at all. Staff who preview drafts also get drafts,
 * which is a role-based difference: anonymous readers all share one page.
 *
 * Cache: every page carrying a space depends on the space's tag (bookings,
 * creatives and providers invalidate it), on the day (cron renews it when a
 * booking starts or ends) and on the page's own settings.
 */
final class AdResolver {

  /**
   * The tag cron invalidates when a booking's first or last day passes.
   */
  public const DAY_TAG = 'ad_inventory_day';

  /**
   * House banners printed per space for the browser's random pick.
   */
  public const HOUSE_POOL = 8;

  /**
   * Bookings loaded this request.
   *
   * @var \Drupal\ad_inventory\Entity\AdBooking[]|null
   */
  private ?array $bookings = NULL;

  /**
   * House creatives loaded this request.
   *
   * @var \Drupal\ad_inventory\Entity\AdCreative[]|null
   */
  private ?array $house = NULL;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly SiteDay $siteDay,
    private readonly OverrideStore $overrides,
  ) {}

  /**
   * What fills a space on a page, or NULL.
   */
  public function resolve(AdSlot $slot, AdScope $scope, AccountInterface $account, CacheableMetadata $cacheability): ?Fill {
    $cacheability
      ->addCacheTags([
        AdSlot::tag((string) $slot->id()),
        self::DAY_TAG,
        'ad_booking_list',
        'ad_creative_list',
        'config:ad_provider_list',
      ])
      ->addCacheContexts(['user.permissions'])
      ->addCacheableDependency($slot)
      ->addCacheableDependency($scope->cacheability);
    $preview = $account->hasPermission('preview ad drafts');

    [$desktop, $phone] = [$slot->desktopFormat(), $slot->phoneFormat()];
    if ($scope->page !== NULL) {
      $cacheability->addCacheTags([AdOverride::tag($scope->page->getEntityTypeId(), $scope->page->id())]);
      $own = $this->overrides->get($scope->page)[(string) $slot->id()] ?? [];
      if (!empty($own['off'])) {
        return NULL;
      }
      $allowed = array_merge($slot->formats(), $slot->alternatives());
      if (!empty($own['desktop']) && in_array($own['desktop'], $allowed, TRUE)) {
        $desktop = (string) $own['desktop'];
      }
      if (!empty($own['phone']) && in_array($own['phone'], $allowed, TRUE)) {
        $phone = (string) $own['phone'];
      }
    }
    $formats = array_values(array_unique([$desktop, $phone]));

    if ($slot->accepts(Fill::BOOKING)) {
      $booking = $this->booking($slot, $scope, $preview, $formats);
      if ($booking !== NULL) {
        $draft = $booking->state() !== AdBooking::APPROVED || !$booking->creative()?->isApproved();
        $cacheability->addCacheableDependency($booking)->addCacheableDependency($booking->creative());
        return new Fill(Fill::BOOKING, $desktop, $phone, $draft, $booking->creative(), $booking);
      }
    }
    if ($slot->accepts(Fill::PROVIDER) && !$account->hasPermission('bypass advertising')) {
      foreach ($this->providers($preview) as $provider) {
        if (!$provider->covers((string) $slot->id())) {
          continue;
        }
        $format = $this->format($desktop);
        if ($format !== NULL && $provider->instance()->unit($slot, $format) !== '') {
          $cacheability->addCacheableDependency($provider);
          return new Fill(Fill::PROVIDER, $desktop, $phone, !$provider->isApproved(), provider: $provider);
        }
      }
    }
    if ($slot->accepts(Fill::HOUSE)) {
      // Every house banner that fits goes to the page; the browser shows one
      // at random, so the page stays the same for every reader.
      $pool = [];
      foreach ($this->house($preview) as $creative) {
        if ($creative->housesIn((string) $slot->id()) && $creative->missing($formats) === []) {
          $cacheability->addCacheableDependency($creative);
          $pool[] = $creative;
          if (count($pool) >= self::HOUSE_POOL) {
            break;
          }
        }
      }
      if ($pool !== []) {
        $draft = array_filter($pool, static fn (AdCreative $c) => !$c->isApproved()) !== [];
        return new Fill(Fill::HOUSE, $desktop, $phone, $draft, $pool[0], candidates: $pool);
      }
    }
    return NULL;
  }

  /**
   * The booking in force for a space and scope, scoped bookings first.
   *
   * @param \Drupal\ad_inventory\Entity\AdSlot $slot
   *   The space.
   * @param \Drupal\ad_inventory\Scope\AdScope $scope
   *   What the page is about.
   * @param bool $preview
   *   Whether drafts count (staff previewing them).
   * @param string[] $formats
   *   Formats the creative must carry.
   */
  private function booking(AdSlot $slot, AdScope $scope, bool $preview, array $formats): ?AdBooking {
    $today = $this->siteDay->today();
    $best = NULL;
    foreach ($this->bookings() as $booking) {
      $state = $booking->state();
      if ($state === AdBooking::STOPPED || ($state === AdBooking::DRAFT && !$preview)) {
        continue;
      }
      $creative = $booking->creative();
      if ($creative === NULL || (!$creative->isApproved() && !$preview)) {
        continue;
      }
      if (!in_array((string) $slot->id(), $booking->slots(), TRUE) || !$booking->covers($today) || !$scope->matches($booking->scope())) {
        continue;
      }
      if (!$slot->allowsKind($creative->kind()) || $creative->missing($formats) !== []) {
        continue;
      }
      // An approved booking beats a draft; a scoped one beats one for every
      // page; then the earliest booked.
      $rank = [$state === AdBooking::APPROVED ? 0 : 1, $booking->scope() !== '' ? 0 : 1, (int) $booking->id()];
      if ($best === NULL || $rank < $best[0]) {
        $best = [$rank, $booking];
      }
    }
    return $best[1] ?? NULL;
  }

  /**
   * Bookings that are not stopped.
   *
   * @return \Drupal\ad_inventory\Entity\AdBooking[]
   *   Bookings.
   */
  private function bookings(): array {
    if ($this->bookings === NULL) {
      $storage = $this->entityTypeManager->getStorage('ad_booking');
      $ids = $storage->getQuery()->accessCheck(FALSE)
        ->condition('state', AdBooking::STOPPED, '<>')
        ->sort('id')
        ->execute();
      $this->bookings = array_filter($storage->loadMultiple($ids), static fn ($b) => $b instanceof AdBooking);
    }
    return $this->bookings;
  }

  /**
   * House creatives readers (or previewers) may see, oldest first.
   *
   * @return \Drupal\ad_inventory\Entity\AdCreative[]
   *   Creatives.
   */
  private function house(bool $preview): array {
    if ($this->house === NULL) {
      $storage = $this->entityTypeManager->getStorage('ad_creative');
      $ids = $storage->getQuery()->accessCheck(FALSE)
        ->condition('house', 1)
        ->sort('id')
        ->execute();
      $this->house = array_filter($storage->loadMultiple($ids), static fn ($c) => $c instanceof AdCreative);
    }
    return array_filter($this->house, static fn (AdCreative $c) => $preview || $c->isApproved());
  }

  /**
   * Enabled providers in order; drafts only for previewers.
   *
   * @return \Drupal\ad_inventory\Entity\AdProvider[]
   *   Providers.
   */
  private function providers(bool $preview): array {
    $providers = $this->entityTypeManager->getStorage('ad_provider')->loadByProperties(['status' => TRUE]);
    $providers = array_filter($providers, static fn ($p) => $p instanceof AdProvider && ($preview || $p->isApproved()));
    uasort($providers, static fn (AdProvider $a, AdProvider $b) => $a->getWeight() <=> $b->getWeight());
    return $providers;
  }

  /**
   * A format by id.
   */
  public function format(string $id): ?AdFormat {
    $format = $id !== '' ? $this->entityTypeManager->getStorage('ad_format')->load($id) : NULL;
    return $format instanceof AdFormat ? $format : NULL;
  }

}
