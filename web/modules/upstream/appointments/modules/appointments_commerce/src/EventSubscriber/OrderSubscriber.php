<?php

declare(strict_types=1);

namespace Drupal\appointments_commerce\EventSubscriber;

use Drupal\appointments\BookingManager;
use Drupal\appointments\Entity\AppointmentInterface;
use Drupal\appointments_commerce\CommerceCheckout;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\state_machine\Event\WorkflowTransitionEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Confirms appointments when their order is placed; cancels them with it.
 *
 * With the shop's manual gateways (bank transfer, cash) "placed" means the
 * client committed to pay, not that the money arrived. That is the moment a
 * paid booking is confirmed; the staff check the payment on the order.
 */
final class OrderSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly BookingManager $booking,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      'commerce_order.place.post_transition' => ['onPlace', -50],
      'commerce_order.cancel.post_transition' => ['onCancel', 0],
    ];
  }

  /**
   * Confirms the order's appointments.
   */
  public function onPlace(WorkflowTransitionEvent $event): void {
    $order = $event->getEntity();
    assert($order instanceof OrderInterface);
    $reference = 'order:' . ($order->getOrderNumber() ?: $order->id());
    foreach ($this->appointments($order) as $appointment) {
      $this->booking->confirm($appointment, $reference);
    }
  }

  /**
   * Cancels the order's appointments.
   */
  public function onCancel(WorkflowTransitionEvent $event): void {
    $order = $event->getEntity();
    assert($order instanceof OrderInterface);
    foreach ($this->appointments($order) as $appointment) {
      $this->booking->cancel($appointment, 'staff');
    }
  }

  /**
   * The appointments an order pays for.
   *
   * @return list<\Drupal\appointments\Entity\AppointmentInterface>
   *   The appointments.
   */
  private function appointments(OrderInterface $order): array {
    $ids = [];
    foreach ($order->getItems() as $item) {
      if ($item->bundle() === CommerceCheckout::ORDER_ITEM_TYPE && $item->getData('appointment_id')) {
        $ids[] = (int) $item->getData('appointment_id');
      }
    }
    if (!$ids) {
      return [];
    }
    $appointments = $this->entityTypeManager->getStorage('appointment')->loadMultiple($ids);
    return array_values(array_filter($appointments, static fn ($a) => $a instanceof AppointmentInterface));
  }

}
