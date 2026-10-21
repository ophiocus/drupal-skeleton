<?php

declare(strict_types=1);

namespace Drupal\edge_purge\EventSubscriber;

use Drupal\Core\Routing\RoutingEvents;
use Drupal\edge_purge\Cache\PurgeQueueInvalidator;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Holds the purge queue for the length of a router rebuild.
 *
 * The rebuild's own subscribers (menu links, the route caches) invalidate
 * tags that sit on every page; none of them is a change a reader can see.
 * First on STATIC, the rebuild's first event; last on FINISHED, its last.
 */
final class RouterRebuildHold implements EventSubscriberInterface {

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      RoutingEvents::STATIC => ['hold', 10000],
      RoutingEvents::FINISHED => ['release', -10000],
    ];
  }

  /**
   * The rebuild started.
   */
  public function hold(): void {
    PurgeQueueInvalidator::hold();
  }

  /**
   * The rebuild finished.
   */
  public function release(): void {
    PurgeQueueInvalidator::release();
  }

}
