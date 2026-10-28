<?php

declare(strict_types=1);

namespace Drupal\ad_inventory\Plugin\Derivative;

use Drupal\ad_inventory\Entity\AdSlot;
use Drupal\Component\Plugin\Derivative\DeriverBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\Discovery\ContainerDeriverInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * One block per web banner space.
 */
final class AdSlotDeriver extends DeriverBase implements ContainerDeriverInterface {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, $base_plugin_id): static {
    return new static($container->get('entity_type.manager'));
  }

  /**
   * {@inheritdoc}
   */
  public function getDerivativeDefinitions($base_plugin_definition): array {
    $this->derivatives = [];
    foreach ($this->entityTypeManager->getStorage('ad_slot')->loadMultiple() as $id => $slot) {
      if ($slot instanceof AdSlot && $slot->channel() === AdSlot::WEB) {
        $this->derivatives[$id] = [
          'admin_label' => $slot->label(),
          'config_dependencies' => ['config' => [$slot->getConfigDependencyName()]],
        ] + $base_plugin_definition;
      }
    }
    return $this->derivatives;
  }

}
