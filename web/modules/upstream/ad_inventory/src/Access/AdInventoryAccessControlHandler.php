<?php

declare(strict_types=1);

namespace Drupal\ad_inventory\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Access to creatives and bookings.
 *
 * Creatives: "manage ad creatives". Bookings: "manage ad bookings" to create
 * and edit, "approve ad bookings" to approve and stop. Reading either is also
 * open to "view ad booking reports". "administer ad inventory" may do all.
 */
final class AdInventoryAccessControlHandler extends EntityAccessControlHandler {

  /**
   * The permission that manages this entity type.
   */
  private function managePermission(): string {
    return $this->entityTypeId === 'ad_creative' ? 'manage ad creatives' : 'manage ad bookings';
  }

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account) {
    $admin = 'administer ad inventory';
    $manage = $this->managePermission();
    return match ($operation) {
      'view' => AccessResult::allowedIfHasPermissions($account, [$admin, $manage, 'view ad booking reports'], 'OR'),
      'update', 'delete' => AccessResult::allowedIfHasPermissions($account, [$admin, $manage], 'OR'),
      'approve', 'stop' => $this->entityTypeId === 'ad_booking'
        ? AccessResult::allowedIfHasPermissions($account, [$admin, 'approve ad bookings'], 'OR')
        : AccessResult::neutral(),
      default => AccessResult::neutral(),
    };
  }

  /**
   * {@inheritdoc}
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL) {
    return AccessResult::allowedIfHasPermissions($account, ['administer ad inventory', $this->managePermission()], 'OR');
  }

}
