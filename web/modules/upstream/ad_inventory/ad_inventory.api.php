<?php

/**
 * @file
 * Hooks provided by the ad_inventory module.
 */

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Cache\RefinableCacheableDependencyInterface;

/**
 * @addtogroup hooks
 * @{
 */

/**
 * Adds page context values for scoped bookings.
 *
 * A booking whose "Pages" field holds a value (for example "city:12") shows
 * only on pages whose scopes include it. Scopes describe the page, never the
 * reader: whatever they depend on must be declared as cacheability, and it
 * must be the same for every reader of the page.
 *
 * @param string[] $scopes
 *   The page's context values so far (a template may pass some in #scopes).
 * @param array $context
 *   Keys: slot (the AdSlotInterface), entity (the page's entity or NULL),
 *   route_match (the current RouteMatchInterface).
 * @param \Drupal\Core\Cache\RefinableCacheableDependencyInterface $cacheability
 *   Add what the scopes depend on (the page's entity, a "route" context).
 */
function hook_ad_inventory_scope_alter(array &$scopes, array $context, RefinableCacheableDependencyInterface $cacheability) {
  $node = $context['route_match']->getParameter('node');
  if ($node && $node->hasField('field_city') && !$node->get('field_city')->isEmpty()) {
    $scopes[] = 'city:' . $node->get('field_city')->target_id;
    $cacheability->addCacheableDependency($node);
    $cacheability->addCacheContexts(['route']);
  }
}

/**
 * Lets an account read a booking's report without "view ad booking reports".
 *
 * Results of every implementation are OR-ed with the permission.
 *
 * @param \Drupal\ad_inventory\Entity\AdBookingInterface $booking
 *   The booking.
 * @param \Drupal\Core\Session\AccountInterface $account
 *   The account asking.
 *
 * @return \Drupal\Core\Access\AccessResultInterface
 *   Allowed to grant; neutral otherwise.
 */
function hook_ad_inventory_report_access($booking, $account): AccessResultInterface {
  // An advertiser sees the bookings of their own creatives.
  $creative = $booking->creative();
  $own = $creative && (int) $creative->get('advertiser')->target_id === (int) $account->id();
  return AccessResult::allowedIf($own)->addCacheableDependency($booking)->cachePerUser();
}

/**
 * Alters the ad network provider plugin definitions.
 *
 * @param array $definitions
 *   Definitions keyed by plugin id.
 */
function hook_ad_inventory_provider_info_alter(array &$definitions) {
  // Let any administrator of the inventory edit pasted snippets.
  if (isset($definitions['snippet'])) {
    $definitions['snippet']['restricted'] = FALSE;
  }
}

/**
 * Alters the mail provider plugin definitions.
 *
 * @param array $definitions
 *   Definitions keyed by plugin id.
 */
function hook_ad_inventory_mail_provider_info_alter(array &$definitions) {
  unset($definitions['generic']);
}

/**
 * @} End of "addtogroup hooks".
 */
