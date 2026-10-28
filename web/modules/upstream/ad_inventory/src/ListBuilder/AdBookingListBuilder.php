<?php

declare(strict_types=1);

namespace Drupal\ad_inventory\ListBuilder;

use Drupal\ad_inventory\Entity\AdBooking;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;

/**
 * Lists banner bookings, newest first.
 */
final class AdBookingListBuilder extends EntityListBuilder {

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
    $header['label'] = $this->t('Booking');
    $header['slots'] = $this->t('Spaces');
    $header['scope'] = $this->t('Scope');
    $header['days'] = $this->t('Days');
    $header['state'] = $this->t('State');
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array {
    assert($entity instanceof AdBooking);
    $row['label'] = $entity->toLink(NULL, 'edit-form');
    $row['slots'] = implode(', ', $entity->slots());
    $row['scope'] = $entity->scope() !== '' ? $entity->scope() : $this->t('every page');
    $row['days'] = $entity->startDay() . ' → ' . $entity->endDay();
    $row['state'] = $entity->state();
    return $row + parent::buildRow($entity);
  }

}
