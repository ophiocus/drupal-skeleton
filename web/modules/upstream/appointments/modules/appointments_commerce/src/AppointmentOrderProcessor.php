<?php

declare(strict_types=1);

namespace Drupal\appointments_commerce;

use Drupal\appointments\BookingManager;
use Drupal\appointments\Entity\AppointmentInterface;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\OrderProcessorInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;

/**
 * Keeps appointment order items honest while the order is a cart.
 *
 * One appointment is one item of quantity 1. An item whose appointment was
 * cancelled, or whose hold ran out while its time was taken, leaves the cart;
 * a hold that ran out on a time still free is renewed.
 */
final class AppointmentOrderProcessor implements OrderProcessorInterface {

  use StringTranslationTrait;

  public function __construct(
    private readonly BookingManager $booking,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly MessengerInterface $messenger,
    TranslationInterface $string_translation,
  ) {
    $this->setStringTranslation($string_translation);
  }

  /**
   * {@inheritdoc}
   */
  public function process(OrderInterface $order): void {
    if ($order->getState()->getId() !== 'draft') {
      return;
    }
    $storage = $this->entityTypeManager->getStorage('appointment');
    foreach ($order->getItems() as $item) {
      if ($item->bundle() !== CommerceCheckout::ORDER_ITEM_TYPE) {
        continue;
      }
      if ((float) $item->getQuantity() !== 1.0) {
        $item->setQuantity('1');
      }
      $id = (int) $item->getData('appointment_id');
      $appointment = $id ? $storage->load($id) : NULL;
      if ($appointment instanceof AppointmentInterface && $this->booking->renewHold($appointment)) {
        continue;
      }
      if ($appointment instanceof AppointmentInterface && $appointment->getStatus() === AppointmentInterface::PENDING) {
        $this->booking->cancel($appointment, 'silent');
      }
      $order->removeItem($item);
      $item->delete();
      $this->messenger->addWarning($this->t('%item was removed from your cart: the time is no longer held. Please book again.', ['%item' => $item->label()]));
    }
  }

}
