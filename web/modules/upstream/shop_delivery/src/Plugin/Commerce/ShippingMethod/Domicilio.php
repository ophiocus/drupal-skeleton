<?php

declare(strict_types=1);

namespace Drupal\shop_delivery\Plugin\Commerce\ShippingMethod;

use Drupal\commerce_price\Price;
use Drupal\commerce_shipping\Attribute\CommerceShippingMethod;
use Drupal\commerce_shipping\Entity\ShipmentInterface;
use Drupal\commerce_shipping\PackageTypeManagerInterface;
use Drupal\commerce_shipping\Plugin\Commerce\ShippingMethod\ShippingMethodBase;
use Drupal\commerce_shipping\ShippingRate;
use Drupal\commerce_shipping\ShippingService;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\shop_delivery\Deliveries;
use Drupal\shop_delivery\FeeCalculator;
use Drupal\shop_delivery\GeoMath;
use Drupal\state_machine\WorkflowManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Home delivery by the shop's own couriers.
 *
 * Every delivery parameter lives here, in the shipping method's settings
 * (Commerce » Configuración » Métodos de envío), because a shipping method
 * is content: what the shop sets in production stays set across deploys,
 * where config would be reset by the next config import.
 */
#[CommerceShippingMethod(
  id: 'shop_domicilio',
  label: new TranslatableMarkup('Domicilio (repartidores de la tienda)'),
  services: ['default' => new TranslatableMarkup('Domicilio')],
  workflow: 'shipment_domicilio',
)]
final class Domicilio extends ShippingMethodBase {

  /**
   * Empty zone rows the settings form offers beyond the saved ones.
   */
  private const SPARE_ZONES = 2;

