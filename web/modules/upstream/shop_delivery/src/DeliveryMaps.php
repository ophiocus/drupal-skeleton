<?php

declare(strict_types=1);

namespace Drupal\shop_delivery;

use Drupal\commerce_shipping\Entity\ShipmentInterface;
use Drupal\Core\Url;
use Drupal\maper\Service\MapBuilder;

/**
 * The maper maps of deliveries.
 *
 * Maper draws every map on the server from vector tiles; a page carries
 * the map's address, never its points. These are the three maps the
 * module draws: one door (the courier's and the customer's, with maper's
 * "open with" buttons when the site turns them on), and every open
 * delivery (the dispatch board).
 */
final class DeliveryMaps {

  /**
   * Pointer colours by state: waiting, assigned, on the way.
   */
  private const COLOURS = [
    'ready' => '#c2410c',
    'asignado' => '#1d4ed8',
    'en_camino' => '#15803d',
    'entregado' => '#6b7280',
  ];

  /**
   * The shop's colour, for the store's pointer.
   */
  private const STORE_COLOUR = '#7c2d12';

  public function __construct(
    private readonly MapBuilder $mapBuilder,
    private readonly Deliveries $deliveries,
  ) {}

  /**
   * The map of one point, or an empty array when there is none.
   *
   * @param array{lat: float, lon: float}|null $point
   *   The point.
   * @param string $label
   *   Its pointer's name.
   * @param list<string> $cacheTags
   *   What the point was read from.
   */
  public function door(?array $point, string $label, array $cacheTags = []): array {
    if ($point === NULL) {
      return [];
    }
    return $this->mapBuilder->element([
      ['lat' => $point['lat'], 'lon' => $point['lon'], 'label' => $label, 'colour' => self::COLOURS['en_camino']],
    ], ['type' => 'locator', 'cache_tags' => $cacheTags]);
  }

  /**
   * The map of a delivery's door, for the courier and the customer.
   */
  public function delivery(ShipmentInterface $shipment, string $label): array {
    return $this->door(
      $this->deliveries->pin($shipment),
      $label,
      array_merge($shipment->getCacheTags(), $shipment->getShippingProfile()?->getCacheTags() ?? []),
    );
  }

  /**
   * The dispatch board's map: the store and every open delivery.
   *
   * @param list<\Drupal\commerce_shipping\Entity\ShipmentInterface> $shipments
   *   The deliveries to show.
   * @param array{lat: float, lon: float}|null $store
   *   The store's point.
   */
  public function board(array $shipments, ?array $store): array {
    $points = [];
    $tags = ['commerce_shipment_list'];
    if ($store !== NULL) {
      $points[] = ['lat' => $store['lat'], 'lon' => $store['lon'], 'label' => 'Tienda', 'colour' => self::STORE_COLOUR];
    }
    foreach ($shipments as $shipment) {
      $pin = $this->deliveries->pin($shipment);
      if ($pin === NULL) {
        continue;
      }
      $order = $shipment->getOrder();
      $courier = $this->deliveries->courier($shipment);
      $points[] = [
        'lat' => $pin['lat'],
        'lon' => $pin['lon'],
        'label' => 'Pedido ' . ($order?->getOrderNumber() ?? $shipment->id()),
        'colour' => self::COLOURS[$shipment->getState()->getId()] ?? self::COLOURS['ready'],
        'url' => $order ? Url::fromRoute('entity.commerce_order.canonical', ['commerce_order' => $order->id()])->toString() : NULL,
        'card' => [
          'status' => $this->deliveries->stateLabel($shipment) . ($courier ? ' · ' . $courier->getDisplayName() : ''),
          'detail' => implode(' — ', $this->deliveries->addressLines($shipment)),
        ],
      ];
      $tags = array_merge($tags, $shipment->getCacheTags());
    }
    if ($points === []) {
      return [];
    }
    return $this->mapBuilder->element($points, [
      'type' => count($points) > 1 ? 'overview' : 'locator',
      'card_slots' => ['status', 'detail'],
      'cache_tags' => array_values(array_unique($tags)),
    ]);
  }

}
