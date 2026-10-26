<?php

declare(strict_types=1);

namespace Drupal\appointments_commerce;

use Drupal\appointments\Calendar;
use Drupal\appointments\CheckoutHandlerInterface;
use Drupal\appointments\Entity\AppointmentInterface;
use Drupal\appointments\Messages;
use Drupal\commerce_cart\CartManagerInterface;
use Drupal\commerce_cart\CartProviderInterface;
use Drupal\commerce_order\Entity\OrderItem;
use Drupal\commerce_price\Price;
use Drupal\commerce_store\CurrentStoreInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Url;

/**
 * Puts a pending appointment in the cart and sends the client to checkout.
 *
 * The order item has no purchasable entity: its title and price are set here
 * and marked overridden, and its data carries the appointment id. Placing the
 * order confirms the appointment (OrderSubscriber).
 */
final class CommerceCheckout implements CheckoutHandlerInterface {

  /**
   * The order item type of appointments.
   */
  public const ORDER_ITEM_TYPE = 'appointment';

  public function __construct(
    private readonly CartProviderInterface $cartProvider,
    private readonly CartManagerInterface $cartManager,
    private readonly CurrentStoreInterface $currentStore,
    private readonly Messages $messages,
    private readonly Calendar $calendar,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function start(AppointmentInterface $appointment): Url {
    $store = $this->currentStore->getStore() ?? $this->entityTypeManager->getStorage('commerce_store')->loadDefault();
    if (!$store) {
      throw new \RuntimeException('Online payment for appointments needs a Commerce store, and there is none.');
    }
    $cart = $this->cartProvider->getCart('default', $store) ?? $this->cartProvider->createCart('default', $store);
    $service = $appointment->getService();
    $currency = (string) ($this->calendar->settings()->get('currency') ?: $store->getDefaultCurrencyCode());

    $item = OrderItem::create([
      'type' => self::ORDER_ITEM_TYPE,
      'quantity' => '1',
    ]);
    $item->setTitle(($service ? $service->label() : 'Appointment') . ' · ' . $this->messages->when($appointment));
    $item->setUnitPrice(new Price((string) $appointment->get('amount')->value, $currency), TRUE);
    $item->setData('appointment_id', (int) $appointment->id());
    $this->cartManager->addOrderItem($cart, $item, FALSE);

    $appointment->set('reference', 'order:' . $cart->id())->save();
    return Url::fromRoute('commerce_checkout.form', ['commerce_order' => $cart->id()]);
  }

}
