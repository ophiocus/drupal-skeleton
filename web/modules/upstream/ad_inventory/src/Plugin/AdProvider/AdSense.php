<?php

declare(strict_types=1);

namespace Drupal\ad_inventory\Plugin\AdProvider;

use Drupal\ad_inventory\Attribute\AdProvider;
use Drupal\ad_inventory\ProviderPolicy;
use Drupal\Component\Utility\Html;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Google AdSense display units, sized per breakpoint by CSS.
 *
 * The publisher id comes from $settings['ad_inventory_accounts'][<provider
 * id>] or the environment variable ADSENSE_PUBLISHER_ID, never from config.
 * Units are Google's responsive-by-CSS code (`display:inline-block`, the size
 * set by the slot's media queries), never auto-format, never overlays or
 * sticky.
 *
 * Known risk: Google documents deferring adsbygoogle.js until the reader
 * has chosen, but not inserting units from site JavaScript with one push()
 * each. That part is proven only on a live, approved account.
 */
#[AdProvider(
  id: 'adsense',
  label: new TranslatableMarkup('Google AdSense'),
  description: new TranslatableMarkup('Display units by slot id; publisher id from the environment.'),
)]
final class AdSense extends AdProviderPluginBase {

  /**
   * The environment variable holding the publisher id.
   */
  public const ENV = 'ADSENSE_PUBLISHER_ID';

  /**
   * The labels Google allows above its units.
   */
  public const LABELS = ['Advertisements', 'Sponsored Links', 'Anuncios', 'Enlaces patrocinados'];

  /**
   * Google's certification authority id in ads.txt.
   */
  public const ADS_TXT_TAG = 'f08c47fec0942fa0';

  /**
   * The publisher id, "pub-<digits>", from any form a site may write.
   *
   * "pub-123", "ca-pub-123" and "accounts/pub-123" name one publisher.
   */
  public static function publisher(?string $value): ?string {
    if ($value !== NULL && preg_match('/(pub-\d{6,})/', $value, $m)) {
      return $m[1];
    }
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultSettings(): array {
    return ['verification' => TRUE];
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $settings): array {
    return [
      'verification' => [
        '#type' => 'checkbox',
        '#title' => new TranslatableMarkup('Print the site verification tag'),
        '#description' => new TranslatableMarkup('<code>&lt;meta name="google-adsense-account"&gt;</code> in every page head, the same for every reader. Google reads it while reviewing the site.'),
        '#default_value' => !empty($settings['verification']),
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function unitFields(): array {
    return [
      'slot' => (string) new TranslatableMarkup('Ad unit id (data-ad-slot)'),
      'channel' => (string) new TranslatableMarkup('Custom channel (data-ad-channel, optional)'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function requiresAccount(): bool {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function account(): ?string {
    return self::publisher($this->gate->account($this->providerId(), self::ENV));
  }

  /**
   * The account as ad code names it: "ca-pub-<digits>".
   */
  private function client(): ?string {
    $publisher = $this->account();
    return $publisher === NULL ? NULL : 'ca-' . $publisher;
  }

  /**
   * {@inheritdoc}
   */
  public function loaderUrl(): string {
    $client = $this->client();
    return $client === NULL ? '' : 'https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=' . $client;
  }

  /**
   * {@inheritdoc}
   */
  public function unitMarkup(array $unit): string {
    $client = $this->client();
    $slot = trim((string) ($unit['slot'] ?? ''));
    if ($client === NULL || !preg_match('/^\d+$/', $slot)) {
      return '';
    }
    $attributes = sprintf(' data-ad-client="%s" data-ad-slot="%s"', Html::escape($client), Html::escape($slot));
    $channel = $this->reportingChannel($unit);
    if ($channel !== '') {
      $attributes .= sprintf(' data-ad-channel="%s"', Html::escape($channel));
    }
    return '<ins class="adsbygoogle" style="display:inline-block"' . $attributes . '></ins>';
  }

  /**
   * {@inheritdoc}
   */
  public function adsTxtLines(): array {
    $publisher = $this->account();
    return $publisher === NULL ? [] : [sprintf('google.com, %s, DIRECT, %s', $publisher, self::ADS_TXT_TAG)];
  }

  /**
   * {@inheritdoc}
   *
   * AdSense rejects every request from a site whose ads.txt exists without
   * its line.
   */
  public function adsTxtLineRequired(): bool {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function consent(): string {
    return 'Advertising consent. Readers in the EEA, the UK and Switzerland need a Google-certified consent platform for personalised ads.';
  }

  /**
   * {@inheritdoc}
   */
  public function allowedLabels(): array {
    return self::LABELS;
  }

  /**
   * {@inheritdoc}
   */
  public function houseBehind(): bool {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function forbids(): array {
    return ProviderPolicy::EXCLUSIVE_KINDS;
  }

  /**
   * {@inheritdoc}
   */
  public function verificationTag(): ?array {
    $client = $this->client();
    if ($client === NULL || empty($this->settings()['verification'])) {
      return NULL;
    }
    return [
      '#tag' => 'meta',
      '#attributes' => [
        'name' => 'google-adsense-account',
        'content' => $client,
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function handler(): string {
    return 'adsbygoogle';
  }

  /**
   * {@inheritdoc}
   */
  public function reportingChannel(array $unit): string {
    $channel = trim((string) ($unit['channel'] ?? ''));
    return preg_match('/^[A-Za-z0-9_+-]+$/', $channel) ? $channel : '';
  }

}
