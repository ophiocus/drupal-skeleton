<?php

declare(strict_types=1);

namespace Drupal\shop_delivery\Form;

use Drupal\commerce_price\CurrencyFormatter;
use Drupal\commerce_shipping\Entity\ShipmentInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\shop_delivery\Deliveries;
use Drupal\shop_delivery\DeliveryMaps;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The dispatch board.
 *
 * Every delivery not yet done, on a map and in a table, with a courier to
 * pick for each.
 */
final class DispatchBoardForm extends FormBase {

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
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'shop_delivery_dispatch_board';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['#attached']['library'][] = 'shop_delivery/delivery';
    $form['#cache']['max-age'] = 0;

    $open = $this->deliveries->inStates(Deliveries::OPEN_STATES);
    $couriers = $this->deliveries->courierOptions();
    $store = NULL;
    foreach ($open as $shipment) {
      $store = $this->deliveries->storePoint($shipment->getOrder()?->getStore());
      if ($store) {
        break;
      }
    }

    $counts = array_count_values(array_map(static fn ($s): string => $s->getState()->getId(), $open));
    $form['summary'] = [
      '#markup' => sprintf(
        '<p class="delivery-board__summary"><span class="delivery-state--ready">%d por despachar</span> · <span class="delivery-state--asignado">%d asignados</span> · <span class="delivery-state--en_camino">%d en camino</span> · %d entregados hoy</p>',
        $counts['ready'] ?? 0,
        $counts['asignado'] ?? 0,
        $counts['en_camino'] ?? 0,
        count($this->deliveries->deliveredToday()),
      ),
    ];
    if ($couriers === []) {
      $form['no_couriers'] = [
        '#markup' => '<p class="messages messages--warning">No hay repartidores: crea usuarios con el rol <em>Repartidor</em> (Personas » Agregar usuario).</p>',
      ];
    }

    $form['map'] = $this->maps->board($open, $store);

    $form['deliveries'] = [
      '#type' => 'table',
      '#header' => ['Pedido', 'Cliente', 'Dirección', 'Cobrar', 'Estado', 'Repartidor', ''],
      '#empty' => 'No hay domicilios pendientes.',
      '#attributes' => ['class' => ['delivery-board']],
    ];
    foreach ($open as $shipment) {
      $id = (int) $shipment->id();
      $order = $shipment->getOrder();
      $state = $shipment->getState()->getId();
      $balance = $order ? $this->deliveries->balance($order) : NULL;
      $courier = $this->deliveries->courier($shipment);
      $row = &$form['deliveries'][$id];

      $row['order'] = $order ? [
        '#type' => 'link',
        '#title' => $order->getOrderNumber() ?: $order->id(),
        '#url' => Url::fromRoute('entity.commerce_order.canonical', ['commerce_order' => $order->id()]),
      ] : ['#markup' => '—'];
      $row['customer'] = [
        '#type' => 'inline_template',
        '#template' => '{{ name }}{% if phone %}<br><a href="tel:{{ phone }}">{{ phone }}</a>{% endif %}',
        '#context' => [
          'name' => $this->deliveries->recipient($shipment),
          'phone' => $this->deliveries->profileText($shipment, 'field_telefono'),
        ],
      ];
      $row['address'] = [
        '#type' => 'inline_template',
        '#template' => '{{ lines|join(", ") }}{% if notes %}<br><small>{{ notes }}</small>{% endif %}{% if not pin %}<br><small class="delivery-board__nopin">Sin ubicación en el mapa</small>{% endif %}',
        '#context' => [
          'lines' => $this->deliveries->addressLines($shipment),
          'notes' => $this->deliveries->profileText($shipment, 'field_indicaciones'),
          'pin' => $this->deliveries->pin($shipment) !== NULL,
        ],
      ];
      $row['collect'] = [
        '#markup' => $balance ? $this->currencyFormatter->format($balance->getNumber(), $balance->getCurrencyCode()) : 'Pagado',
      ];
      $row['state'] = [
        '#markup' => '<span class="delivery-card__state delivery-state--' . $state . '">' . $this->deliveries->stateLabel($shipment) . '</span>',
      ];
      $row['courier'] = [
        '#type' => 'select',
        '#title' => 'Repartidor',
        '#title_display' => 'invisible',
        // A courier who has since lost the role stays selectable on the
        // deliveries they still hold, or the row could not be saved.
        '#options' => $couriers + ($courier ? [(int) $courier->id() => $courier->getDisplayName()] : []),
        '#empty_option' => '— Elegir —',
        '#default_value' => $courier ? (int) $courier->id() : NULL,
      ];
      $row['actions'] = ['#type' => 'actions'];
      $row['actions']['assign'] = [
        '#type' => 'submit',
        '#value' => $state === 'ready' ? 'Asignar' : 'Reasignar',
        '#name' => 'assign_' . $id,
        '#shipment' => $id,
        '#op' => 'assign',
        '#access' => $couriers !== [],
      ];
      if ($state !== 'ready') {
        $row['actions']['release'] = [
          '#type' => 'submit',
          '#value' => 'Devolver',
          '#name' => 'release_' . $id,
          '#shipment' => $id,
          '#op' => 'release',
          '#limit_validation_errors' => [],
          '#submit' => ['::submitForm'],
        ];
      }
      unset($row);
    }
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $trigger = $form_state->getTriggeringElement();
    if (($trigger['#op'] ?? '') === 'assign') {
      $id = (int) $trigger['#shipment'];
      if (empty($form_state->getValue(['deliveries', $id, 'courier']))) {
        $form_state->setError($form['deliveries'][$id]['courier'], 'Elige un repartidor.');
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $trigger = $form_state->getTriggeringElement();
    $shipment = $this->entityTypeManager->getStorage('commerce_shipment')->load((int) ($trigger['#shipment'] ?? 0));
    if (!$shipment instanceof ShipmentInterface) {
      return;
    }
    $state = $shipment->getState();
    $number = $shipment->getOrder()?->getOrderNumber() ?? $shipment->id();
    if (($trigger['#op'] ?? '') === 'release') {
      if ($state->isTransitionAllowed('liberar')) {
        $state->applyTransitionById('liberar');
        $shipment->save();
        $this->messenger()->addStatus('Pedido ' . $number . ' volvió a por despachar.');
      }
      return;
    }
    $courier = (int) $form_state->getValue(['deliveries', (int) $shipment->id(), 'courier']);
    $shipment->set('field_repartidor', $courier);
    if ($state->isTransitionAllowed('asignar')) {
      $state->applyTransitionById('asignar');
    }
    $shipment->save();
    $name = $this->deliveries->courier($shipment)?->getDisplayName() ?? '';
    $this->messenger()->addStatus('Pedido ' . $number . ' asignado a ' . $name . '.');
  }

}
