<?php

declare(strict_types=1);

namespace Drupal\ad_inventory\ListBuilder;

use Drupal\ad_inventory\Entity\AdSlotInterface;
use Drupal\Core\Config\Entity\ConfigEntityListBuilder;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Url;

/**
 * Lists ad slots.
 */
final class AdSlotListBuilder extends ConfigEntityListBuilder {

  /**
   * {@inheritdoc}
   */
  public function buildHeader() {
    return [
      'label' => $this->t('Slot'),
      'id' => $this->t('Machine name'),
      'channel' => $this->t('Channel'),
      'formats' => $this->t('Desktop / phone'),
      'sources' => $this->t('Filled by'),
      'group' => $this->t('Group'),
    ] + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity) {
    assert($entity instanceof AdSlotInterface);
    return [
      'label' => $entity->label(),
      'id' => $entity->id(),
      'channel' => $entity->channel(),
      'formats' => $entity->desktopFormat() . ' / ' . $entity->phoneFormat(),
      'sources' => implode(', ', $entity->sources()),
      'group' => $entity->group() ?: '–',
    ] + parent::buildRow($entity);
  }

  /**
   * {@inheritdoc}
   */
  public function getDefaultOperations(EntityInterface $entity, ?CacheableMetadata $cacheability = NULL) {
    $operations = parent::getDefaultOperations($entity, $cacheability);
    if ($entity instanceof AdSlotInterface && $entity->channel() === 'email' && $entity->access('update')) {
      $operations['email'] = [
        'title' => $this->t('Email snippets'),
        'url' => Url::fromRoute('ad_inventory.email_snippets', ['ad_slot' => $entity->id()]),
        'weight' => 20,
      ];
    }
    return $operations;
  }

}
