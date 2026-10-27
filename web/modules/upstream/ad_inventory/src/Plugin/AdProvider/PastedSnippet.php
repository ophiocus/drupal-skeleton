<?php

declare(strict_types=1);

namespace Drupal\ad_inventory\Plugin\AdProvider;

use Drupal\ad_inventory\Attribute\AdProvider;
use Drupal\ad_inventory\ProviderPolicy;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * A network whose code is pasted as given, per slot.
 *
 * Restricted: whoever edits it prints arbitrary code on public pages, so it
 * needs "administer pasted ad snippets". The unit markup is the slot's
 * "markup" field, verbatim; <script> elements in it run when the browser
 * fills the unit, after the grant.
 */
#[AdProvider(
  id: 'snippet',
  label: new TranslatableMarkup('Pasted snippet'),
  description: new TranslatableMarkup('Any network: its unit code pasted per slot, its loader, ads.txt lines and rules.'),
  restricted: TRUE,
)]
final class PastedSnippet extends AdProviderPluginBase {

  /**
   * {@inheritdoc}
   */
  public function defaultSettings(): array {
    return [
      'loader' => '',
      'kinds' => ['banner'],
      'house_behind' => FALSE,
      'max_units' => 0,
      'labels' => [],
      'ads_txt' => [],
      'consent' => 'Advertising consent.',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $settings): array {
    $settings += $this->defaultSettings();
    return [
      'loader' => [
        '#type' => 'url',
        '#title' => new TranslatableMarkup('Loader script'),
        '#description' => new TranslatableMarkup("Loaded once per page, after the grant, when the page carries one of this provider's units. Empty when the unit code loads its own."),
        '#default_value' => $settings['loader'],
      ],
      'kinds' => [
        '#type' => 'checkboxes',
        '#title' => new TranslatableMarkup('Formats it serves'),
        '#description' => new TranslatableMarkup('Pop-unders, push prompts, vignettes, interstitials, exit and slide-in widgets are refused beside a provider that forbids them (AdSense does).'),
        '#options' => array_combine(ProviderPolicy::KINDS, ProviderPolicy::KINDS),
        '#default_value' => (array) $settings['kinds'],
      ],
      'house_behind' => [
        '#type' => 'checkbox',
        '#title' => new TranslatableMarkup('A house creative may sit behind an unfilled unit'),
        '#description' => new TranslatableMarkup("Leave off when the network's terms forbid hiding or covering its unit."),
        '#default_value' => !empty($settings['house_behind']),
      ],
      'max_units' => [
        '#type' => 'number',
        '#title' => new TranslatableMarkup('Units per page at most'),
        '#description' => new TranslatableMarkup('0 for no limit.'),
        '#min' => 0,
        '#default_value' => (int) $settings['max_units'],
      ],
      'labels' => [
        '#type' => 'textarea',
        '#title' => new TranslatableMarkup('Labels it allows'),
        '#description' => new TranslatableMarkup('One per line; empty allows any.'),
        '#default_value' => implode("\n", (array) $settings['labels']),
        '#rows' => 3,
      ],
      'ads_txt' => [
        '#type' => 'textarea',
        '#title' => new TranslatableMarkup('ads.txt lines'),
        '#description' => new TranslatableMarkup('One per line, as the network issued them.'),
        '#default_value' => implode("\n", (array) $settings['ads_txt']),
        '#rows' => 3,
      ],
      'consent' => [
        '#type' => 'textfield',
        '#title' => new TranslatableMarkup('Consent it needs'),
        '#default_value' => (string) $settings['consent'],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function unitFields(): array {
    return ['markup' => (string) new TranslatableMarkup('Unit code, as the network issued it')];
  }

  /**
   * {@inheritdoc}
   */
  public function loaderUrl(): string {
    $loader = trim((string) $this->settings()['loader']);
    return preg_match('#^https://#', $loader) ? $loader : '';
  }

  /**
   * {@inheritdoc}
   */
  public function unitMarkup(array $unit): string {
    return trim((string) ($unit['markup'] ?? ''));
  }

  /**
   * {@inheritdoc}
   */
  public function adsTxtLines(): array {
    return array_values(array_filter(array_map('trim', (array) $this->settings()['ads_txt'])));
  }

  /**
   * {@inheritdoc}
   */
  public function consent(): string {
    return (string) $this->settings()['consent'];
  }

  /**
   * {@inheritdoc}
   */
  public function allowedLabels(): array {
    return array_values(array_filter(array_map('trim', (array) $this->settings()['labels'])));
  }

  /**
   * {@inheritdoc}
   */
  public function maxUnits(): int {
    return max(0, (int) $this->settings()['max_units']);
  }

  /**
   * {@inheritdoc}
   */
  public function houseBehind(): bool {
    return !empty($this->settings()['house_behind']);
  }

  /**
   * {@inheritdoc}
   */
  public function kinds(): array {
    return array_values(array_intersect(ProviderPolicy::KINDS, (array) $this->settings()['kinds']));
  }

}
