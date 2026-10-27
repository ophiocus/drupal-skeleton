<?php

declare(strict_types=1);

namespace Drupal\ad_inventory\Plugin\Derivative;

use Drupal\Component\Plugin\Derivative\DeriverBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\Discovery\ContainerDeriverInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Derives one block per web ad slot.
 */
final class AdSlotBlockDeriver extends DeriverBase implements ContainerDeriverInterface {

  use StringTranslationTrait;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, $base_plugin_id) {
    return new self($container->get('entity_type.manager'));
  }

  /**
   * {@inheritdoc}
   */
  public function getDerivativeDefinitions($base_plugin_definition) {
    $this->derivatives = [];
    foreach ($this->entityTypeManager->getStorage('ad_slot')->loadMultiple() as $id => $slot) {
      if ($slot->channel() !== 'web') {
        continue;
      }
      $this->derivatives[$id] = [
        'admin_label' => $this->t('Ad slot: @label', ['@label' => $slot->label()]),
        'config_dependencies' => [
          'config' => [$slot->getConfigDependencyName()],
        ],
      ] + $base_plugin_definition;
    }
    return $this->derivatives;
  }

}
