<?php

declare(strict_types=1);

namespace Drupal\shop_delivery;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_price\Price;
use Drupal\commerce_shipping\Entity\ShipmentInterface;
use Drupal\commerce_store\Entity\StoreInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\shop_delivery\Plugin\Commerce\ShippingMethod\Domicilio;
use Drupal\maper\Plugin\Field\FieldType\MaperPointItem;
use Drupal\user\UserInterface;

/**
 * Reads deliveries: who, where, what to collect, and in which state.
 *
 * Every page of the module (the board, the courier's, the customer's)
 * reads through here, so a delivery means the same thing on all of them.
 */
final class Deliveries {

  /**
   * The field holding a point: the store's (origin) and the profile's (door).
   */
  public const POINT_FIELD = 'field_ubicacion';

  /**
   * The courier role.
   */
  public const COURIER_ROLE = 'repartidor';

  /**
   * States a delivery is still on its way in, in the order they happen.
   */
  public const OPEN_STATES = ['ready', 'asignado', 'en_camino'];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly TimeInterface $time,
  ) {}

  /**
   * The Domicilio plugin behind a shipment, or NULL for another method.
   */
  public function method(ShipmentInterface $shipment): ?Domicilio {
    $plugin = $shipment->getShippingMethod()?->getPlugin();
    return $plugin instanceof Domicilio ? $plugin : NULL;
  }

  /**
   * The customer's pin, from the shipment's shipping profile.
   *
   * @return array{lat: float, lon: float}|null
   *   The point.
   */
  public function pin(ShipmentInterface $shipment): ?array {
    $profile = $shipment->getShippingProfile();
    if (!$profile || !$profile->hasField(self::POINT_FIELD)) {
      return NULL;
    }
    return MaperPointItem::coordinates($profile->get(self::POINT_FIELD)->first());
  }

  /**
   * The store's point, where every delivery starts.
   *
   * @return array{lat: float, lon: float}|null
   *   The point.
   */
  public function storePoint(?StoreInterface $store): ?array {
    if (!$store || !$store->hasField(self::POINT_FIELD)) {
      return NULL;
    }
    return MaperPointItem::coordinates($store->get(self::POINT_FIELD)->first());
  }

  /**
   * The shipments of one order that are Domicilio deliveries.
   *
   * @return list<\Drupal\commerce_shipping\Entity\ShipmentInterface>
   *   The shipments.
   */
  public function ofOrder(OrderInterface $order): array {
    if (!$order->hasField('shipments')) {
      return [];
    }
    $out = [];
    foreach ($order->get('shipments')->referencedEntities() as $shipment) {
      if ($shipment instanceof ShipmentInterface && $this->method($shipment)) {
        $out[] = $shipment;
      }
    }
    return $out;
  }

  /**
   * Deliveries in the given states, oldest order first.
   *
   * @param list<string> $states
   *   Shipment states.
   * @param int|null $courier
   *   Only this courier's, when given.
   *
   * @return list<\Drupal\commerce_shipping\Entity\ShipmentInterface>
   *   The shipments.
   */
  public function inStates(array $states, ?int $courier = NULL): array {
    $storage = $this->entityTypeManager->getStorage('commerce_shipment');
    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('state', $states, 'IN')
      ->sort('order_id')
      ->sort('shipment_id');
    if ($courier !== NULL) {
      $query->condition('field_repartidor', $courier);
    }
    $out = [];
    foreach ($storage->loadMultiple($query->execute()) as $shipment) {
      if ($shipment instanceof ShipmentInterface && $this->method($shipment)) {
        $out[] = $shipment;
      }
    }
    return $out;
  }

  /**
   * Deliveries done since the start of today, newest first.
   *
   * @return list<\Drupal\commerce_shipping\Entity\ShipmentInterface>
   *   The shipments.
   */
  public function deliveredToday(?int $courier = NULL): array {
    $start = strtotime('today', $this->time->getRequestTime());
    $out = array_filter(
      $this->inStates(['entregado'], $courier),
      fn (ShipmentInterface $s): bool => (int) $s->get('field_entregado')->value >= $start,
    );
    usort($out, fn ($a, $b): int => (int) $b->get('field_entregado')->value <=> (int) $a->get('field_entregado')->value);
    return array_values($out);
  }

  /**
   * Delivered, cash-collecting deliveries no one has settled yet.
   *
   * @return array<int, list<\Drupal\commerce_shipping\Entity\ShipmentInterface>>
   *   Shipments, keyed by courier user id (0: none recorded).
   */
  public function unsettled(): array {
    $out = [];
    foreach ($this->inStates(['entregado']) as $shipment) {
      if ($shipment->get('field_liquidado')->isEmpty()
        && $this->method($shipment)?->settlesCash()
        && !$shipment->get('field_recaudado')->isEmpty()) {
        $out[(int) $shipment->get('field_repartidor')->target_id][] = $shipment;
      }
    }
    return $out;
  }

  /**
   * What the customer still owes on the order: total less what is paid.
   */
  public function balance(OrderInterface $order): ?Price {
    $balance = $order->getBalance();
    return $balance && $balance->isPositive() ? $balance : NULL;
  }

  /**
   * The courier, if one is assigned.
   */
  public function courier(ShipmentInterface $shipment): ?UserInterface {
    $courier = $shipment->get('field_repartidor')->entity;
    return $courier instanceof UserInterface ? $courier : NULL;
  }

  /**
   * Active users with the courier role, by name.
   *
   * @return array<int, string>
   *   Display names keyed by user id.
   */
  public function courierOptions(): array {
    $storage = $this->entityTypeManager->getStorage('user');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('status', 1)
      ->condition('roles', self::COURIER_ROLE)
      ->sort('name')
      ->execute();
    $out = [];
    foreach ($storage->loadMultiple($ids) as $user) {
      $out[(int) $user->id()] = $user->getDisplayName();
    }
    return $out;
  }

  /**
   * The door, as lines: street, details, town.
   *
   * @return list<string>
   *   Plain-text lines.
   */
  public function addressLines(ShipmentInterface $shipment): array {
    $profile = $shipment->getShippingProfile();
    if (!$profile || !$profile->hasField('address') || $profile->get('address')->isEmpty()) {
      return [];
    }
    $a = $profile->get('address')->first();
    $lines = [
      trim($a->getAddressLine1() . ' ' . $a->getAddressLine2()),
      trim(implode(', ', array_filter([$a->getDependentLocality(), $a->getLocality(), $a->getAdministrativeArea()]))),
    ];
    return array_values(array_filter($lines, static fn (string $l): bool => $l !== ''));
  }

  /**
   * The customer's name on the shipping profile.
   */
  public function recipient(ShipmentInterface $shipment): string {
    $profile = $shipment->getShippingProfile();
    if (!$profile || !$profile->hasField('address') || $profile->get('address')->isEmpty()) {
      return '';
    }
    $a = $profile->get('address')->first();
    return trim($a->getGivenName() . ' ' . $a->getFamilyName());
  }

  /**
   * A plain field of the shipping profile (phone, instructions), or ''.
   */
  public function profileText(ShipmentInterface $shipment, string $field): string {
    $profile = $shipment->getShippingProfile();
    if (!$profile || !$profile->hasField($field) || $profile->get($field)->isEmpty()) {
      return '';
    }
    return trim((string) $profile->get($field)->value);
  }

  /**
   * The label of the shipment's state ("En camino").
   */
  public function stateLabel(ShipmentInterface $shipment): string {
    return (string) $shipment->getState()->getLabel();
  }

}
