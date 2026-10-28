<?php

declare(strict_types=1);

namespace Drupal\ad_inventory;

use Drupal\ad_inventory\Entity\AdBooking;
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
    $storage = $this->entityTypeManager->getStorage('ad_booking');
    $ids = $storage->getQuery()->accessCheck(FALSE)->condition('state', AdBooking::APPROVED)->execute();
    foreach ($storage->loadMultiple($ids) as $booking) {
      if (!$booking instanceof AdBooking || $booking->endDay() < $today) {
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
    // Every page with a space depends on this tag: bookings that began or
    // ended since the last run show or leave at the next render.
    $this->invalidator->invalidateTags([AdResolver::DAY_TAG]);
    $this->state->set(self::STATE, $today);
    return $stopped;
  }

}
