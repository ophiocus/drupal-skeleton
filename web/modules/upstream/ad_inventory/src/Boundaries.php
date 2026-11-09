<?php

declare(strict_types=1);

namespace Drupal\ad_inventory;

use Drupal\ad_inventory\Entity\AdBooking;
use Drupal\ad_inventory\Entity\AdSlot;
use Drupal\ad_inventory\Event\AdBookingValidateEvent;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\State\StateInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * The daily turn: renews pages whose spaces change, checks running bookings.
 *
 * The page cache keeps a page until one of its tags is invalidated; a
 * booking that starts tomorrow therefore needs tomorrow's first cron run to
 * renew the pages of its spaces. That run also asks the site, through the
 * validate event, whether each running booking may go on.
 *
 * ONLY THE SPACES THAT CHANGE. A date alone changes nothing on a page: what
 * fills a space depends on the day only through a booking's first and last
 * day. A tag shared by every space, invalidated every day, emptied the edge
 * cache of every page with a space each midnight, on a site with no bookings
 * at all (oidoenvivo, 2026-11-09: 114 of 147 sampled pages had no other reason
 * to go).
 */
final class Boundaries {

  /**
   * State key of the last day handled.
   */
  public const STATE = 'ad_inventory.last_day';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly SiteDay $siteDay,
    private readonly StateInterface $state,
    private readonly CacheTagsInvalidatorInterface $invalidator,
    private readonly EventDispatcherInterface $dispatcher,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Runs once per site day; returns the bookings it stopped.
   *
   * @param bool $force
   *   Run even if today was handled, and renew the spaces of every booking
   *   that is not stopped, whatever its dates.
   *
   * @return int[]
   *   Ids of stopped bookings.
   */
  public function run(bool $force = FALSE): array {
    $today = $this->siteDay->today();
    $last = (string) $this->state->get(self::STATE, '');
    if (!$force && $last === $today) {
      return [];
    }
    $stopped = [];
    $tags = [];
    $storage = $this->entityTypeManager->getStorage('ad_booking');
    // Drafts count: staff who preview them see them on their dates.
    $ids = $storage->getQuery()->accessCheck(FALSE)->condition('state', AdBooking::STOPPED, '<>')->execute();
    foreach ($storage->loadMultiple($ids) as $booking) {
      if (!$booking instanceof AdBooking) {
        continue;
      }
      if ($force || self::turned($booking, $last, $today)) {
        foreach ($booking->slots() as $slot) {
          $tags[] = AdSlot::tag($slot);
        }
      }
      if ($booking->state() !== AdBooking::APPROVED || $booking->endDay() < $today) {
        continue;
      }
      $event = new AdBookingValidateEvent($booking, AdBookingValidateEvent::DAILY);
      $this->dispatcher->dispatch($event, AdBookingValidateEvent::NAME);
      if ($event->stopReason() !== '') {
        $booking->stop($event->stopReason())->save();
        $stopped[] = (int) $booking->id();
        $this->logger->notice('Booking @id stopped: @reason', [
          '@id' => $booking->id(),
          '@reason' => $event->stopReason(),
        ]);
      }
    }
    if ($tags !== []) {
      $this->invalidator->invalidateTags(array_values(array_unique($tags)));
    }
    $this->state->set(self::STATE, $today);
    return $stopped;
  }

  /**
   * Whether a booking began or left since the last day handled.
   *
   * It began on its start day and left on the day after its end day. Cron may
   * have skipped days, so this looks at all of them. With no last day on
   * record, nothing can be ruled out.
   */
  private static function turned(AdBooking $booking, string $last, string $today): bool {
    if ($last === '') {
      return TRUE;
    }
    $start = $booking->startDay();
    $end = $booking->endDay();
    return ($last < $start && $start <= $today) || ($last <= $end && $end < $today);
  }

}
