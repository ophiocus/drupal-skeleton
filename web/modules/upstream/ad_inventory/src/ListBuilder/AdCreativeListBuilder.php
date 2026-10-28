<?php

declare(strict_types=1);

namespace Drupal\ad_inventory\ListBuilder;

use Drupal\ad_inventory\Entity\AdCreative;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;

/**
 * Lists banner creatives, newest first.
 */
final class AdCreativeListBuilder extends EntityListBuilder {

  /**
   * {@inheritdoc}
   */
  protected function getEntityIds(): array {
    return $this->getStorage()->getQuery()->accessCheck(TRUE)->sort('id', 'DESC')->pager(50)->execute();
  }

  /**
   * {@inheritdoc}
   */
  public function buildHeader(): array {
    $header['label'] = $this->t('Creative');
    $header['advertiser'] = $this->t('Advertiser');
    $header['kind'] = $this->t('Kind');
    $header['house'] = $this->t('House');
    $header['formats'] = $this->t('Formats');
    $header['state'] = $this->t('State');
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array {
    assert($entity instanceof AdCreative);
    $row['label'] = $entity->toLink(NULL, 'edit-form');
    $row['advertiser'] = (string) $entity->get('advertiser')->value;
    $row['kind'] = $entity->kind();
    $row['house'] = $entity->isHouse() ? $this->t('yes') : '';
    $row['formats'] = implode(', ', array_keys($entity->renditions()));
    $row['state'] = (string) $entity->get('state')->value;
    return $row + parent::buildRow($entity);
  }

}
