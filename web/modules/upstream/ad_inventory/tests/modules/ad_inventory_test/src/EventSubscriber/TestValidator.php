<?php

declare(strict_types=1);

namespace Drupal\ad_inventory_test\EventSubscriber;

use Drupal\ad_inventory\Event\AdCreativeValidateEvent;
use Drupal\Core\State\StateInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Refuses or stops bookings as the test sets in state.
 *
 * State "ad_inventory_test.refuse" or "ad_inventory_test.stop": a reason,
 * applied to every booking, at the phase in "ad_inventory_test.phase" (any
 * phase when unset). This is how a site would check, say, a registration
 * code against a register.
 */
final class TestValidator implements EventSubscriberInterface {

  public function __construct(
    private readonly StateInterface $state,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [AdCreativeValidateEvent::NAME => 'validate'];
  }

  /**
   * Applies the state's verdict.
   */
  public function validate(AdCreativeValidateEvent $event): void {
    $phase = $this->state->get('ad_inventory_test.phase');
    if ($phase !== NULL && $phase !== $event->phase) {
      return;
    }
    if ($reason = $this->state->get('ad_inventory_test.refuse')) {
      $event->refuse((string) $reason);
    }
    if ($reason = $this->state->get('ad_inventory_test.stop')) {
      $event->stop((string) $reason);
    }
  }

}
