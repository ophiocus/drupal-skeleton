<?php

declare(strict_types=1);

namespace Drupal\appointments\EventSubscriber;

use Drupal\appointments\Freshness;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\PageCache\ResponsePolicy\KillSwitch;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Gives the booking page the lifetime of the free times it shows.
 *
 * Runs after core's FinishResponseSubscriber (0), which made the page public
 * for the site's page max-age (or private where that is 0), and before
 * edge_purge's CacheTagHeader (-1024), which then keeps the CDN lifetime set
 * here instead of its own.
 */
final class FreshHeaders implements EventSubscriberInterface {

  public function __construct(
    private readonly TimeInterface $time,
    private readonly KillSwitch $killSwitch,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [KernelEvents::RESPONSE => ['onResponse', -10]];
  }

  /**
   * Turns the page's deadline into its cache headers.
   */
  public function onResponse(ResponseEvent $event): void {
    if (!$event->isMainRequest() || !$event->getRequest()->isMethodCacheable()) {
      return;
    }
    if (Freshness::apply($event->getResponse(), $this->time->getCurrentTime()) === 0) {
      // Not even Drupal's page cache may keep it (an appointment being moved).
      $this->killSwitch->trigger();
    }
  }

}
