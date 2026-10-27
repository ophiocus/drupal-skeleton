<?php

declare(strict_types=1);

namespace Drupal\ad_inventory;

use Drupal\ad_inventory\Entity\AdBookingInterface;
use Drupal\ad_inventory\Event\AdCreativeValidateEvent;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Whether a booking may run: its own checks, then the site's subscribers.
 */
final class Approval {

  public function __construct(
    private readonly Inventory $inventory,
    private readonly EventDispatcherInterface $eventDispatcher,
  ) {}

  /**
   * What stands in a booking's way.
   *
   * @param \Drupal\ad_inventory\Entity\AdBookingInterface $booking
   *   The booking.
   * @param string $phase
   *   When: "save" or "daily".
   *
   * @return array{refuse: string[], stop: string[]}
   *   Reasons it may not be approved, and reasons it must stop.
   */
  public function problems(AdBookingInterface $booking, string $phase): array {
    $refuse = [];
    $stop = [];
    $creative = $booking->creative();
    if ($creative === NULL) {
      return ['refuse' => ['The booking has no creative.'], 'stop' => []];
    }
    if (!$creative->isPublished()) {
      $refuse[] = 'The creative is not active.';
    }
    if (!Calendar::isDay($booking->start()) || !Calendar::isDay($booking->end())) {
      $refuse[] = 'The booking needs a first and a last day.';
    }
    elseif ($booking->end() < $booking->start()) {
      $refuse[] = 'The last day is before the first.';
    }
    $slots = $this->inventory->expandSlots($booking->slotValues());
    if (!$slots) {
      $refuse[] = 'The booking names no slot.';
    }
    foreach ($slots as $id) {
      $slot = $this->inventory->slot($id);
      if ($slot === NULL) {
        $refuse[] = sprintf('The slot "%s" does not exist.', $id);
        continue;
      }
      if (!$slot->allows('booking')) {
        $refuse[] = sprintf('The slot %s takes no bookings.', $slot->label());
      }
      if ($slot->bookingKinds() && !in_array($creative->kind(), $slot->bookingKinds(), TRUE)) {
        $refuse[] = sprintf('The slot %s takes no "%s" creatives.', $slot->label(), $creative->kind());
      }
    }
    $refuse = array_merge($refuse, $this->inventory->renditionProblems($creative, $this->inventory->neededFormats($booking)));

    $event = new AdCreativeValidateEvent($booking, $creative, $phase);
    $this->eventDispatcher->dispatch($event, AdCreativeValidateEvent::NAME);
    $refuse = array_merge($refuse, $event->refusals());
    $stop = array_merge($stop, $event->stops());
    return ['refuse' => $refuse, 'stop' => $stop];
  }

  /**
   * Applies the checks to an approved booking, without saving it.
   *
   * A stop stops it. A refusal sends it back to draft at a save, and stops
   * it at a daily run (it had been running). Either way the reasons are
   * recorded on the booking.
   *
   * @return bool
   *   Whether the booking is still approved.
   */
  public function enforce(AdBookingInterface $booking, string $phase): bool {
    if ($booking->state() !== 'approved') {
      return FALSE;
    }
    $problems = $this->problems($booking, $phase);
    if ($problems['stop']) {
      $booking->setState('stopped')->setReason(implode(' ', $problems['stop']));
      return FALSE;
    }
    if ($problems['refuse']) {
      $booking->setState($phase === 'daily' ? 'stopped' : 'draft')->setReason(implode(' ', $problems['refuse']));
      return FALSE;
    }
    $booking->setReason('');
    return TRUE;
  }

}
