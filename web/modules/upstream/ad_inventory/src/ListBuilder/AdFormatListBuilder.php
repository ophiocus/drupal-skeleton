<?php

declare(strict_types=1);

namespace Drupal\ad_inventory\ListBuilder;

use Drupal\ad_inventory\Entity\AdFormatInterface;
use Drupal\Core\Config\Entity\ConfigEntityListBuilder;
use Drupal\Core\Entity\EntityInterface;

/**
 * Lists ad formats.
 */
final class AdFormatListBuilder extends ConfigEntityListBuilder {

  /**
   * {@inheritdoc}
   */
  public function buildHeader() {
    return [
      'label' => $this->t('Format'),
      'id' => $this->t('Machine name'),
      'size' => $this->t('Size'),
      'upload' => $this->t('Upload at'),
      'network' => $this->t('Network unit by viewport'),
    ] + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity) {
    assert($entity instanceof AdFormatInterface);
    $network = [];
    foreach ($entity->networkSizes() as $row) {
      $network[] = sprintf('≥%d: %d×%d', $row['min_width'], $row['width'], $row['height']);
    }
    return [
      'label' => $entity->label(),
      'id' => $entity->id(),
      'size' => sprintf('%d×%d', $entity->width(), $entity->height()),
      'upload' => sprintf('%d×%d', $entity->width() * 2, $entity->height() * 2),
      'network' => $network ? implode(', ', $network) : '–',
    ] + parent::buildRow($entity);
  }

}
