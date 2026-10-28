<?php

declare(strict_types=1);

namespace Drupal\ad_inventory\Plugin\Block;

use Drupal\ad_inventory\Plugin\Derivative\AdSlotDeriver;
use Drupal\ad_inventory\SlotBuilder;
use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * A banner space, placed through block layout.
 *
 * One space may have two placements, one shown on wide screens and one on
 * phones, so it can sit beside the map on desktop and under it on a phone.
 * The block renders nothing while nothing approved fills the space.
 */
#[Block(
  id: 'ad_inventory_slot',
  admin_label: new TranslatableMarkup('Banner space'),
  category: new TranslatableMarkup('Advertising'),
  deriver: AdSlotDeriver::class,
)]
final class AdSlotBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * Builds spaces.
   */
  protected SlotBuilder $slots;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = new static($configuration, $plugin_id, $plugin_definition);
    $instance->slots = $container->get('ad_inventory.slot_builder');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return ['devices' => SlotBuilder::BOTH, 'label_display' => '0'];
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state): array {
    $form['devices'] = [
      '#type' => 'radios',
      '#title' => $this->t('Show on'),
      '#options' => [
        SlotBuilder::BOTH => $this->t('Every screen'),
        SlotBuilder::DESKTOP => $this->t('Wide screens only'),
        SlotBuilder::PHONE => $this->t('Phones only'),
      ],
      '#default_value' => $this->configuration['devices'],
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state): void {
    $this->configuration['devices'] = (string) $form_state->getValue('devices');
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    return $this->slots->build((string) $this->getDerivativeId(), (string) $this->configuration['devices']);
  }

}
