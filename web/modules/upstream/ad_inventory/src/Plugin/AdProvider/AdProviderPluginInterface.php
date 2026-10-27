<?php

declare(strict_types=1);

namespace Drupal\ad_inventory\Plugin\AdProvider;

use Drupal\Component\Plugin\PluginInspectionInterface;

/**
 * An ad network: what it needs, what it prints, what it allows beside it.
 *
 * An instance is configured by an `ad_provider` config entity:
 * $configuration['provider_id'] is the entity id, $configuration['settings']
 * its stored settings. Account ids never live in configuration: account()
 * reads settings.php or the environment (NetworkGate).
 */
interface AdProviderPluginInterface extends PluginInspectionInterface {

  /**
   * Settings an instance starts with.
   */
  public function defaultSettings(): array;

  /**
   * The instance's settings, defaults filled in.
   */
  public function settings(): array;

  /**
   * Form elements for the settings, keyed like defaultSettings().
   *
   * @param array $settings
   *   The current settings.
   */
  public function settingsForm(array $settings): array;

  /**
   * The fields a slot fills in for its unit of this provider.
   *
   * @return array<string, string>
   *   Labels keyed by field name.
   */
  public function unitFields(): array;

  /**
   * Whether the provider serves nothing without an account id.
   */
  public function requiresAccount(): bool;

  /**
   * The account id from settings.php or the environment, or NULL.
   */
  public function account(): ?string;

  /**
   * The script the browser loads once, after the grant, or ''.
   */
  public function loaderUrl(): string;

  /**
   * The unit's markup, printed verbatim inside the reserved box.
   *
   * @param array<string, string> $unit
   *   The slot's unit field values.
   *
   * @return string
   *   HTML, or '' when the unit is incomplete.
   */
  public function unitMarkup(array $unit): string;

  /**
   * The ads.txt lines the provider needs.
   *
   * @return string[]
   *   Lines, possibly none.
   */
  public function adsTxtLines(): array;

  /**
   * Whether a missing ads.txt line stops the provider's every request.
   */
  public function adsTxtLineRequired(): bool;

  /**
   * The consent the provider needs, for people.
   */
  public function consent(): string;

  /**
   * The labels the provider allows above its units; empty allows any.
   *
   * @return string[]
   *   Labels.
   */
  public function allowedLabels(): array;

  /**
   * How many units of this provider one page may carry; 0 for no limit.
   */
  public function maxUnits(): int;

  /**
   * Whether a house creative may sit behind an unfilled unit.
   */
  public function houseBehind(): bool;

  /**
   * The format kinds the provider serves (ProviderPolicy::KINDS).
   *
   * @return string[]
   *   Kinds.
   */
  public function kinds(): array;

  /**
   * The kinds no other provider may serve on the same site.
   *
   * @return string[]
   *   Kinds.
   */
  public function forbids(): array;

  /**
   * A tag for every page's head (site verification), or NULL.
   *
   * The same for every reader, so it never varies the page cache.
   *
   * @return array|null
   *   An html_head element.
   */
  public function verificationTag(): ?array;

  /**
   * The browser handler that fills a unit: "generic" or "adsbygoogle".
   */
  public function handler(): string;

  /**
   * The reporting channel of a unit, or ''.
   *
   * @param array<string, string> $unit
   *   The slot's unit field values.
   */
  public function reportingChannel(array $unit): string;

}
