<?php

declare(strict_types=1);

namespace Drupal\ad_inventory\ListBuilder;

use Drupal\ad_inventory\Entity\AdProviderInterface;
use Drupal\Core\Config\Entity\ConfigEntityListBuilder;
use Drupal\Core\Entity\EntityInterface;

/**
 * Lists network providers in fill order.
 */
final class AdProviderListBuilder extends ConfigEntityListBuilder {

  /**
   * {@inheritdoc}
   */
  public function buildHeader() {
    return [
      'label' => $this->t('Provider'),
      'plugin' => $this->t('Network'),
      'weight' => $this->t('Fill order'),
      'status' => $this->t('Enabled'),
      'account' => $this->t('Account'),
    ] + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity) {
    assert($entity instanceof AdProviderInterface);
    $plugin = $entity->plugin();
    $account = '–';
    if ($plugin !== NULL && $plugin->requiresAccount()) {
      $account = self::mask($plugin->account());
    }
    return [
      'label' => $entity->label(),
      'plugin' => $entity->pluginId(),
      'weight' => $entity->weight(),
      'status' => $entity->status() ? $this->t('Yes') : $this->t('No'),
      'account' => $account,
    ] + parent::buildRow($entity);
  }

  /**
   * An account id shown by its last four characters only.
   */
  public static function mask(?string $account): string {
    if ($account === NULL || $account === '') {
      return 'missing';
    }
    return '…' . substr($account, -4);
  }

}
