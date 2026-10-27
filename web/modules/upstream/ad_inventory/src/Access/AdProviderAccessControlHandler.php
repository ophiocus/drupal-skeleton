<?php

declare(strict_types=1);

namespace Drupal\ad_inventory\Access;

use Drupal\ad_inventory\Entity\AdProviderInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Providers need "administer ad inventory"; pasted snippets need more.
 */
final class AdProviderAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account) {
    $result = parent::checkAccess($entity, $operation, $account);
    if ($operation !== 'view' && $operation !== 'view label' && $entity instanceof AdProviderInterface && $entity->isRestricted()) {
      $result = $result->andIf(AccessResult::allowedIfHasPermission($account, 'administer pasted ad snippets'));
    }
    return $result->addCacheableDependency($entity);
  }

}
