<?php

declare(strict_types=1);

namespace Drupal\appointments;

use Drupal\appointments\Entity\AppointmentRoomInterface;
use Drupal\Core\Config\Entity\ConfigEntityListBuilder;
use Drupal\Core\Entity\EntityInterface;

/**
 * The list of rooms.
 */
final class AppointmentRoomListBuilder extends ConfigEntityListBuilder {

  /**
   * {@inheritdoc}
   */
  public function buildHeader(): array {
    return [
      'label' => $this->t('Room'),
      'services' => $this->t('Services'),
      'status' => $this->t('Bookable'),
    ] + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array {
    assert($entity instanceof AppointmentRoomInterface);
    $keys = $entity->getServiceKeys();
    return [
      'label' => $entity->label(),
      'services' => $keys ? implode(', ', $keys) : $this->t('All'),
      'status' => $entity->status() ? $this->t('Yes') : $this->t('No'),
    ] + parent::buildRow($entity);
  }

  /**
   * {@inheritdoc}
   */
  public function render(): array {
    $build = parent::render();
    $build['table']['#empty'] = $this->t('No rooms: the calendar is one room, and the Settings tab sets how many appointments it takes at once.');
    return $build;
  }

}
