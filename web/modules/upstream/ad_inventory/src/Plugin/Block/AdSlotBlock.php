<?php

declare(strict_types=1);

namespace Drupal\ad_inventory\Plugin\Block;

use Drupal\ad_inventory\Element\AdSlotElement;
use Drupal\ad_inventory\Plugin\Derivative\AdSlotBlockDeriver;
use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * One block per web slot, placed through block layout.
 *
 * Themes ship their placements in their own config/install (a block names
 * its theme, and a module cannot depend on a theme).
 */
#[Block(
  id: 'ad_inventory_slot',
  admin_label: new TranslatableMarkup('Ad slot'),
  category: new TranslatableMarkup('Advertising'),
  deriver: AdSlotBlockDeriver::class,
)]
class AdSlotBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function build() {
    return AdSlotElement::build((string) $this->getDerivativeId(), NULL, 0, [], 'block');
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTags() {
    return array_merge(parent::getCacheTags(), ['config:ad_inventory.slot.' . $this->getDerivativeId()]);
  }

}
