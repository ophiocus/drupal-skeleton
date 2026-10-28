<?php

declare(strict_types=1);

namespace Drupal\ad_inventory\ListBuilder;

use Drupal\ad_inventory\Entity\AdSlot;
use Drupal\Core\Config\Entity\ConfigEntityListBuilder;
use Drupal\Core\Entity\EntityInterface;

/**
 * Lists banner spaces.
 */
final class AdSlotListBuilder extends ConfigEntityListBuilder {

  /**
   * {@inheritdoc}
   */
  public function buildHeader(): array {
    $header['label'] = $this->t('Space');
    $header['id'] = $this->t('Machine name');
    $header['channel'] = $this->t('Channel');
    $header['formats'] = $this->t('Desktop / phone');
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array {
    assert($entity instanceof AdSlot);
    $row['label'] = $entity->label();
    $row['id'] = $entity->id();
    $row['channel'] = $entity->channel();
    $row['formats'] = $entity->desktopFormat() . ' / ' . $entity->phoneFormat();
    return $row + parent::buildRow($entity);
  }

}
