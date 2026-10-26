<?php

declare(strict_types=1);

namespace Drupal\Tests\appointments_commerce\Kernel;

use Drupal\appointments\Entity\AppointmentInterface;
use Drupal\appointments\Entity\AppointmentServiceInterface;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Tests\appointments\Kernel\FrozenTime;
use Drupal\Tests\commerce_cart\Kernel\CartKernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Paid bookings: the cart, the checkout, and what placing the order does.
 */
#[Group('appointments')]
#[RunTestsInSeparateProcesses]
final class AppointmentsCommerceTest extends CartKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'field',
    'appointments',
    'appointments_commerce',
  ];

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    $container->register('datetime.time', FrozenTime::class);
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    // The test's clock from before Drupal starts, never rewound past it (see
    // AppointmentsKernelTestBase::setUp()).
    FrozenTime::$now = $this->ts('2026-10-26 06:00');
    parent::setUp();
    $this->installEntitySchema('appointment_service');
    $this->installEntitySchema('appointment');
    $this->installConfig(['appointments', 'appointments_commerce']);
    $this->container->get('router.builder')->rebuild();
    $this->config('system.mail')->set('interface.default', 'test_mail_collector')->save();
    $this->config('system.site')->set('mail', 'clinic@example.com')->save();
    $this->config('appointments.settings')
      ->set('timezone', 'America/Bogota')
      ->set('currency', 'USD')
      ->set('slot_step', 30)
      ->set('lead_time', 120)
      ->set('hold', 30)
      ->set('hours', [['day' => 1, 'start' => '08:00', 'end' => '12:00']])
      ->set('payment', ['mode' => 'full', 'deposit_type' => 'percent', 'deposit_value' => '30'])
      ->save();
    FrozenTime::$now = $this->ts('2026-10-26 06:00');
    $this->container->get('current_user')->setAccount($this->createUser());
  }

  /**
   * A Bogota wall-clock time as a timestamp.
   */
  private function ts(string $when): int {
    return (new \DateTimeImmutable($when, new \DateTimeZone('America/Bogota')))->getTimestamp();
  }

  /**
   * A service at a price.
   */
  private function service(string $price): AppointmentServiceInterface {
    $service = $this->entityTypeManager->getStorage('appointment_service')->create([
      'key' => 'consult',
      'label' => 'Consult',
      'duration' => 30,
      'price' => $price,
      'status' => TRUE,
    ]);
    $service->save();
    assert($service instanceof AppointmentServiceInterface);
    return $service;
  }

  /**
   * Books 09:00 through the booking manager.
   */
  private function book(AppointmentServiceInterface $service): AppointmentInterface {
    $appointment = $this->entityTypeManager->getStorage('appointment')->create([
      'service' => $service->id(),
      'start' => $this->ts('2026-10-26 09:00'),
      'name' => 'Ana',
      'email' => 'ana@example.com',
      'phone' => '3001234567',
    ]);
    assert($appointment instanceof AppointmentInterface);
    $url = $this->container->get('appointments.booking')->book($appointment);
    $this->assertNotNull($url);
    $this->assertSame('commerce_checkout.form', $url->getRouteName());
    return $appointment;
  }

  /**
   * The client's cart.
   */
  private function cart(): OrderInterface {
    $cart = $this->cartProvider->getCart('default', $this->store);
    $this->assertNotNull($cart);
    return $this->reloadEntity($cart);
  }

  /**
   * Reloads an appointment from storage.
   */
  private function reload(AppointmentInterface $appointment): AppointmentInterface {
    $fresh = $this->entityTypeManager->getStorage('appointment')->loadUnchanged($appointment->id());
    assert($fresh instanceof AppointmentInterface);
    return $fresh;
  }

  /**
   * Full payment: the price goes to the cart; placing confirms.
   */
  public function testFullPaymentConfirmsOnPlace(): void {
    $appointment = $this->book($this->service('50'));
    $this->assertSame(AppointmentInterface::PENDING, $appointment->getStatus());
    $this->assertSame('full', $appointment->get('payment')->value);
    $this->assertSame([], $this->container->get('state')->get('system.test_mail_collector', []));

    $cart = $this->cart();
    $items = $cart->getItems();
    $this->assertCount(1, $items);
    $item = reset($items);
    $this->assertSame('appointment', $item->bundle());
    $this->assertEquals(50, (float) $item->getUnitPrice()->getNumber());
    $this->assertSame('USD', $item->getUnitPrice()->getCurrencyCode());
    $this->assertTrue($item->isUnitPriceOverridden());
    $this->assertSame((int) $appointment->id(), $item->getData('appointment_id'));

    $cart->getState()->applyTransitionById('place');
    $cart->save();
    $appointment = $this->reload($appointment);
    $this->assertSame(AppointmentInterface::CONFIRMED, $appointment->getStatus());
    $this->assertStringStartsWith('order:', (string) $appointment->get('reference')->value);
    $ids = array_column($this->container->get('state')->get('system.test_mail_collector', []), 'id');
    $this->assertContains('appointments_confirmed.client', $ids);
  }

  /**
   * Deposits: a percentage of the price, or a fixed amount capped at it.
   */
  public function testDeposit(): void {
    $settings = $this->config('appointments.settings');
    $settings->set('payment', ['mode' => 'deposit', 'deposit_type' => 'percent', 'deposit_value' => '30'])->save();
    $booking = $this->container->get('appointments.booking');
    $service = $this->service('50');
    $this->assertSame('15.00', $booking->amountDue($service));
    $settings->set('payment', ['mode' => 'deposit', 'deposit_type' => 'fixed', 'deposit_value' => '80'])->save();
    $this->assertSame('50.00', $booking->amountDue($service));
    $settings->set('payment', ['mode' => 'deposit', 'deposit_type' => 'fixed', 'deposit_value' => '10'])->save();
    $appointment = $this->book($service);
    $this->assertSame('deposit', $appointment->get('payment')->value);
    $items = $this->cart()->getItems();
    $this->assertEquals(10, (float) reset($items)->getUnitPrice()->getNumber());
  }

  /**
   * A free service is confirmed at once even in a paid mode.
   */
  public function testFreeServiceSkipsCheckout(): void {
    $appointment = $this->entityTypeManager->getStorage('appointment')->create([
      'service' => $this->service('0')->id(),
      'start' => $this->ts('2026-10-26 09:00'),
      'name' => 'Ana',
      'email' => 'ana@example.com',
      'phone' => '1',
    ]);
    assert($appointment instanceof AppointmentInterface);
    $this->assertNull($this->container->get('appointments.booking')->book($appointment));
    $this->assertSame(AppointmentInterface::CONFIRMED, $appointment->getStatus());
  }

  /**
   * Taking the appointment out of the cart frees its time.
   */
  public function testRemoveFromCartCancels(): void {
    $appointment = $this->book($this->service('50'));
    $cart = $this->cart();
    $items = $cart->getItems();
    $this->cartManager->removeOrderItem($cart, reset($items));
    $this->assertSame(AppointmentInterface::CANCELLED, $this->reload($appointment)->getStatus());
  }

  /**
   * A lapsed hold is renewed while its time is free, dropped once it is not.
   */
  public function testLapsedHold(): void {
    $service = $this->service('50');
    $appointment = $this->book($service);

    // Past the 30-minute hold, time still free: refreshing the cart renews it.
    FrozenTime::$now = $this->ts('2026-10-26 06:45');
    $cart = $this->cart();
    $cart->setRefreshState(OrderInterface::REFRESH_ON_SAVE);
    $cart->save();
    $appointment = $this->reload($appointment);
    $this->assertSame(AppointmentInterface::PENDING, $appointment->getStatus());
    $this->assertTrue($appointment->isHolding(FrozenTime::$now));
    $this->assertCount(1, $this->cart()->getItems());

    // Lapsed again, and someone else booked the time meanwhile.
    FrozenTime::$now = $this->ts('2026-10-26 07:30');
    $this->config('appointments.settings')->set('payment.mode', 'none')->set('lead_time', 0)->save();
    $other = $this->entityTypeManager->getStorage('appointment')->create([
      'service' => $service->id(),
      'start' => $this->ts('2026-10-26 09:00'),
      'name' => 'Bea',
      'email' => 'bea@example.com',
      'phone' => '2',
    ]);
    assert($other instanceof AppointmentInterface);
    $this->container->get('appointments.booking')->book($other);

    $cart = $this->cart();
    $cart->setRefreshState(OrderInterface::REFRESH_ON_SAVE);
    $cart->save();
    $this->assertCount(0, $this->cart()->getItems());
    $this->assertSame(AppointmentInterface::CANCELLED, $this->reload($appointment)->getStatus());
  }

}
