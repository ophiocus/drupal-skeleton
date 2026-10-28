<?php

declare(strict_types=1);

namespace Drupal\ad_inventory\Plugin;

use Drupal\ad_inventory\Entity\AdFormat;
use Drupal\ad_inventory\Entity\AdSlot;
use Drupal\Component\Plugin\ConfigurableInterface;
use Drupal\Component\Plugin\PluginInspectionInterface;
use Drupal\Core\Form\FormStateInterface;

/**
 * An ad network, or any source of third-party units.
 *
 * A provider never sees the reader: it returns the markup of an inert unit,
 * the same for everyone, which the browser activates only after the reader's
 * advertising consent. Loader scripts are listed separately so the browser
 * loads each one once, after consent.
 */
interface AdProviderInterface extends PluginInspectionInterface, ConfigurableInterface {

  /**
   * The unit's markup for a space and format, or '' when it has none there.
   *
   * Scripts in it do not run until the browser fills the space.
   */
  public function unit(AdSlot $slot, AdFormat $format): string;

  /**
   * Script URLs the units need, loaded once per page after consent.
   *
   * @return string[]
   *   Absolute https URLs.
   */
  public function loaders(): array;

  /**
   * Lines this provider adds to /ads.txt.
   *
   * @return string[]
   *   Lines, each "domain, account id, DIRECT|RESELLER[, authority id]".
   */
  public function adsTxtLines(): array;

  /**
   * A tag for the page head that proves site ownership, or ''.
   */
  public function verificationTag(): string;

  /**
   * The settings form.
   *
   * @param array<string, mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Its state.
   *
   * @return array<string, mixed>
   *   Elements.
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array;

  /**
   * The settings to store from the submitted form values.
   *
   * @param array<string, mixed> $values
   *   Submitted values of the settings form.
   *
   * @return array<string, mixed>
   *   Settings.
   */
  public function settingsFromForm(array $values): array;

}
