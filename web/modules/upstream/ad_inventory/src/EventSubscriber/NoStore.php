<?php

declare(strict_types=1);

namespace Drupal\ad_inventory\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Marks the counting routes "no-store", after core set its own headers.
 *
 * Core gives an uncacheable response "no-cache, private"; a click redirect or
 * a beacon answer must also never be kept by a browser or a proxy.
 */
final class NoStore implements EventSubscriberInterface {

  /**
   * The routes.
   */
  public const ROUTES = [
    'ad_inventory.beacon',
    'ad_inventory.click',
    'ad_inventory.email_go',
    'ad_inventory.booking_report_csv',
  ];

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // After FinishResponseSubscriber (priority 0).
    return [KernelEvents::RESPONSE => ['onResponse', -100]];
  }

  /**
   * Adds no-store.
   */
  public function onResponse(ResponseEvent $event): void {
    if (in_array($event->getRequest()->attributes->get('_route'), self::ROUTES, TRUE)) {
      $event->getResponse()->headers->addCacheControlDirective('no-store');
    }
  }

}
