<?php

declare(strict_types=1);

namespace Drupal\shop_delivery\EventSubscriber;

use Drupal\commerce_shipping\Entity\ShipmentInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\shop_delivery\Deliveries;
use Drupal\state_machine\Event\WorkflowTransitionEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * What a delivery's transitions do beyond changing its state.
 *
 * - Delivered: stamps the time, records the cash to collect when the
 *   courier did not say otherwise, and completes the order once every
 *   delivery of it is done.
 * - Released back to the board: the courier is unassigned.
 */
final class ShipmentSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly Deliveries $deliveries,
    private readonly TimeInterface $time,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      'commerce_shipment.entregar.pre_transition' => ['onDeliver'],
      'commerce_shipment.entregar.post_transition' => ['onDelivered'],
      'commerce_shipment.liberar.pre_transition' => ['onRelease'],
    ];
  }

  /**
   * Stamps the delivery and what was collected.
   */
  public function onDeliver(WorkflowTransitionEvent $event): void {
    $shipment = $event->getEntity();
    if (!$shipment instanceof ShipmentInterface) {
      return;
    }
    $shipment->set('field_entregado', $this->time->getRequestTime());
    $order = $shipment->getOrder();
    if ($order && $shipment->get('field_recaudado')->isEmpty()
      && $this->deliveries->method($shipment)?->settlesCash()) {
      $balance = $this->deliveries->balance($order);
      if ($balance) {
        $shipment->set('field_recaudado', $balance);
      }
    }
  }

  /**
   * Completes the order when its last delivery is done.
   */
  public function onDelivered(WorkflowTransitionEvent $event): void {
    $shipment = $event->getEntity();
    $order = $shipment instanceof ShipmentInterface ? $shipment->getOrder() : NULL;
    if (!$order || !$order->getState()->isTransitionAllowed('fulfill')) {
      return;
    }
    foreach ($this->deliveries->ofOrder($order) as $other) {
      $state = (int) $other->id() === (int) $shipment->id() ? 'entregado' : $other->getState()->getId();
      if (!in_array($state, ['entregado', 'canceled'], TRUE)) {
        return;
      }
    }
    $order->getState()->applyTransitionById('fulfill');
    $order->save();
  }

  /**
   * Unassigns the courier of a delivery sent back to the board.
   */
  public function onRelease(WorkflowTransitionEvent $event): void {
    $shipment = $event->getEntity();
    if ($shipment instanceof ShipmentInterface) {
      $shipment->set('field_repartidor', NULL);
    }
  }

}
