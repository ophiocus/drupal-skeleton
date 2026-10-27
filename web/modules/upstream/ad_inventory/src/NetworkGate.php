<?php

declare(strict_types=1);

namespace Drupal\ad_inventory;

use Drupal\Core\Site\Settings;

/**
 * Whether network units may serve here, and the accounts they serve for.
 *
 * Fail closed: network serving is off unless the environment turns it on
 * ($settings['ad_inventory_network'] = TRUE, or AD_INVENTORY_NETWORK=1), so a
 * local copy, CI or a fresh build never requests a network unit. Account ids
 * come from $settings['ad_inventory_accounts'][<provider id>] or each
 * provider's environment variable, never from configuration. Both are read
 * when asked, never kept.
 */
final class NetworkGate {

  /**
   * The environment variable that turns network serving on.
   */
  public const ENV = 'AD_INVENTORY_NETWORK';

  /**
   * Whether network units may be printed at all.
   */
  public function enabled(): bool {
    $setting = Settings::get('ad_inventory_network');
    if ($setting !== NULL) {
      return (bool) $setting;
    }
    $env = getenv(self::ENV);
    return is_string($env) && in_array(strtolower(trim($env)), ['1', 'true', 'yes', 'on'], TRUE);
  }

  /**
   * A provider's account id, or NULL.
   *
   * @param string $provider_id
   *   The ad_provider entity id.
   * @param string $env
   *   The provider plugin's environment variable, or ''.
   */
  public function account(string $provider_id, string $env = ''): ?string {
    $accounts = (array) Settings::get('ad_inventory_accounts', []);
    $value = $accounts[$provider_id] ?? NULL;
    if (($value === NULL || $value === '') && $env !== '') {
      $value = getenv($env);
    }
    $value = is_string($value) ? trim($value) : '';
    return $value === '' ? NULL : $value;
  }

}
