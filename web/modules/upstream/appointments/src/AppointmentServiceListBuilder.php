<?php

declare(strict_types=1);

namespace Drupal\appointments;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The list of bookable services.
 *
 * One row per service, in the content language (Calendar::services()).
 */
final class AppointmentServiceListBuilder extends EntityListBuilder {

  public function __construct(
    EntityTypeInterface $entity_type,
    EntityStorageInterface $storage,
    protected readonly Calendar $calendar,
  ) {
    parent::__construct($entity_type, $storage);
  }

  /**
   * {@inheritdoc}
   */
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type): static {
    return new static(
      $entity_type,
      $container->get('entity_type.manager')->getStorage($entity_type->id()),
      $container->get('appointments.calendar'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function load(): array {
    return array_values($this->calendar->services(FALSE));
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
