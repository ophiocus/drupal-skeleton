<?php

declare(strict_types=1);

namespace Drupal\shop_delivery\Plugin\Field\FieldWidget;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Field\Attribute\FieldWidget;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\shop_delivery\AddressSearch;
use Drupal\shop_delivery\DeliveryMaps;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The customer's door, as a pin: latitude and longitude, found three ways.
 *
 * The maper widget is two numbers to paste. A customer at checkout does
 * not have numbers; they have a phone, a Google Maps link sent to them on
 * WhatsApp, and an address. So, around the same two numbers (latitude
 * first, as every map app prints them):
 *
 * - "Usar mi ubicación": the browser's own geolocation. The position
 *   stays between the phone and this site.
 * - Paste a link or coordinates: a Google Maps, Apple Maps, OpenStreetMap
 *   or WhatsApp location link, or "4.6097, -74.0817", read in the browser.
 * - "Buscar mi dirección": the address typed above, looked up through
 *   maper_nominatim (experimental), when that module is on and allowed to
 *   ask.
 *
 * Under them, the maper map of the pin as it stands, so the customer sees
 * where the courier will go. A pin moved in the browser shows on the map
 * once the form is recalculated (the shipping pane does that by itself).
 */
#[FieldWidget(
  id: 'shop_delivery_pin',
  label: new TranslatableMarkup('Delivery pin (location, link or address search)'),
  field_types: ['maper_point'],
)]
final class PinWidget extends WidgetBase implements ContainerFactoryPluginInterface {

  /**
   * The delivery maps.
   */
  protected DeliveryMaps $maps;

  /**
   * The address search, when it can answer.
   */
  protected AddressSearch $search;

