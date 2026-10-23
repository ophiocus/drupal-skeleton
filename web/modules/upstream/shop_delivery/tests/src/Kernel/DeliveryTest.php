<?php

declare(strict_types=1);

namespace Drupal\Tests\shop_delivery\Kernel;

use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderItem;
use Drupal\commerce_order\Entity\OrderType;
use Drupal\commerce_price\Price;
use Drupal\commerce_shipping\Entity\Shipment;
use Drupal\commerce_shipping\Entity\ShipmentInterface;
use Drupal\commerce_shipping\Entity\ShippingMethod;
use Drupal\commerce_shipping\ShipmentItem;
use Drupal\shop_delivery\Form\CourierShipmentForm;
use Drupal\physical\Weight;
use Drupal\profile\Entity\Profile;
use Drupal\Tests\commerce_shipping\Kernel\ShippingKernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * A delivery from placing the order to settling the courier's cash.
 */
#[Group('shop_delivery')]
#[RunTestsInSeparateProcesses]
final class DeliveryTest extends ShippingKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'telephone',
    'maper',
    'commerce_checkout',
    'commerce_payment',
    'shop_delivery',
  ];

  /**
   * The store's point.
   */
  private const STORE = ['lat' => 4.6486, 'lon' => -74.0628];

  /**
   * The customer's door, about 1.03 km from the store.
   */
  private const DOOR = ['lat' => 4.6553125, 'lon' => -74.0567812];

  /**
   * The Domicilio shipping method.
   */
  private ShippingMethod $method;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['shop_delivery']);

    $order_type = OrderType::load('default');
    $order_type->setWorkflowId('order_fulfillment');
    $order_type->save();

    // The store was built before the module's fields existed.
    $this->store = $this->reloadEntity($this->store);
    $this->store->set('field_ubicacion', self::STORE)->save();
    $this->method = ShippingMethod::create([
      'name' => 'Domicilio',
      'stores' => [$this->store->id()],
      'plugin' => [
        'target_plugin_id' => 'shop_domicilio',
        'target_plugin_configuration' => [
          'fee_mode' => 'distance',
          'flat_amount' => ['number' => '5', 'currency_code' => 'USD'],
          'distance_base' => ['number' => '3', 'currency_code' => 'USD'],
          'distance_per_km' => ['number' => '1', 'currency_code' => 'USD'],
          'distance_max_km' => 8,
          'round_to' => 1,
          'cod_settlement' => TRUE,
        ],
      ],
      'status' => TRUE,
    ]);
    $this->method->save();
  }

  /**
   * The rate follows the pin and the store, and the mode the shop picked.
   */
  public function testRates(): void {
    $shipment = $this->delivery()->get('shipments')->entity;
    $plugin = $this->method->getPlugin();

    $rates = $plugin->calculateRates($shipment);
    $this->assertCount(1, $rates);
    // 3 + 1 × 1.03 km, rounded up to a whole unit.
    $this->assertEquals(new Price('5', 'USD'), $rates[0]->getAmount());
    $this->assertSame('1,0 km', (string) $rates[0]->getDescription());
    $this->assertSame('shipment_domicilio', $plugin->getWorkflowId());

    // No pin: no rate by distance, but a flat fee needs none.
    $shipment->getShippingProfile()->set('field_ubicacion', NULL)->save();
    $this->assertSame([], $plugin->calculateRates($shipment));
    $plugin->setConfiguration(['fee_mode' => 'flat'] + $plugin->getConfiguration());
    $this->assertEquals(new Price('5', 'USD'), $plugin->calculateRates($shipment)[0]->getAmount());
  }

  /**
   * Placed, assigned, picked up, delivered: the order completes.
   */
  public function testDelivery(): void {
    $order = $this->delivery();
    $this->place($order);
    $shipment = $this->reload($order);
    $this->assertSame('ready', $shipment->getState()->getId(), 'Placing the order finalizes the delivery.');

    $courier = $this->createUser(['deliver shop shipments']);
    $courier->addRole('repartidor')->save();
    $shipment->set('field_repartidor', $courier->id());
    $shipment->getState()->applyTransitionById('asignar');
    $shipment->save();
    $shipment->getState()->applyTransitionById('recoger');
    $shipment->save();

    $balance = $this->reloadEntity($order)->getBalance();
    $shipment->getState()->applyTransitionById('entregar');
    $shipment->save();

    $shipment = $this->reload($order);
    $this->assertSame('entregado', $shipment->getState()->getId());
    $this->assertFalse($shipment->get('field_entregado')->isEmpty(), 'The delivery time is stamped.');
    $this->assertEquals($balance, $shipment->get('field_recaudado')->first()->toPrice(), 'The cash to collect is the order balance.');
    $this->assertSame('completed', $this->reloadEntity($order)->getState()->getId(), 'The last delivery completes the order.');

    $unsettled = $this->container->get('shop_delivery.deliveries')->unsettled();
    $this->assertSame([(int) $shipment->id()], array_map(static fn ($s): int => (int) $s->id(), $unsettled[(int) $courier->id()]));
  }

  /**
   * Sent back to the board, a delivery loses its courier.
   */
  public function testRelease(): void {
    $order = $this->delivery();
    $this->place($order);
    $shipment = $this->reload($order);
    $courier = $this->createUser();
    $shipment->set('field_repartidor', $courier->id());
    $shipment->getState()->applyTransitionById('asignar');
    $shipment->save();
    $shipment->getState()->applyTransitionById('liberar');
    $shipment->save();

    $shipment = $this->reload($order);
    $this->assertSame('ready', $shipment->getState()->getId());
    $this->assertTrue($shipment->get('field_repartidor')->isEmpty());
  }

  /**
   * A courier opens their own deliveries only; a dispatcher opens any.
   */
  public function testCourierAccess(): void {
    $order = $this->delivery();
    $this->place($order);
    $shipment = $this->reload($order);

    $mine = $this->createUser(['deliver shop shipments']);
    $other = $this->createUser(['deliver shop shipments']);
    $dispatcher = $this->createUser(['dispatch shop deliveries']);
    $shipment->set('field_repartidor', $mine->id())->save();

    $this->assertTrue(CourierShipmentForm::access($mine, $shipment)->isAllowed());
    $this->assertFalse(CourierShipmentForm::access($other, $shipment)->isAllowed());
    $this->assertTrue(CourierShipmentForm::access($dispatcher, $shipment)->isAllowed());
  }

  /**
   * The tracking link's secret is the order's alone.
   */
  public function testTrackingToken(): void {
    $tokens = $this->container->get('shop_delivery.tracking_token');
    $order = $this->delivery();
    $other = $this->delivery();
    $token = $tokens->token($order);
    $this->assertTrue($tokens->valid($order, $token));
    $this->assertFalse($tokens->valid($other, $token));
    $this->assertFalse($tokens->valid($order, substr($token, 0, -1) . 'x'));
    $this->assertStringContainsString('/pedido/' . $order->id() . '/', $tokens->url($order)->toString());
  }

  /**
   * A draft order with one Domicilio delivery to the door.
   */
  private function delivery(): OrderInterface {
    $user = $this->createUser();
    $item = OrderItem::create([
      'type' => 'test',
      'quantity' => 1,
      'unit_price' => new Price('12.00', 'USD'),
    ]);
    $item->save();
    $order = Order::create([
      'type' => 'default',
      'state' => 'draft',
      'mail' => $user->getEmail(),
      'uid' => $user->id(),
      'store_id' => $this->store->id(),
      'order_items' => [$item],
    ]);
    $order->save();
    $profile = Profile::create([
      'type' => 'customer',
      'address' => [
        'country_code' => 'CO',
        'given_name' => 'Prueba',
        'family_name' => 'Cliente',
        'address_line1' => 'Calle 67 # 7-35',
        'locality' => 'Bogotá',
        'administrative_area' => 'DC',
      ],
      'field_ubicacion' => self::DOOR,
      'field_telefono' => '3001234567',
    ]);
    $profile->save();
    $shipment = Shipment::create([
      'type' => 'default',
      'order_id' => $order->id(),
      'title' => 'Domicilio',
      'shipping_method' => $this->method,
      'shipping_service' => 'default',
      'shipping_profile' => $profile,
      'items' => [
        new ShipmentItem([
          'order_item_id' => $item->id(),
          'title' => 'Pan',
          'quantity' => 1,
          'weight' => new Weight('0', 'g'),
          'declared_value' => new Price('12', 'USD'),
        ]),
      ],
      'amount' => new Price('5', 'USD'),
      'state' => 'draft',
    ]);
    $shipment->save();
    $order->set('shipments', [$shipment]);
    $order->save();
    return $this->reloadEntity($order);
  }

  /**
   * Places an order.
   */
  private function place(OrderInterface $order): void {
    $order->getState()->applyTransitionById('place');
    $order->save();
  }

  /**
   * The order's delivery, fresh from storage.
   */
  private function reload(OrderInterface $order): ShipmentInterface {
    $order = $this->reloadEntity($order);
    return $this->reloadEntity($order->get('shipments')->entity);
  }

}
