<?php

declare(strict_types=1);

namespace Drupal\ad_inventory\Plugin\Field\FieldWidget;

use Drupal\ad_inventory\Plugin\Field\FieldType\AdOverridesItem;
use Drupal\Core\Field\Attribute\FieldWidget;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * The per-page slot controls as a field widget.
 *
 * The widget is the `ad_inventory_overrides` form element; a site that keeps
 * the controls elsewhere in its admin can print the element itself.
 */
#[FieldWidget(
  id: 'ad_inventory_overrides_default',
  label: new TranslatableMarkup('Ad slot controls'),
  field_types: ['ad_inventory_overrides'],
)]
class AdOverridesWidget extends WidgetBase {

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {
    $element['value'] = $element + [
      '#type' => 'ad_inventory_overrides',
      '#default_value' => AdOverridesItem::decode((string) ($items[$delta]->value ?? '')),
    ];
    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public function massageFormValues(array $values, array $form, FormStateInterface $form_state) {
    foreach ($values as $delta => $value) {
      $values[$delta]['value'] = AdOverridesItem::encode(is_array($value['value'] ?? NULL) ? $value['value'] : []);
    }
    return $values;
  }

}
