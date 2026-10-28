<?php

declare(strict_types=1);

namespace Drupal\ad_inventory\ListBuilder;

use Drupal\ad_inventory\Entity\AdProvider;
use Drupal\Core\Config\Entity\ConfigEntityListBuilder;
use Drupal\Core\Entity\EntityInterface;

/**
 * Lists ad providers in their order.
 */
final class AdProviderListBuilder extends ConfigEntityListBuilder {

  /**
   * {@inheritdoc}
   */
  public function buildHeader(): array {
    $header['label'] = $this->t('Provider');
    $header['plugin'] = $this->t('Type');
    $header['state'] = $this->t('State');
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array {
    assert($entity instanceof AdProvider);
    $row['label'] = $entity->label();
    $row['plugin'] = $entity->pluginId();
    $row['state'] = $entity->status() ? $entity->state() : $this->t('disabled');
    return $row + parent::buildRow($entity);
  }

}