  /**
   * The deliveries service, for the store's point and the customer's pin.
   */
  protected Deliveries $deliveries;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, PackageTypeManagerInterface $package_type_manager, WorkflowManagerInterface $workflow_manager) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $package_type_manager, $workflow_manager);
    $this->services['default'] = new ShippingService('default', $this->configuration['rate_label'] ?: 'Domicilio');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->deliveries = $container->get('shop_delivery.deliveries');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'rate_label' => 'Domicilio',
      'fee_mode' => 'flat',
      'flat_amount' => NULL,
      'distance_base' => NULL,
      'distance_per_km' => NULL,
      'distance_max_km' => 0,
      'round_to' => 100,
      'zones' => [],
      'cod_settlement' => TRUE,
      'services' => ['default'],
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);
    // One service, one package: neither means anything for a courier. And
    // the workflow is the delivery's own: the board, the courier's page and
    // the settlement all read its states.
    $form['services']['#access'] = FALSE;
    $form['default_package_type']['#access'] = FALSE;
    $form['workflow']['#access'] = FALSE;

    $form['rate_label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Nombre del domicilio'),
      '#description' => $this->t('Lo que ve el cliente al pagar.'),
      '#default_value' => $this->configuration['rate_label'],
      '#required' => TRUE,
    ];
    $form['fee_mode'] = [
      '#type' => 'radios',
      '#title' => $this->t('Cómo se cobra el domicilio'),
      '#options' => [
        'flat' => $this->t('Tarifa fija: el mismo valor en cada pedido'),
        'distance' => $this->t('Por distancia: base más un valor por kilómetro desde la tienda, en línea recta'),
        'zones' => $this->t('Por zonas: cada zona del mapa con su valor; fuera de las zonas no hay domicilio'),
      ],
      '#default_value' => $this->configuration['fee_mode'],
      '#required' => TRUE,
    ];

    $form['flat'] = [
      '#type' => 'details',
      '#title' => $this->t('Tarifa fija'),
      '#open' => $this->configuration['fee_mode'] === 'flat',
    ];
    $form['flat']['flat_amount'] = [
      '#type' => 'commerce_price',
      '#title' => $this->t('Valor'),
      '#default_value' => self::priceDefault($this->configuration['flat_amount']),
    ];

    $form['distance'] = [
      '#type' => 'details',
      '#title' => $this->t('Por distancia'),
      '#description' => $this->t('La distancia se mide en línea recta desde la ubicación de la tienda (Commerce » Tiendas). Sin ubicación de la tienda no hay tarifa.'),
      '#open' => $this->configuration['fee_mode'] === 'distance',
    ];
    $form['distance']['distance_base'] = [
      '#type' => 'commerce_price',
      '#title' => $this->t('Valor base'),
      '#default_value' => self::priceDefault($this->configuration['distance_base']),
    ];
    $form['distance']['distance_per_km'] = [
      '#type' => 'commerce_price',
      '#title' => $this->t('Valor por kilómetro'),
      '#default_value' => self::priceDefault($this->configuration['distance_per_km']),
    ];
    $form['distance']['distance_max_km'] = [
      '#type' => 'number',
      '#title' => $this->t('Distancia máxima (km)'),
      '#description' => $this->t('Más lejos no se ofrece domicilio. 0: sin límite.'),
      '#min' => 0,
      '#step' => 0.1,
      '#default_value' => $this->configuration['distance_max_km'],
    ];
    $form['distance']['round_to'] = [
      '#type' => 'number',
      '#title' => $this->t('Redondear hacia arriba a múltiplos de'),
      '#description' => $this->t('Por ejemplo 100 o 500 pesos. 0: sin redondeo.'),
      '#min' => 0,
      '#step' => 1,
      '#default_value' => $this->configuration['round_to'],
    ];

    $form['zones'] = [
      '#type' => 'details',
      '#title' => $this->t('Por zonas'),
      '#description' => $this->t('Dibuja cada zona en geojson.io (o en cualquier programa de mapas), copia el GeoJSON y pégalo aquí. Gana la primera zona que contiene el punto del cliente. Deja el nombre vacío para borrar una zona.'),
      '#open' => $this->configuration['fee_mode'] === 'zones',
    ];
    $form['zones']['list'] = [
      '#type' => 'table',
      '#header' => [$this->t('Zona'), $this->t('Valor'), $this->t('Polígono (GeoJSON)')],
    ];
    $zones = array_values((array) $this->configuration['zones']);
    $rows = count($zones) + self::SPARE_ZONES;
    for ($i = 0; $i < $rows; $i++) {
      $zone = $zones[$i] ?? [];
      $form['zones']['list'][$i]['name'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Zona'),
        '#title_display' => 'invisible',
        '#size' => 18,
        '#default_value' => $zone['name'] ?? '',
      ];
      $form['zones']['list'][$i]['amount'] = [
        '#type' => 'commerce_price',
        '#title' => $this->t('Valor'),
        '#title_display' => 'invisible',
        '#default_value' => self::priceDefault($zone['amount'] ?? NULL),
      ];
      $form['zones']['list'][$i]['geojson'] = [
        '#type' => 'textarea',
        '#title' => $this->t('Polígono'),
        '#title_display' => 'invisible',
        '#rows' => 3,
        '#default_value' => $zone['geojson'] ?? '',
      ];
    }

    $form['cod_settlement'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Los repartidores cobran contra entrega y liquidan con la tienda'),
      '#description' => $this->t('El repartidor anota lo que recibió al entregar, y la tienda lo liquida en Domicilios » Liquidación, lo que da por pagado el pedido.'),
      '#default_value' => $this->configuration['cod_settlement'],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::validateConfigurationForm($form, $form_state);
    $values = $form_state->getValue($form['#parents']);
    $mode = $values['fee_mode'] ?? 'flat';
    $missing = static fn ($price): bool => !is_array($price) || !isset($price['number']) || $price['number'] === '';
    if ($mode === 'flat' && $missing($values['flat']['flat_amount'] ?? NULL)) {
      $form_state->setError($form['flat']['flat_amount'], 'La tarifa fija necesita un valor.');
    }
    if ($mode === 'distance') {
      foreach (['distance_base', 'distance_per_km'] as $key) {
        if ($missing($values['distance'][$key] ?? NULL)) {
          $form_state->setError($form['distance'][$key], 'La tarifa por distancia necesita el valor base y el valor por kilómetro.');
        }
      }
    }
    $named = 0;
    foreach ((array) ($values['zones']['list'] ?? []) as $i => $row) {
      if (trim((string) ($row['name'] ?? '')) === '') {
        continue;
      }
      $named++;
      if ($missing($row['amount'] ?? NULL)) {
        $form_state->setError($form['zones']['list'][$i]['amount'], 'Cada zona necesita un valor.');
      }
      if (GeoMath::parsePolygons((string) ($row['geojson'] ?? '')) === NULL) {
        $form_state->setError($form['zones']['list'][$i]['geojson'], 'Este GeoJSON no tiene ningún polígono.');
      }
    }
    if ($mode === 'zones' && $named === 0) {
      $form_state->setError($form['zones'], 'El cobro por zonas necesita al menos una zona.');
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);
    if ($form_state->getErrors()) {
      return;
    }
    $values = $form_state->getValue($form['#parents']);
    $price = static fn ($price): ?array => (is_array($price) && isset($price['number']) && $price['number'] !== '')
      ? ['number' => (string) $price['number'], 'currency_code' => (string) $price['currency_code']]
      : NULL;
    $zones = [];
    foreach ((array) ($values['zones']['list'] ?? []) as $row) {
      $name = trim((string) ($row['name'] ?? ''));
      if ($name !== '') {
        $zones[] = [
          'name' => $name,
          'amount' => $price($row['amount'] ?? NULL),
          'geojson' => trim((string) $row['geojson']),
        ];
      }
    }
    $this->configuration['rate_label'] = $values['rate_label'];
    $this->configuration['fee_mode'] = $values['fee_mode'];
    $this->configuration['flat_amount'] = $price($values['flat']['flat_amount'] ?? NULL);
    $this->configuration['distance_base'] = $price($values['distance']['distance_base'] ?? NULL);
    $this->configuration['distance_per_km'] = $price($values['distance']['distance_per_km'] ?? NULL);
    $this->configuration['distance_max_km'] = (float) ($values['distance']['distance_max_km'] ?? 0);
    $this->configuration['round_to'] = (int) ($values['distance']['round_to'] ?? 0);
    $this->configuration['zones'] = $zones;
    $this->configuration['cod_settlement'] = (bool) $values['cod_settlement'];
    $this->configuration['services'] = ['default'];
    $this->configuration['workflow'] = 'shipment_domicilio';
  }

  /**
   * {@inheritdoc}
   */
  public function calculateRates(ShipmentInterface $shipment) {
    $order = $shipment->getOrder();
    $quote = FeeCalculator::quote(
      $this->configuration,
      $order ? $this->deliveries->storePoint($order->getStore()) : NULL,
      $this->deliveries->pin($shipment),
    );
    if ($quote['amount'] === NULL) {
      return [];
    }
    return [
      new ShippingRate([
        'shipping_method_id' => $this->parentEntity->id(),
        'service' => $this->services['default'],
        'amount' => new Price($quote['amount']['number'], $quote['amount']['currency_code']),
        'description' => $quote['detail'],
      ]),
    ];
  }

  /**
   * Whether couriers collect cash on delivery and settle it with the shop.
   */
  public function settlesCash(): bool {
    return (bool) $this->configuration['cod_settlement'];
  }

  /**
   * A stored price as the commerce_price element's default, or NULL.
   */
  private static function priceDefault(mixed $value): ?array {
    return is_array($value) && isset($value['number'], $value['currency_code']) ? $value : NULL;
  }

}
