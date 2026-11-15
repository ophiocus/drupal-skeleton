<?php

declare(strict_types=1);

namespace Drupal\ad_inventory;

use Drupal\ad_inventory\Coverage\CoverageStore;
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
 * In order: the bookings in force for the space and the page's scope (one
 * exclusive booking alone, or every booking that shares the space, each with
 * its creatives: the browser asks the site which goes next, see Rotation),
 * then the providers in their order, then the house banners (the browser
 * shows one of them at random). With none of them approved the space is not
 * printed at all. Staff who preview drafts also get drafts, which is a
 * role-based difference: anonymous readers all share one page.
 *
 * Cache: every page carrying a space depends on the space's tag (bookings,
 * creatives and providers invalidate it; so does cron, when a booking of the
 * space starts or ends: see Boundaries) and on the page's own settings.
 */
final class AdResolver {

  /**
   * House banners printed per space for the browser's random pick.
   */
  public const HOUSE_POOL = 8;

  /**
   * Placements printed per space at most (a site caps its sales lower).
   */
  public const ROSTER_MAX = 12;

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
    private readonly ?CoverageStore $coverage = NULL,
  ) {}

  /**
   * What fills a space on a page, or NULL.
   */
  public function resolve(AdSlot $slot, AdScope $scope, AccountInterface $account, CacheableMetadata $cacheability): ?Fill {
    // The space's own tag, not the booking and creative lists: a booking
    // renews the spaces it holds, a creative the spaces of its bookings, and
    // the day's turn the spaces whose bookings began or ended. A save in one
    // space no longer empties every page that has a space.
    $cacheability
      ->addCacheTags([
        AdSlot::tag((string) $slot->id()),
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
      $placements = $this->placements($slot, $scope, $preview, $formats);
      if ($placements !== []) {
        $draft = FALSE;
        foreach ($placements as $placement) {
          $cacheability->addCacheableDependency($placement->booking);
          $draft = $draft || $placement->booking->state() !== AdBooking::APPROVED;
          foreach ($placement->creatives as $creative) {
            $cacheability->addCacheableDependency($creative);
            $draft = $draft || !$creative->isApproved();
          }
        }
        $first = $placements[0];
        return new Fill(Fill::BOOKING, $desktop, $phone, $draft, $first->creatives[0], $first->booking, placements: $placements);
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
      // A new or changed house banner renews this page (and only pages that
      // got this far).
      $cacheability->addCacheTags([AdCreative::HOUSE_TAG]);
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
   * The placements a space shows on a page today: its roster.
   *
   * Every booking in force for the space and the page competes by rank: an
   * approved booking beats a draft, a scoped one beats one for every page.
   * At the winning rank an exclusive booking (a sponsorship) stands alone,
   * the earliest booked if there were ever two; otherwise every booking
   * there shares the space and the browser asks which goes next (outer
   * rotation, fewest views first). A site caps how many share a space.
   *
   * @param \Drupal\ad_inventory\Entity\AdSlot $slot
   *   The space.
   * @param \Drupal\ad_inventory\Scope\AdScope $scope
   *   What the page is about.
   * @param bool $preview
   *   Whether drafts count (staff previewing them).
   * @param string[] $formats
   *   Formats a creative must carry.
   *
   * @return \Drupal\ad_inventory\Placement[]
   *   Placements, earliest booked first; empty when none.
   */
  private function placements(AdSlot $slot, AdScope $scope, bool $preview, array $formats): array {
    $today = $this->siteDay->today();
    $ranked = [];
    foreach ($this->bookings() as $booking) {
      $state = $booking->state();
      if ($state === AdBooking::STOPPED || ($state === AdBooking::DRAFT && !$preview)) {
        continue;
      }
      if (!in_array((string) $slot->id(), $booking->slots(), TRUE) || !$booking->covers($today) || !$this->reaches($booking, $scope)) {
        continue;
      }
      $creatives = array_values(array_filter(
        $booking->creatives(),
        static fn (AdCreative $c): bool => ($preview || $c->isApproved()) && $slot->allowsKind($c->kind()) && $c->missing($formats) === [],
      ));
      if ($creatives === []) {
        continue;
      }
      $rank = ($state === AdBooking::APPROVED ? 0 : 2) + ($booking->scope() !== '' ? 0 : 1);
      $ranked[$rank][] = new Placement($booking, $creatives);
    }
    if ($ranked === []) {
      return [];
    }
    ksort($ranked);
    $placements = reset($ranked);
    usort($placements, static fn (Placement $a, Placement $b): int => (int) $a->booking->id() <=> (int) $b->booking->id());
    foreach ($placements as $placement) {
      if ($placement->booking->isExclusive()) {
        return [$placement];
      }
    }
    return array_slice($placements, 0, self::ROSTER_MAX);
  }

  /**
   * Whether a booking shows on a page: the pages it locked when approved.
   *
   * A booking with no stored coverage (a draft, or one approved before
   * coverage existed) reaches its whole scope.
   */
  private function reaches(AdBooking $booking, AdScope $scope): bool {
    $coverage = $booking->state() === AdBooking::APPROVED ? $this->coverage?->get($booking) : NULL;
    return $coverage !== NULL ? $coverage->matches($scope) : $scope->matches($booking->scope());
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