  public function __construct(
    string $plugin_id,
    mixed $plugin_definition,
    FieldDefinitionInterface $field_definition,
    array $settings,
    array $third_party_settings,
    DeliveryMaps $maps,
    AddressSearch $search,
  ) {
    parent::__construct($plugin_id, $plugin_definition, $field_definition, $settings, $third_party_settings);
    $this->maps = $maps;
    $this->search = $search;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new self(
      $plugin_id,
      $plugin_definition,
      $configuration['field_definition'],
      $configuration['settings'],
      $configuration['third_party_settings'],
      $container->get('shop_delivery.maps'),
      $container->get('shop_delivery.address_search'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings() {
    return [
      'require_pin' => FALSE,
    ] + parent::defaultSettings();
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state) {
    $element['require_pin'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Exigir la ubicación en este formulario'),
      '#description' => $this->t('Para la dirección de entrega al pagar, donde el repartidor la necesita. El campo sigue opcional, así que la facturación y los demás formularios no la piden.'),
      '#default_value' => $this->getSetting('require_pin'),
    ];
    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary() {
    return [$this->getSetting('require_pin') ? 'Ubicación obligatoria' : 'Ubicación opcional'];
  }

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {
    $item = $items[$delta];
    $lat = $item->lat ?? NULL;
    $lon = $item->lon ?? NULL;
    // On a rebuild (the shipping pane recalculating) the entity may not
    // carry the numbers just set in the browser yet; the submitted ones
    // are what the map must show.
    $submitted = NestedArray::getValue(
      $form_state->getUserInput(),
      array_merge($element['#field_parents'], [$this->fieldDefinition->getName(), $delta]),
    );
    if (is_array($submitted) && isset($submitted['lat'], $submitted['lon'])
      && is_numeric($submitted['lat']) && is_numeric($submitted['lon'])) {
      $lat = $submitted['lat'];
      $lon = $submitted['lon'];
    }
    $point = ($lat !== NULL && $lon !== NULL && $lat !== '' && $lon !== '')
      ? ['lat' => (float) $lat, 'lon' => (float) $lon]
      : NULL;
    $required = $element['#required'] || $this->getSetting('require_pin');

    $element += [
      '#type' => 'fieldset',
      '#title' => 'Ubicación de entrega',
    ];
    $element['#required'] = FALSE;
    $element['#attributes']['class'][] = 'delivery-pin';
    $element['#attributes']['data-delivery-pin'] = '';
    $element['#attached']['library'][] = 'shop_delivery/pin';
    $element['#attached']['drupalSettings']['shopDeliveryPin'] = [
      'locate' => $this->search->available() ? Url::fromRoute('shop_delivery.locate')->toString() : NULL,
      'token' => Url::fromRoute('system.csrftoken')->toString(),
    ];
    $element['#element_validate'][] = [static::class, 'validatePin'];
    $element['#shop_delivery_require_pin'] = $required;

    $element['help'] = [
      '#markup' => '<p class="delivery-pin__help">Marca la puerta exacta para que el repartidor llegue sin llamarte.</p>',
    ];
    $element['actions'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['delivery-pin__actions']],
    ];
    $element['actions']['geolocate'] = [
      '#type' => 'html_tag',
      '#tag' => 'button',
      '#value' => 'Usar mi ubicación actual',
      '#attributes' => [
        'type' => 'button',
        'class' => ['button', 'delivery-pin__geolocate'],
        'data-delivery-pin-action' => 'geolocate',
      ],
    ];
    if ($this->search->available()) {
      $element['actions']['search'] = [
        '#type' => 'html_tag',
        '#tag' => 'button',
        '#value' => 'Buscar mi dirección en el mapa',
        '#attributes' => [
          'type' => 'button',
          'class' => ['button', 'delivery-pin__search'],
          'data-delivery-pin-action' => 'search',
          'title' => 'Búsqueda experimental en OpenStreetMap: revisa el punto en el mapa.',
        ],
      ];
    }
    $element['paste'] = [
      '#type' => 'html_tag',
      '#tag' => 'input',
      '#attributes' => [
        'type' => 'text',
        'class' => ['form-text', 'delivery-pin__paste'],
        'data-delivery-pin-action' => 'paste',
        'placeholder' => 'O pega aquí un enlace de Google Maps o WhatsApp, o "4.6097, -74.0817"',
        'aria-label' => 'Enlace de ubicación o coordenadas',
        'autocomplete' => 'off',
      ],
    ];
    $element['status'] = [
      '#markup' => '<p class="delivery-pin__status" data-delivery-pin-status aria-live="polite"></p>',
    ];
    $element['choices'] = [
      '#markup' => '<div class="delivery-pin__choices" data-delivery-pin-choices></div>',
    ];

    // Step "any": a 0.0000001 step makes Drupal check (value - min) / step
    // for an integer in floating point, and real coordinates such as
    // 4.5054005 fail it ("no es un número válido").
    $element['lat'] = [
      '#type' => 'number',
      '#title' => 'Latitud',
      '#default_value' => $lat,
      '#step' => 'any',
      '#min' => -90,
      '#max' => 90,
      '#size' => 12,
      '#attributes' => ['data-delivery-pin-lat' => ''],
      '#wrapper_attributes' => ['class' => ['delivery-pin__number']],
    ];
    $element['lon'] = [
      '#type' => 'number',
      '#title' => 'Longitud',
      '#default_value' => $lon,
      '#step' => 'any',
      '#min' => -180,
      '#max' => 180,
      '#size' => 12,
      '#attributes' => ['data-delivery-pin-lon' => ''],
      '#wrapper_attributes' => ['class' => ['delivery-pin__number']],
    ];

    $element['map'] = $point
      ? $this->maps->door($point, 'Tu entrega')
      : ['#markup' => '<p class="delivery-pin__empty">Aún no hay ubicación: el mapa aparece cuando la marques.</p>'];

    return $element;
  }

  /**
   * Requires both numbers when the form must have a pin.
   *
   * Not while the shipping pane recalculates rates: that happens as soon
   * as the address is typed, before the customer has reached the pin.
   */
  public static function validatePin(array &$element, FormStateInterface $form_state): void {
    if (empty($element['#shop_delivery_require_pin'])) {
      return;
    }
    if (!empty($form_state->getTriggeringElement()['#recalculate'])) {
      return;
    }
    $lat = (string) ($element['lat']['#value'] ?? '');
    $lon = (string) ($element['lon']['#value'] ?? '');
    if ($lat === '' || $lon === '') {
      $form_state->setError($element, 'Marca la ubicación de entrega: usa tu ubicación actual, pega un enlace de Google Maps o busca tu dirección.');
    }
  }

  /**
   * {@inheritdoc}
   */
  public function massageFormValues(array $values, array $form, FormStateInterface $form_state) {
    foreach ($values as $delta => $value) {
      // An empty box is an absent coordinate, not zero degrees.
      $values[$delta] = [
        'lat' => ($value['lat'] ?? '') === '' ? NULL : $value['lat'],
        'lon' => ($value['lon'] ?? '') === '' ? NULL : $value['lon'],
      ];
    }
    return $values;
  }

}
