<?php

declare(strict_types=1);

namespace Drupal\shop_delivery\Form;

use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\ManualPaymentGatewayInterface;
use Drupal\commerce_price\CurrencyFormatter;
use Drupal\commerce_price\Price;
use Drupal\commerce_shipping\Entity\ShipmentInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\shop_delivery\Deliveries;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Settlement: the cash each courier collected and has not handed in.
 *
 * One button per courier. Settling stamps each delivery as settled and
 * receives its order's payment: the pending cash-on-delivery payment when
 * the checkout created one (for the amount collected), or a new received
 * payment through the cash-on-delivery gateway when it did not.
 */
final class SettlementForm extends FormBase {

  /**
   * The cash-on-delivery gateway this site's checkout offers.
   */
  public const COD_GATEWAY = 'contra_entrega';

  /**
   * The deliveries.
   */
  protected Deliveries $deliveries;

  /**
   * The currency formatter.
   */
  protected CurrencyFormatter $currencyFormatter;

  /**
   * The entity type manager.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The time service.
   */
  protected TimeInterface $time;

  /**
   * The date formatter.
   */
  protected DateFormatterInterface $dateFormatter;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $form = new self();
    $form->dateFormatter = $container->get('date.formatter');
    $form->deliveries = $container->get('shop_delivery.deliveries');
    $form->currencyFormatter = $container->get('commerce_price.currency_formatter');
    $form->entityTypeManager = $container->get('entity_type.manager');
    $form->time = $container->get('datetime.time');
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'shop_delivery_settlement';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['#attached']['library'][] = 'shop_delivery/delivery';
    $form['#cache']['max-age'] = 0;
    $groups = $this->deliveries->unsettled();
    if ($groups === []) {
      $form['empty'] = ['#markup' => '<p>Nada por liquidar: cada entrega cobrada ya está liquidada.</p>'];
      return $form;
    }
    $names = $this->deliveries->courierOptions();
    foreach ($groups as $uid => $shipments) {
      $name = $names[$uid] ?? ($uid ? $this->entityTypeManager->getStorage('user')->load($uid)?->getDisplayName() : NULL) ?? 'Sin repartidor';
      $total = NULL;
      $rows = [];
      foreach ($shipments as $shipment) {
        $collected = $shipment->get('field_recaudado')->first()->toPrice();
        $total = $total ? $total->add($collected) : $collected;
        $order = $shipment->getOrder();
        $rows[] = [
          $order ? [
            'data' => [
              '#type' => 'link',
              '#title' => $order->getOrderNumber() ?: $order->id(),
              '#url' => Url::fromRoute('entity.commerce_order.canonical', ['commerce_order' => $order->id()]),
            ],
          ] : '—',
          implode(', ', $this->deliveries->addressLines($shipment)),
          $this->dateFormatter->format((int) $shipment->get('field_entregado')->value, 'custom', 'd/m H:i'),
          $this->format($collected),
          $order?->getTotalPrice() ? $this->format($order->getTotalPrice()) : '—',
        ];
      }
      $form['courier_' . $uid] = [
        '#type' => 'details',
        '#title' => $name . ' · ' . count($shipments) . ' entregas · ' . $this->format($total),
        '#open' => TRUE,
        'table' => [
          '#type' => 'table',
          '#header' => ['Pedido', 'Dirección', 'Entregado', 'Recibió', 'Total del pedido'],
          '#rows' => $rows,
        ],
        'settle' => [
          '#type' => 'submit',
          '#value' => 'Liquidar ' . $this->format($total) . ' de ' . $name,
          '#name' => 'settle_' . $uid,
          '#shipments' => array_map(static fn ($s): int => (int) $s->id(), $shipments),
        ],
      ];
    }
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $ids = (array) ($form_state->getTriggeringElement()['#shipments'] ?? []);
    $settled = 0;
    $now = $this->time->getRequestTime();
    foreach ($this->entityTypeManager->getStorage('commerce_shipment')->loadMultiple($ids) as $shipment) {
      if (!$shipment instanceof ShipmentInterface || !$shipment->get('field_liquidado')->isEmpty()) {
        continue;
      }
      $collected = $shipment->get('field_recaudado')->first()?->toPrice();
      $order = $shipment->getOrder();
      if ($order && $collected && $collected->isPositive()) {
        $this->receive($order->id(), $collected);
      }
      $shipment->set('field_liquidado', $now);
      $shipment->save();
      $settled++;
    }
    $this->messenger()->addStatus($settled . ' entregas liquidadas.');
  }

  /**
   * Receives an order's cash: its pending manual payment, or a new one.
   */
  private function receive(int|string $order_id, Price $collected): void {
    $payments = $this->entityTypeManager->getStorage('commerce_payment')->loadByProperties(['order_id' => $order_id]);
    foreach ($payments as $payment) {
      $plugin = $payment instanceof PaymentInterface ? $payment->getPaymentGateway()?->getPlugin() : NULL;
      if ($plugin instanceof ManualPaymentGatewayInterface && $payment->getState()->getId() === 'pending') {
        $plugin->receivePayment($payment, $collected);
        return;
      }
    }
    $gateway = $this->entityTypeManager->getStorage('commerce_payment_gateway')->load(self::COD_GATEWAY);
    $plugin = $gateway?->getPlugin();
    if (!$plugin instanceof ManualPaymentGatewayInterface) {
      $this->messenger()->addWarning('No hay pasarela «' . self::COD_GATEWAY . '»: la entrega queda liquidada, pero el pago no se registró en el pedido.');
      return;
    }
    $payment = $this->entityTypeManager->getStorage('commerce_payment')->create([
      'state' => 'new',
      'amount' => $collected,
      'payment_gateway' => $gateway->id(),
      'order_id' => $order_id,
    ]);
    $plugin->createPayment($payment, TRUE);
  }

  /**
   * A price as the shop writes it.
   */
  private function format(Price $price): string {
    return $this->currencyFormatter->format($price->getNumber(), $price->getCurrencyCode());
  }

}
