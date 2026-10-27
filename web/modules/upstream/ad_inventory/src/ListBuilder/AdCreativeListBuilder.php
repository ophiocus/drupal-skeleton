<?php

declare(strict_types=1);

namespace Drupal\ad_inventory\ListBuilder;

use Drupal\ad_inventory\Entity\AdCreativeInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;

/**
 * Lists creatives.
 */
final class AdCreativeListBuilder extends EntityListBuilder {

  /**
   * {@inheritdoc}
   */
  public function buildHeader() {
    return [
      'label' => $this->t('Creative'),
      'kind' => $this->t('Kind'),
      'advertiser' => $this->t('Advertiser'),
      'renditions' => $this->t('Formats'),
      'house' => $this->t('House'),
      'status' => $this->t('Active'),
    ] + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity) {
    assert($entity instanceof AdCreativeInterface);
    return [
      'label' => $entity->label(),
      'kind' => $entity->kind(),
      'advertiser' => $entity->advertiserName() ?: '–',
      'renditions' => implode(', ', $entity->renditionFormats()) ?: '–',
      'house' => $entity->isHouse() ? $this->t('Yes') : $this->t('No'),
      'status' => $entity->isPublished() ? $this->t('Yes') : $this->t('No'),
    ] + parent::buildRow($entity);
  }

}
