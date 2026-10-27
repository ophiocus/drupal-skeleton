<?php

declare(strict_types=1);

namespace Drupal\ad_inventory\ListBuilder;

use Drupal\ad_inventory\Entity\AdBookingInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;
use Drupal\Core\Url;

/**
 * Lists bookings, latest first day first.
 */
final class AdBookingListBuilder extends EntityListBuilder {

  /**
   * {@inheritdoc}
   */
  protected function getEntityIds() {
    return $this->getStorage()->getQuery()
      ->accessCheck(TRUE)
      ->sort('start_day', 'DESC')
      ->sort('id', 'DESC')
      ->pager($this->limit)
      ->execute();
  }

  /**
   * {@inheritdoc}
   */
  public function buildHeader() {
    return [
      'label' => $this->t('Booking'),
      'creative' => $this->t('Creative'),
      'slots' => $this->t('Slots'),
      'days' => $this->t('Days'),
      'state' => $this->t('State'),
    ] + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity) {
    assert($entity instanceof AdBookingInterface);
    return [
      'label' => $entity->label(),
      'creative' => $entity->creative()?->label() ?? '–',
      'slots' => implode(', ', $entity->slotValues()),
      'days' => $entity->start() . ' – ' . $entity->end(),
      'state' => $entity->state() . ($entity->reason() !== '' ? ' (' . $entity->reason() . ')' : ''),
    ] + parent::buildRow($entity);
  }

  /**
   * {@inheritdoc}
   */
  public function getDefaultOperations(EntityInterface $entity, ?CacheableMetadata $cacheability = NULL) {
    $operations = parent::getDefaultOperations($entity, $cacheability);
    assert($entity instanceof AdBookingInterface);
    if ($entity->state() !== 'approved' && $entity->access('approve')) {
      $operations['approve'] = ['title' => $this->t('Approve'), 'url' => $entity->toUrl('approve-form'), 'weight' => 15];
    }
    if ($entity->state() === 'approved' && $entity->access('stop')) {
      $operations['stop'] = ['title' => $this->t('Stop'), 'url' => $entity->toUrl('stop-form'), 'weight' => 16];
    }
    $report = Url::fromRoute('ad_inventory.booking_report', ['ad_booking' => $entity->id()]);
    if ($report->access()) {
      $operations['report'] = ['title' => $this->t('Report'), 'url' => $report, 'weight' => 17];
    }
    return $operations;
  }

}
