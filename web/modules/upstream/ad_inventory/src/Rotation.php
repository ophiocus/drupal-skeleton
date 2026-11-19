<?php

declare(strict_types=1);

namespace Drupal\ad_inventory;

use Drupal\ad_inventory\Entity\AdBooking;
use Drupal\ad_inventory\Entity\AdCreative;
use Drupal\ad_inventory\Entity\AdProvider;
use Drupal\ad_inventory\Entity\AdSlot;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Which placement a space shows next, and which counts are real.
 *
 * The page is the same for every reader and carries the whole roster; the
 * browser asks here which one goes next. Outer rotation: of the placements
 * in force in the space, the one with the fewest views this week (Monday to
 * Sunday, the site's days), so placements that started together end the
 * week with even shares. Inner rotation: of that placement's creatives, the
 * one with the fewest views this week in the space. Ties go to the earliest
 * booking and, inside a booking, to the client's order.
 *
 * Views arrive when a reader leaves a page, so readers in the same minute may
 * get the same placement; the shares even out over the day.
 */
final class Rotation {

  /**
   * Bookings considered per space in one question.
   */
  public const MAX_BOOKINGS = 16;

  /**
   * User agents that never count (crawlers, link previews, scanners).
   */
  private const ROBOTS = '/bot|crawl|spider|slurp|preview|scan|monitor|lighthouse|headless|phantom|facebookexternalhit|embedly|whatsapp|telegram|curl|wget|python|java\/|go-http|okhttp|axios|node-fetch|libwww|httpclient|googleother|inspectiontool|apis-google|feedfetcher|mediapartners/i';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly SiteDay $siteDay,
    private readonly AdCounter $counter,
  ) {}

  /**
   * The placement and creative a space shows next.
   *
   * @param string $slot_id
   *   Space id.
   * @param int[] $booking_ids
   *   The bookings the page printed for the space.
   *
   * @return array{0: int, 1: int}|null
   *   Booking id and creative id, or NULL when none is in force.
   */
  public function pick(string $slot_id, array $booking_ids): ?array {
    $placements = $this->inForce($slot_id, $booking_ids);
    if ($placements === []) {
      return NULL;
    }
    $views = $this->counter->weekViews($slot_id, array_keys($placements));
    $best = NULL;
    foreach ($placements as $id => $creatives) {
      $total = array_sum($views[$id] ?? []);
      if ($best === NULL || $total < $best[0] || ($total === $best[0] && $id < $best[1])) {
        $best = [$total, $id];
      }
    }
    $booking = $best[1];
    $creative = NULL;
    foreach ($placements[$booking] as $id) {
      $seen = $views[$booking][$id] ?? 0;
      if ($creative === NULL || $seen < $creative[0]) {
        $creative = [$seen, $id];
      }
    }
    return [$booking, $creative[1]];
  }

  /**
   * Approved bookings in force in a space, with their approved creatives.
   *
   * @param string $slot_id
   *   Space id.
   * @param int[] $booking_ids
   *   Candidate booking ids.
   *
   * @return array<int, int[]>
   *   Booking id → creative ids in the client's order; earliest booking first.
   */
  private function inForce(string $slot_id, array $booking_ids): array {
    $ids = array_slice(array_values(array_unique(array_filter(array_map('intval', $booking_ids), static fn (int $id): bool => $id > 0))), 0, self::MAX_BOOKINGS);
    if ($ids === []) {
      return [];
    }
    $today = $this->siteDay->today();
    $placements = [];
    foreach ($this->entityTypeManager->getStorage('ad_booking')->loadMultiple($ids) as $booking) {
      if (!$booking instanceof AdBooking || $booking->state() !== AdBooking::APPROVED || !$booking->covers($today) || !in_array($slot_id, $booking->slots(), TRUE)) {
        continue;
      }
      $creatives = array_values(array_map(
        static fn (AdCreative $c): int => (int) $c->id(),
        array_filter($booking->creatives(), static fn (AdCreative $c): bool => $c->isApproved()),
      ));
      if ($creatives !== []) {
        $placements[(int) $booking->id()] = $creatives;
      }
    }
    ksort($placements);
    return $placements;
  }

  /**
   * Whether a view or click is one the space could really have shown.
   *
   * A booking's source must name a booking that holds the space and the
   * creative, approved and in force (today, or yesterday for a page read
   * across midnight); a house banner must be a house banner of the space; a
   * provider's unit (creative 0) an enabled provider covering the space. The
   * plain "booking" source of pages cached before per-booking counts counts
   * when some booking in force there holds the creative.
   */
  public function countable(string $slot_id, int $creative_id, string $source): bool {
    if ($slot_id === '' || preg_match(AdCounter::SOURCE, $source) !== 1 || $source === 'email') {
      return FALSE;
    }
    $slot = $this->entityTypeManager->getStorage('ad_slot')->load($slot_id);
    if (!$slot instanceof AdSlot) {
      return FALSE;
    }
    if (str_starts_with($source, 'provider:')) {
      $provider = $this->entityTypeManager->getStorage('ad_provider')->load(substr($source, 9));
      return $creative_id === 0 && $provider instanceof AdProvider && $provider->status() && $provider->covers($slot_id);
    }
    if ($creative_id <= 0) {
      return FALSE;
    }
    $creative = $this->entityTypeManager->getStorage('ad_creative')->load($creative_id);
    if (!$creative instanceof AdCreative) {
      return FALSE;
    }
    if ($source === 'house') {
      return $creative->isHouse() && $creative->housesIn($slot_id);
    }
    $booking_id = AdCounter::sourceBooking($source);
    $storage = $this->entityTypeManager->getStorage('ad_booking');
    if ($booking_id !== NULL) {
      $candidates = [$storage->load($booking_id)];
    }
    else {
      $query = $storage->getQuery()->accessCheck(FALSE)->condition('state', AdBooking::APPROVED);
      $candidates = $storage->loadMultiple(AdBooking::whereCreative($query, $creative_id)->execute());
    }
    $today = $this->siteDay->today();
    $yesterday = (new \DateTimeImmutable($today))->modify('-1 day')->format('Y-m-d');
    foreach ($candidates as $booking) {
      if ($booking instanceof AdBooking
        && $booking->state() === AdBooking::APPROVED
        && ($booking->covers($today) || $booking->covers($yesterday))
        && in_array($slot_id, $booking->slots(), TRUE)
        && in_array($creative_id, $booking->creativeIds(), TRUE)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Whether a request comes from a robot (no user agent counts as one).
   */
  public static function isRobot(string $user_agent): bool {
    return trim($user_agent) === '' || preg_match(self::ROBOTS, $user_agent) === 1;
  }

}
