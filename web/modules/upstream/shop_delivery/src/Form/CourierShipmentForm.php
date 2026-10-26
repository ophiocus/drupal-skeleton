<?php

declare(strict_types=1);

namespace Drupal\shop_delivery\Form;

use Drupal\commerce_price\Calculator;
use Drupal\commerce_price\CurrencyFormatter;
use Drupal\commerce_price\Price;
use Drupal\commerce_shipping\Entity\ShipmentInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\shop_delivery\Deliveries;
use Drupal\shop_delivery\DeliveryMaps;
use Drupal\shop_delivery\DeliveryText;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * One delivery, for its courier.
 *
 * Where, who, what to collect, and the two buttons of the job ("picked
 * up", "delivered").
 */
final class CourierShipmentForm extends FormBase {

  /**
   * The deliveries.
   */
  protected Deliveries $deliveries;

  /**
   * The delivery maps.
   */
  protected DeliveryMaps $maps;

  /**
   * The currency formatter.
   */
  protected CurrencyFormatter $currencyFormatter;

  /**
   * The entity type manager.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $form = new self();
    $form->entityTypeManager = $container->get('entity_type.manager');
    $form->deliveries = $container->get('shop_delivery.deliveries');
    $form->maps = $container->get('shop_delivery.maps');
    $form->currencyFormatter = $container->get('commerce_price.currency_formatter');
    return $form;
  }

  /**
   * Its courier, or a dispatcher, and only for a Domicilio delivery.
   */
  public static function access(AccountInterface $account, ShipmentInterface $commerce_shipment): AccessResultInterface {
    $deliveries = \Drupal::service('shop_delivery.deliveries');
    if (!$deliveries->method($commerce_shipment)) {
      return AccessResult::forbidden()->addCacheableDependency($commerce_shipment);
    }
    $own = $account->hasPermission('deliver shop shipments')
      && (int) $commerce_shipment->get('field_repartidor')->target_id === (int) $account->id();
    return AccessResult::allowedIf($own || $account->hasPermission('dispatch shop deliveries'))
      ->cachePerUser()
      ->addCacheableDependency($commerce_shipment);
  }

  /**
   * The page title: the order number.
   */
  public static function title(ShipmentInterface $commerce_shipment): string {
    return 'Pedido ' . ($commerce_shipment->getOrder()?->getOrderNumber() ?? $commerce_shipment->id());
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'shop_delivery_courier_shipment';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?ShipmentInterface $commerce_shipment = NULL) {
    $shipment = $commerce_shipment;
    $form_state->set('shipment_id', $shipment->id());
    $order = $shipment->getOrder();
    $state = $shipment->getState()->getId();
    $phone = $this->deliveries->profileText($shipment, 'field_telefono');
    $notes = $this->deliveries->profileText($shipment, 'field_indicaciones');
    $balance = $order ? $this->deliveries->balance($order) : NULL;
    $cash = (bool) $this->deliveries->method($shipment)?->settlesCash();

    $form['#attached']['library'][] = 'shop_delivery/delivery';
    $form['#cache']['max-age'] = 0;
    $form['#attributes']['class'][] = 'shop-delivery';

    $form['state'] = [
      '#markup' => '<p class="delivery-card__state delivery-state--' . $state . '">' . $this->deliveries->stateLabel($shipment) . '</p>',
    ];
    $form['who'] = [
      '#type' => 'inline_template',
      '#template' => '<div class="shop-delivery__who">'
      . '<p class="shop-delivery__name">{{ name }}</p>'
      . '{% for line in lines %}<p class="shop-delivery__address">{{ line }}</p>{% endfor %}'
      . '{% if notes %}<p class="shop-delivery__notes"><strong>Indicaciones:</strong> {{ notes }}</p>{% endif %}'
      . '</div>',
      '#context' => [
        'name' => $this->deliveries->recipient($shipment),
        'lines' => $this->deliveries->addressLines($shipment),
        'notes' => $notes,
      ],
    ];

    $form['contact'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['shop-delivery__contact']],
    ];
    if ($phone !== '') {
      $form['contact']['call'] = [
        '#type' => 'link',
        '#title' => 'Llamar ' . $phone,
        '#url' => Url::fromUri('tel:' . DeliveryText::tel($phone)),
        '#attributes' => ['class' => ['button']],
      ];
      $wa = DeliveryText::whatsapp($phone);
      if ($wa !== '') {
        $form['contact']['whatsapp'] = [
          '#type' => 'link',
          '#title' => 'WhatsApp',
          '#url' => Url::fromUri('https://wa.me/' . $wa),
          '#attributes' => ['class' => ['button']],
        ];
      }
    }

    $form['map'] = $this->maps->delivery($shipment, $this->deliveries->recipient($shipment) ?: 'Entrega');
    if ($form['map'] === []) {
      $form['map'] = ['#markup' => '<p class="messages messages--warning">Este pedido no tiene ubicación en el mapa: guíate por la dirección y llama al cliente.</p>'];
    }

    $items = [];
    foreach ($order ? $order->getItems() : [] as $item) {
      $items[] = (int) $item->getQuantity() . ' × ' . $item->label();
    }
    $form['items'] = [
      '#theme' => 'item_list',
      '#title' => 'Lleva',
      '#items' => $items,
    ];

    if ($cash && $state !== 'entregado') {
      $form['collect'] = [
        '#markup' => '<p class="shop-delivery__collect">' . ($balance
          ? 'Cobrar al entregar: <strong>' . $this->format($balance) . '</strong>'
          : 'Pedido pagado: no cobres nada.') . '</p>',
      ];
    }
    if ($state === 'entregado') {
      $collected = $shipment->get('field_recaudado')->first()?->toPrice();
      $form['done'] = [
        '#markup' => '<p class="shop-delivery__collect">Entregado' . ($collected ? ' · recibiste ' . $this->format($collected) : '') . '.</p>',
      ];
    }

    $form['actions'] = ['#type' => 'actions'];
    if ($state === 'asignado') {
      $form['actions']['recoger'] = [
        '#type' => 'submit',
        '#value' => 'Recogí el pedido en la tienda',
        '#transition' => 'recoger',
        '#button_type' => 'primary',
      ];
    }
    if ($state === 'en_camino') {
      if ($cash && $balance) {
        $form['received'] = [
          '#type' => 'number',
          '#title' => 'Recibiste (' . $balance->getCurrencyCode() . ')',
          '#default_value' => Calculator::trim($balance->getNumber()),
          '#min' => 0,
          '#step' => 1,
          '#required' => TRUE,
          '#weight' => 90,
        ];
      }
      $form['actions']['entregar'] = [
        '#type' => 'submit',
        '#value' => 'Entregado',
        '#transition' => 'entregar',
        '#button_type' => 'primary',
      ];
    }
    $form['actions']['back'] = [
      '#type' => 'link',
      '#title' => 'Mis entregas',
      '#url' => Url::fromRoute('shop_delivery.courier'),
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $transition = (string) ($form_state->getTriggeringElement()['#transition'] ?? '');
    $shipment = $this->entityTypeManager->getStorage('commerce_shipment')->load($form_state->get('shipment_id'));
    if (!$shipment instanceof ShipmentInterface || $transition === '') {
      return;
    }
    $state = $shipment->getState();
    if (!$state->isTransitionAllowed($transition)) {
      $this->messenger()->addWarning('Esta entrega cambió mientras la mirabas: ' . $this->deliveries->stateLabel($shipment) . '.');
      return;
    }
    if ($transition === 'entregar' && $form_state->getValue('received') !== NULL) {
      $currency = $shipment->getOrder()?->getTotalPrice()?->getCurrencyCode() ?? 'COP';
      $shipment->set('field_recaudado', new Price((string) (int) $form_state->getValue('received'), $currency));
    }
    $state->applyTransitionById($transition);
    $shipment->save();
    $this->messenger()->addStatus($transition === 'entregar' ? 'Entrega registrada. ¡Gracias!' : 'Vas en camino.');
    if ($transition === 'entregar') {
      $form_state->setRedirect('shop_delivery.courier');
    }
  }

  /**
   * A price as the shop writes it.
   */
  private function format(Price $price): string {
    return $this->currencyFormatter->format($price->getNumber(), $price->getCurrencyCode());
  }

}
