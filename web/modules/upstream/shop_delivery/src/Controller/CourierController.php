<?php

declare(strict_types=1);

namespace Drupal\shop_delivery\Controller;

use Drupal\commerce_price\CurrencyFormatter;
use Drupal\commerce_shipping\Entity\ShipmentInterface;
use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\shop_delivery\Deliveries;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The courier's list: what to deliver now, and what was delivered today.
 *
 * Built for a phone held in one hand: one card per delivery, the address
 * first, the whole card a link to the delivery's page.
 */
final class CourierController implements ContainerInjectionInterface {

  use AutowireTrait;

  public function __construct(
    #[Autowire(service: 'shop_delivery.deliveries')]
    private readonly Deliveries $deliveries,
    private readonly AccountInterface $currentUser,
    #[Autowire(service: 'commerce_price.currency_formatter')]
    private readonly CurrencyFormatter $currencyFormatter,
  ) {}

  /**
   * The list.
   */
  public function list(): array {
    $uid = (int) $this->currentUser->id();
    $build = [
      '#attached' => ['library' => ['shop_delivery/delivery']],
      // Live data: a delivery assigned a minute ago must show.
      '#cache' => ['max-age' => 0],
    ];

    $open = $this->deliveries->inStates(['asignado', 'en_camino'], $uid);
    $build['open'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['delivery-courier']],
      'title' => ['#markup' => '<h2>Por entregar (' . count($open) . ')</h2>'],
    ];
    if ($open === []) {
      $build['open']['empty'] = ['#markup' => '<p>No tienes entregas asignadas. Esta página no se actualiza sola: recárgala.</p>'];
    }
    foreach ($open as $shipment) {
      $build['open'][] = $this->card($shipment);
    }

    $done = $this->deliveries->deliveredToday($uid);
    if ($done !== []) {
      $build['done'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['delivery-courier', 'delivery-courier--done']],
        'title' => ['#markup' => '<h2>Entregadas hoy (' . count($done) . ')</h2>'],
      ];
      foreach ($done as $shipment) {
        $build['done'][] = $this->card($shipment);
      }
    }
    return $build;
  }

  /**
   * One delivery's card.
   */
  private function card(ShipmentInterface $shipment): array {
    $order = $shipment->getOrder();
    $state = $shipment->getState()->getId();
    $lines = $this->deliveries->addressLines($shipment);
    $collect = '';
    if ($state !== 'entregado' && $order && $this->deliveries->method($shipment)?->settlesCash()) {
      $balance = $this->deliveries->balance($order);
      $collect = $balance
        ? 'Cobrar ' . $this->currencyFormatter->format($balance->getNumber(), $balance->getCurrencyCode())
        : 'Pagado';
    }
    return [
      '#type' => 'link',
      '#url' => Url::fromRoute('shop_delivery.courier_shipment', ['commerce_shipment' => $shipment->id()]),
      '#title' => [
        '#type' => 'inline_template',
        '#template' => '<span class="delivery-card__state delivery-state--{{ state }}">{{ label }}</span>'
        . '<strong class="delivery-card__address">{{ line1 }}</strong>'
        . '{% if line2 %}<span>{{ line2 }}</span>{% endif %}'
        . '<span class="delivery-card__meta">Pedido {{ number }}{% if collect %} · {{ collect }}{% endif %}</span>',
        '#context' => [
          'state' => $state,
          'label' => $this->deliveries->stateLabel($shipment),
          'line1' => $lines[0] ?? 'Sin dirección',
          'line2' => $lines[1] ?? '',
          'number' => $order?->getOrderNumber() ?? $shipment->id(),
          'collect' => $collect,
        ],
      ],
      '#attributes' => ['class' => ['delivery-card']],
    ];
  }

}
