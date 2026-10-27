<?php

declare(strict_types=1);

namespace Drupal\ad_inventory;

use Drupal\ad_inventory\Entity\AdBookingInterface;
use Drupal\ad_inventory\Entity\AdSlot;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\State\StateInterface;
use Psr\Log\LoggerInterface;

/**
 * The daily run, from cron: bookings change without a deploy.
 *
 * When the day changes it invalidates the fill tag of every slot whose
 * booking started or ended since the last run (pages and the email image
 * then render the new day's creative; edge_purge, where installed, purges the
 * edge by the same tag) and re-validates every approved booking that is not
 * over: a stopped booking leaves the pages at their next render.
 */
final class Scheduler {

  /**
   * The state key of the last day run.
   */
  public const STATE_DAY = 'ad_inventory.day';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly Inventory $inventory,
    private readonly Approval $approval,
    private readonly Calendar $calendar,
    private readonly StateInterface $state,
    private readonly CacheTagsInvalidatorInterface $invalidator,
    private readonly Placements $placements,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Runs once per cron; does the day's work once per day.
   *
   * @return array{day: string, ran: bool, slots: string[], stopped: int[]}
   *   What happened.
   */
  public function run(): array {
    $today = $this->calendar->today();
    $last = $this->state->get(self::STATE_DAY);
    $report = ['day' => $today, 'ran' => FALSE, 'slots' => [], 'stopped' => []];
    if ($last !== $today) {
      $report = $this->day($today, is_string($last) ? $last : NULL);
      $this->state->set(self::STATE_DAY, $today);
    }
    $this->placements->prune();
    return $report;
  }

  /**
   * The day's work.
   *
   * @param string $today
   *   Y-m-d.
   * @param string|null $last
   *   The last day run, or NULL on the first run.
   */
  public function day(string $today, ?string $last): array {
    $storage = $this->entityTypeManager->getStorage('ad_booking');
    $query = $storage->getQuery()->accessCheck(FALSE)->condition('state', 'approved');
    if ($last !== NULL && Calendar::isDay($last) && $last < $today) {
      $started = $query->andConditionGroup()
        ->condition('start_day', $last, '>')
        ->condition('start_day', $today, '<=');
      $ended = $query->andConditionGroup()
        ->condition('end_day', $last, '>=')
        ->condition('end_day', $today, '<');
      $query->condition($query->orConditionGroup()->condition($started)->condition($ended));
    }
    $values = [];
    foreach ($storage->loadMultiple($query->execute()) as $booking) {
      if ($booking instanceof AdBookingInterface) {
        $values = array_merge($values, $booking->slotValues());
      }
    }

    $stopped = [];
    $running = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('state', 'approved')
      ->condition('end_day', $today, '>=')
      ->execute();
    foreach ($storage->loadMultiple($running) as $booking) {
      if ($booking instanceof AdBookingInterface && !$this->approval->enforce($booking, 'daily')) {
        // Saving invalidates its slots.
        $booking->save();
        $stopped[] = (int) $booking->id();
        $this->logger->notice('Ad booking @id stopped: @reason', [
          '@id' => $booking->id(),
          '@reason' => $booking->reason(),
        ]);
      }
    }

    $slots = $this->inventory->expandSlots($values);
    if ($slots) {
      $this->invalidator->invalidateTags(array_map([AdSlot::class, 'fillTag'], $slots));
    }
    return ['day' => $today, 'ran' => TRUE, 'slots' => $slots, 'stopped' => $stopped];
  }

}
