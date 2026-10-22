<?php

declare(strict_types=1);

namespace Drupal\site_seed\Handler;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\site_seed\SeedContext;

/**
 * Section `shipping_methods:`, created once and never converged.
 *
 * A shipping method's settings (fees, zones) are the shop's to change in
 * production, and a deploy must not put the seed's placeholders back over
 * them. Each row: name, plugin, configuration, stores (names).
 */
final class ShippingMethodHandler implements SeedHandlerInterface {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function section(): string {
    return 'shipping_methods';
  }

  /**
   * {@inheritdoc}
   */
  public function weight(): int {
    return 40;
  }

  /**
   * {@inheritdoc}
   */
  public function applies(): bool {
    return $this->entityTypeManager->hasDefinition('commerce_shipping_method');
  }

  /**
   * {@inheritdoc}
   */
  public function converge(array $rows, SeedContext $context): void {
    $storage = $this->entityTypeManager->getStorage('commerce_shipping_method');
    $stores = $context->stores ?? [];
    if ($stores === [] && $this->entityTypeManager->hasDefinition('commerce_store')) {
      foreach ($this->entityTypeManager->getStorage('commerce_store')->loadMultiple() as $store) {
        if ($store instanceof ContentEntityInterface) {
          $stores[(string) $store->label()] = $store;
        }
      }
    }
    foreach ($rows as $row) {
      if ($storage->loadByProperties(['name' => $row['name']])) {
        $context->count('unchanged');
        continue;
      }
      $ids = [];
      foreach ($row['stores'] ?? [] as $name) {
        if (isset($stores[$name])) {
          $ids[] = (int) $stores[$name]->id();
        }
      }
      $context->save($storage->create([
        'name' => $row['name'],
        'stores' => $ids,
        'plugin' => [
          'target_plugin_id' => $row['plugin'],
          'target_plugin_configuration' => $row['configuration'] ?? [],
        ],
        'status' => TRUE,
      ]));
      $context->count('shipping_methods');
    }
  }

}
