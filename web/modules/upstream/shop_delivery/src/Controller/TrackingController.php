<?php

declare(strict_types=1);

namespace Drupal\shop_delivery\Controller;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\shop_delivery\Deliveries;
use Drupal\shop_delivery\DeliveryMaps;
use Drupal\shop_delivery\TrackingToken;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The customer's page for one order: where its delivery is at.
 *
 * No live position: the courier's whereabouts are never recorded. The
 * page says which step the delivery is on, who carries it, and shows the
 * door it is going to.
 */
final class TrackingController implements ContainerInjectionInterface {

  use AutowireTrait;

  /**
   * The steps a customer sees, by shipment state.
   */
  private const STEPS = [
    'ready' => 'Recibimos tu pedido',
    'asignado' => 'Asignado a un repartidor',
    'en_camino' => 'En camino',
    'entregado' => 'Entregado',
  ];

  public function __construct(
    #[Autowire(service: 'shop_delivery.deliveries')]
    private readonly Deliveries $deliveries,
    #[Autowire(service: 'shop_delivery.maps')]
    private readonly DeliveryMaps $maps,
    #[Autowire(service: 'shop_delivery.tracking_token')]
    private readonly TrackingToken $tokens,
  ) {}

  /**
   * The link's secret must be the order's, and the order placed.
   */
  public static function access(OrderInterface $commerce_order, string $token): AccessResultInterface {
    $valid = \Drupal::service('shop_delivery.tracking_token')->valid($commerce_order, $token)
      && $commerce_order->getState()->getId() !== 'draft';
    return AccessResult::allowedIf($valid)->setCacheMaxAge(0);
  }

  /**
   * The page.
   */
  public function view(OrderInterface $commerce_order): array {
    $build = [
      '#attached' => ['library' => ['shop_delivery/tracking']],
      '#cache' => ['max-age' => 0],
      'order' => ['#markup' => '<p class="delivery-track__order">Pedido <strong>' . $commerce_order->getOrderNumber() . '</strong></p>'],
    ];
    $shipments = $this->deliveries->ofOrder($commerce_order);
    if ($shipments === []) {
      $build['none'] = ['#markup' => '<p>Este pedido no es a domicilio.</p>'];
      return $build;
    }
    foreach ($shipments as $i => $shipment) {
      $state = $shipment->getState()->getId();
      $reached = array_search($state, array_keys(self::STEPS), TRUE);
      $steps = [];
      foreach (array_keys(self::STEPS) as $position => $key) {
        $steps[] = [
          'label' => self::STEPS[$key],
          'class' => $reached === FALSE ? 'todo' : ($position < $reached ? 'done' : ($position === $reached ? 'now' : 'todo')),
        ];
      }
      $courier = $this->deliveries->courier($shipment);
      $first_name = $courier ? strtok($courier->getDisplayName(), ' ') : '';
      $build['delivery_' . $i] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['delivery-track'],
          // The page reloads itself while the delivery is under way.
          'data-delivery-track-live' => in_array($state, Deliveries::OPEN_STATES, TRUE) ? '60' : '',
        ],
        'steps' => [
          '#type' => 'inline_template',
          '#template' => '{% if canceled %}<p class="delivery-track__canceled">Este domicilio fue cancelado. Escríbenos si necesitas ayuda.</p>{% else %}'
          . '<ol class="delivery-track__steps">{% for step in steps %}<li class="is-{{ step.class }}">{{ step.label }}</li>{% endfor %}</ol>{% endif %}'
          . '{% if courier %}<p>Te lo lleva <strong>{{ courier }}</strong>.</p>{% endif %}'
          . '{% if lines %}<p class="delivery-track__address">{{ lines|join(", ") }}</p>{% endif %}',
          '#context' => [
            'canceled' => $state === 'canceled',
            'steps' => $steps,
            'courier' => in_array($state, ['asignado', 'en_camino'], TRUE) ? $first_name : '',
            'lines' => $this->deliveries->addressLines($shipment),
          ],
        ],
        'map' => $this->maps->delivery($shipment, 'Tu entrega'),
      ];
    }
    return $build;
  }

}
