<?php

declare(strict_types=1);

namespace Drupal\ad_inventory\Element;

use Drupal\ad_inventory\Entity\AdFormat;
use Drupal\ad_inventory\Entity\AdSlot;
use Drupal\ad_inventory\Entity\AdSlotInterface;
use Drupal\ad_inventory\Overrides;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Attribute\FormElement;
use Drupal\Core\Render\Element\FormElementBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Per-page slot controls, for any admin form.
 *
 * Usage:
 * @code
 * $form['ads'] = [
 *   '#type' => 'ad_inventory_overrides',
 *   '#title' => t('Banners on this page'),
 *   '#default_value' => $entity->get('field_ads')->first()?->overrides() ?? [],
 *   // Optional: only these slots.
 *   '#slots' => ['event_gap', 'event_end'],
 *   '#access' => $account->hasPermission('use ad inventory overrides'),
 * ];
 * @endcode
 * The value is the controls to store, keyed by slot id, holding only what
 * differs from each slot (an empty array when the page follows every slot).
 */
#[FormElement('ad_inventory_overrides')]
class AdOverridesElement extends FormElementBase {

  /**
   * {@inheritdoc}
   */
  public function getInfo() {
    return [
      '#input' => TRUE,
      '#tree' => TRUE,
      '#slots' => NULL,
      '#default_value' => [],
      '#process' => [[static::class, 'processOverrides']],
      '#element_validate' => [[static::class, 'validateOverrides']],
      '#theme_wrappers' => ['fieldset'],
    ];
  }

  /**
   * The web slots the element offers, keyed by id.
   *
   * @return \Drupal\ad_inventory\Entity\AdSlotInterface[]
   *   Slots.
   */
  protected static function slots(array $element): array {
    $slots = [];
    foreach (AdSlot::loadMultiple(is_array($element['#slots']) ? $element['#slots'] : NULL) as $id => $slot) {
      if ($slot->channel() === 'web') {
        $slots[$id] = $slot;
      }
    }
    return $slots;
  }

  /**
   * The format options of a slot: its own format first, then alternatives.
   */
  protected static function formatOptions(string $own, AdSlotInterface $slot): array {
    $ids = array_values(array_unique(array_filter(array_merge([$own], $slot->alternativeFormats()))));
    $options = [];
    foreach (AdFormat::loadMultiple($ids) as $id => $format) {
      $options[$id] = sprintf('%s (%d×%d)', $format->label(), $format->width(), $format->height());
    }
    return $options;
  }

  /**
   * Builds one row of controls per slot.
   */
  public static function processOverrides(array &$element, FormStateInterface $form_state, array &$complete_form): array {
    $values = is_array($element['#value'] ?? NULL) ? $element['#value'] : [];
    $slots = static::slots($element);
    if (!$slots) {
      $element['empty'] = [
        '#markup' => new TranslatableMarkup('No ad slots are defined yet.'),
      ];
      return $element;
    }
    foreach ($slots as $id => $slot) {
      $override = Overrides::apply(is_array($values[$id] ?? NULL) ? $values[$id] : [], $slot->desktopFormat(), $slot->phoneFormat());
      $element[$id] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['container-inline', 'ad-inventory-overrides__row']],
      ];
      // Always sent, so an unchecked box still names its slot.
      $element[$id]['present'] = [
        '#type' => 'hidden',
        '#value' => '1',
      ];
      $element[$id]['show'] = [
        '#type' => 'checkbox',
        '#title' => $slot->label(),
        '#default_value' => !$override['off'],
      ];
      $desktop = static::formatOptions($slot->desktopFormat(), $slot);
      if (count($desktop) > 1) {
        $element[$id]['format'] = [
          '#type' => 'select',
          '#title' => new TranslatableMarkup('Desktop'),
          '#options' => $desktop,
          '#default_value' => $override['desktop'],
        ];
      }
      $phone = static::formatOptions($slot->phoneFormat(), $slot);
      if (count($phone) > 1) {
        $element[$id]['phone_format'] = [
          '#type' => 'select',
          '#title' => new TranslatableMarkup('Phone'),
          '#options' => $phone,
          '#default_value' => $override['phone'],
        ];
      }
      if ($slot->isRepeatable()) {
        $element[$id]['count'] = [
          '#type' => 'number',
          '#title' => new TranslatableMarkup('How many'),
          '#min' => 0,
          '#size' => 4,
          '#default_value' => $override['count'],
          '#description' => new TranslatableMarkup('Empty: as many as the page prints.'),
        ];
      }
    }
    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public static function valueCallback(&$element, $input, FormStateInterface $form_state) {
    if (!is_array($input)) {
      return is_array($element['#default_value'] ?? NULL) ? $element['#default_value'] : [];
    }
    $slots = [];
    foreach (static::slots($element) as $id => $slot) {
      $slots[$id] = [
        'desktop' => $slot->desktopFormat(),
        'phone' => $slot->phoneFormat(),
        'desktop_options' => array_keys(static::formatOptions($slot->desktopFormat(), $slot)),
        'phone_options' => array_keys(static::formatOptions($slot->phoneFormat(), $slot)),
        'repeatable' => $slot->isRepeatable(),
      ];
      // An unchecked checkbox is absent from the input.
      if (is_array($input[$id] ?? NULL) && !array_key_exists('show', $input[$id])) {
        $input[$id]['show'] = 0;
      }
    }
    return Overrides::normalize($input, $slots);
  }

  /**
   * Puts the normalised controls back over what the child inputs left.
   */
  public static function validateOverrides(array &$element, FormStateInterface $form_state, array &$complete_form): void {
    $form_state->setValueForElement($element, is_array($element['#value'] ?? NULL) ? $element['#value'] : []);
  }

}
