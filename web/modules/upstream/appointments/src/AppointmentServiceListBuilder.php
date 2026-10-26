<?php

declare(strict_types=1);

namespace Drupal\appointments;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;

/**
 * The list of bookable services.
 */
final class AppointmentServiceListBuilder extends EntityListBuilder {

  /**
   * {@inheritdoc}
   */
  protected function getEntityIds(): array {
    return $this->getStorage()->getQuery()
      ->accessCheck(TRUE)
      ->sort('weight')
      ->sort('label')
      ->execute();
  }

  /**
   * {@inheritdoc}
   */
  public function buildHeader(): array {
    return [
      'label' => $this->t('Name'),
      'key' => $this->t('Key'),
      'duration' => $this->t('Duration'),
      'price' => $this->t('Price'),
      'status' => $this->t('Bookable'),
    ] + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array {
    /** @var \Drupal\appointments\Entity\AppointmentServiceInterface $entity */
    return [
      'label' => $entity->label(),
      'key' => $entity->getKey(),
      'duration' => $this->t('@min min + @buffer min', [
        '@min' => $entity->getDuration(),
        '@buffer' => $entity->getBuffer(),
      ]),
      'price' => $entity->getPrice(),
      'status' => $entity->isPublished() ? $this->t('Yes') : $this->t('No'),
    ] + parent::buildRow($entity);
  }

}
