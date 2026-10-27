<?php

declare(strict_types=1);

namespace Drupal\ad_inventory\Entity;

use Drupal\ad_inventory\Plugin\AdProvider\AdProviderPluginInterface;
use Drupal\Core\Config\Entity\ConfigEntityInterface;

/**
 * A configured network provider: one instance of an AdProvider plugin.
 */
interface AdProviderInterface extends ConfigEntityInterface {

  /**
   * The plugin id.
   */
  public function pluginId(): string;

  /**
   * The place in fill order; lower fills first.
   */
  public function weight(): int;

  /**
   * The plugin settings.
   */
  public function settings(): array;

  /**
   * The configured plugin, or NULL when its code is gone.
   */
  public function plugin(): ?AdProviderPluginInterface;

  /**
   * Whether editing this provider needs "administer pasted ad snippets".
   */
  public function isRestricted(): bool;

}
