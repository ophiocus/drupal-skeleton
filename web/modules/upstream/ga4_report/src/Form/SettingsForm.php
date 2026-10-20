<?php

declare(strict_types=1);

namespace Drupal\ga4_report\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\Url;
use Drupal\ga4_report\Report\PageKinds;

/**
 * The property, the dates and the site's own rules for the report.
 *
 * Lists are edited as lines of text, one entry per line, so a site's whole
 * setup reads at a glance and pastes between environments.
 */
final class SettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'ga4_report_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['ga4_report.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('ga4_report.settings');

    $form['sources'] = [
      '#type' => 'details',
      '#title' => $this->t('Sources'),
      '#open' => TRUE,
      '#description' => $this->t('Each source refreshes when its ID is set and settings.php names its credentials file by path. Credentials are never stored here.'),
    ];
    $form['sources']['property_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('GA4 property ID'),
      '#default_value' => $config->get('property_id'),
      '#description' => $this->t('The number in Admin → Property details, not the "G-…" measurement ID.') . ' ' . $this->status('ga4_report_credentials', 'GOOGLE_APPLICATION_CREDENTIALS'),
      '#size' => 16,
    ];
    $form['sources']['cloudflare_zone'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Cloudflare zone ID'),
      '#default_value' => $config->get('cloudflare_zone'),
      '#description' => $this->t("The 32-character ID on the zone's Overview page.") . ' ' . $this->status('ga4_report_cloudflare_token'),
      '#size' => 36,
    ];
    $form['sources']['adsense_account'] = [
      '#type' => 'textfield',
      '#title' => $this->t('AdSense publisher ID'),
      '#default_value' => $config->get('adsense_account'),
      '#description' => $this->t('"pub-" and its digits.') . ' ' . $this->status('ga4_report_adsense_credentials'),
      '#size' => 24,
    ];
    $form['dates'] = [
      '#type' => 'details',
      '#title' => $this->t('Dates and refreshing'),
      '#open' => TRUE,
    ];
    $form['dates']['start_date'] = [
      '#type' => 'textfield',
      '#title' => $this->t('First day'),
      '#default_value' => $config->get('start_date'),
      '#description' => $this->t('A date (2026-01-31) or a number of days back (90daysAgo).'),
      '#size' => 16,
    ];
    $form['dates']['end_date'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Last day'),
      '#default_value' => $config->get('end_date'),
      '#description' => $this->t('A date, today or yesterday.'),
      '#size' => 16,
    ];
    $form['dates']['refresh_interval'] = [
      '#type' => 'number',
      '#title' => $this->t('Hours between cron refreshes'),
      '#default_value' => $config->get('refresh_interval'),
      '#min' => 0,
      '#description' => $this->t('0: cron never refreshes; run <code>drush ga4-report:refresh</code> from system cron, or use the button.'),
    ];
    $form['rules'] = [
      '#type' => 'details',
      '#title' => $this->t("The site's own rules"),
      '#open' => TRUE,
    ];
    $form['rules']['internal_traffic'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Internal traffic'),
      '#default_value' => self::formatConditions($config->get('internal_traffic') ?? []),
      '#description' => $this->t('One condition per line, <code>dimension: value, value</code>. A visit is internal when it matches every line, e.g. <code>city: Springfield</code> and <code>deviceCategory: desktop</code>. Internal visits are drawn apart on the daily chart and left out of the weekday chart. Empty: no visit is internal.'),
      '#rows' => 3,
    ];
    $form['rules']['page_kinds'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Kinds of page'),
      '#default_value' => self::formatKinds($config->get('page_kinds') ?? []),
      '#description' => $this->t('One per line, <code>id | Label | pattern</code>. The pattern is a regular expression the whole page path must match (Google reads it as RE2), e.g. <code>article | Articles | /(en/)?articles/.+</code>. A page takes the first kind that matches it.'),
      '#rows' => 6,
    ];
    $form['rules']['markers'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Marked dates'),
      '#default_value' => self::formatMarkers($config->get('markers') ?? []),
      '#description' => $this->t('One per line, <code>YYYY-MM-DD | Label</code>: a launch, a campaign, a redesign.'),
      '#rows' => 3,
    ];
    $form['limits'] = [
      '#type' => 'details',
      '#title' => $this->t('Row limits'),
    ];
    $form['limits']['pages'] = [
      '#type' => 'number',
      '#title' => $this->t('Most viewed pages to keep'),
      '#default_value' => $config->get('limits.pages'),
      '#min' => 1,
      '#max' => 1000,
    ];
    $form['limits']['landing_pages'] = [
      '#type' => 'number',
      '#title' => $this->t('First-page and channel rows to keep'),
      '#default_value' => $config->get('limits.landing_pages'),
      '#min' => 1,
      '#max' => 1000,
    ];
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $property = trim((string) $form_state->getValue('property_id'));
    if ($property !== '' && !preg_match('/^\d+$/', $property)) {
      $form_state->setErrorByName('property_id', $this->t('The property ID is a number.'));
    }
    $zone = strtolower(trim((string) $form_state->getValue('cloudflare_zone')));
    if ($zone !== '' && !preg_match('/^[0-9a-f]{32}$/', $zone)) {
      $form_state->setErrorByName('cloudflare_zone', $this->t('The zone ID is 32 hexadecimal characters, not the domain name.'));
    }
    $account = trim((string) $form_state->getValue('adsense_account'));
    if ($account !== '' && !preg_match('/pub-\d{6,}/', $account)) {
      $form_state->setErrorByName('adsense_account', $this->t('The publisher ID is "pub-" and its digits.'));
    }
    foreach (['start_date', 'end_date'] as $key) {
      if (!self::isGaDate(trim((string) $form_state->getValue($key)))) {
        $form_state->setErrorByName($key, $this->t('Use a date (YYYY-MM-DD), NdaysAgo, today or yesterday.'));
      }
    }
    try {
      self::parseConditions((string) $form_state->getValue('internal_traffic'));
    }
    catch (\InvalidArgumentException $e) {
      $form_state->setErrorByName('internal_traffic', $e->getMessage());
    }
    try {
      self::parseKinds((string) $form_state->getValue('page_kinds'));
    }
    catch (\InvalidArgumentException $e) {
      $form_state->setErrorByName('page_kinds', $e->getMessage());
    }
    try {
      self::parseMarkers((string) $form_state->getValue('markers'));
    }
    catch (\InvalidArgumentException $e) {
      $form_state->setErrorByName('markers', $e->getMessage());
    }
    parent::validateForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('ga4_report.settings')
      ->set('property_id', trim((string) $form_state->getValue('property_id')))
      ->set('cloudflare_zone', strtolower(trim((string) $form_state->getValue('cloudflare_zone'))))
      ->set('adsense_account', trim((string) $form_state->getValue('adsense_account')))
      ->set('start_date', trim((string) $form_state->getValue('start_date')))
      ->set('end_date', trim((string) $form_state->getValue('end_date')))
      ->set('refresh_interval', (int) $form_state->getValue('refresh_interval'))
      ->set('internal_traffic', self::parseConditions((string) $form_state->getValue('internal_traffic')))
      ->set('page_kinds', self::parseKinds((string) $form_state->getValue('page_kinds')))
      ->set('markers', self::parseMarkers((string) $form_state->getValue('markers')))
      ->set('limits.pages', (int) $form_state->getValue('pages'))
      ->set('limits.landing_pages', (int) $form_state->getValue('landing_pages'))
      ->save();
    $this->messenger()->addStatus($this->t('Saved. The report changes at the next <a href=":url">refresh</a>.', [
      ':url' => Url::fromRoute('ga4_report.report')->toString(),
    ]));
  }

  /**
   * Whether a value is a date the Data API takes.
   */
  public static function isGaDate(string $value): bool {
    return (bool) preg_match('/^(\d{4}-\d{2}-\d{2}|\d+daysAgo|today|yesterday)$/', $value);
  }

  /**
   * Parses "dimension: value, value" lines.
   *
   * @throws \InvalidArgumentException
   */
  public static function parseConditions(string $text): array {
    $conditions = [];
    foreach (self::lines($text) as $n => $line) {
      if (!preg_match('/^([A-Za-z][A-Za-z0-9_:]*)\s*:\s*(.+)$/', $line, $m)) {
        throw new \InvalidArgumentException(sprintf('Line %d: write "dimension: value, value".', $n));
      }
      $values = array_values(array_filter(array_map('trim', explode(',', $m[2])), 'strlen'));
      $conditions[] = ['dimension' => $m[1], 'values' => $values];
    }
    return $conditions;
  }

  /**
   * Parses "id | Label | pattern" lines.
   *
   * @throws \InvalidArgumentException
   */
  public static function parseKinds(string $text): array {
    $kinds = [];
    foreach (self::lines($text) as $n => $line) {
      $parts = array_map('trim', explode('|', $line, 3));
      if (count($parts) !== 3 || !preg_match('/^[a-z][a-z0-9_]*$/', $parts[0]) || $parts[2] === '') {
        throw new \InvalidArgumentException(sprintf('Line %d: write "id | Label | pattern", the id in lower case.', $n));
      }
      if ($parts[0] === PageKinds::OTHER || isset($kinds[$parts[0]])) {
        throw new \InvalidArgumentException(sprintf('Line %d: the id "%s" is taken.', $n, $parts[0]));
      }
      if (PageKinds::compile($parts[2]) === NULL) {
        throw new \InvalidArgumentException(sprintf('Line %d: the pattern is not a valid regular expression.', $n));
      }
      $kinds[$parts[0]] = ['id' => $parts[0], 'label' => $parts[1], 'pattern' => $parts[2]];
    }
    return array_values($kinds);
  }

  /**
   * Parses "YYYY-MM-DD | Label" lines.
   *
   * @throws \InvalidArgumentException
   */
  public static function parseMarkers(string $text): array {
    $markers = [];
    foreach (self::lines($text) as $n => $line) {
      $parts = array_map('trim', explode('|', $line, 2));
      if (count($parts) !== 2 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $parts[0])) {
        throw new \InvalidArgumentException(sprintf('Line %d: write "YYYY-MM-DD | Label".', $n));
      }
      $markers[] = ['date' => $parts[0], 'label' => $parts[1]];
    }
    return $markers;
  }

  /**
   * Conditions as the lines parseConditions() reads.
   */
  public static function formatConditions(array $conditions): string {
    return implode("\n", array_map(static fn(array $c): string => $c['dimension'] . ': ' . implode(', ', $c['values']), $conditions));
  }

  /**
   * Kinds as the lines parseKinds() reads.
   */
  public static function formatKinds(array $kinds): string {
    return implode("\n", array_map(static fn(array $k): string => "{$k['id']} | {$k['label']} | {$k['pattern']}", $kinds));
  }

  /**
   * Markers as the lines parseMarkers() reads.
   */
  public static function formatMarkers(array $markers): string {
    return implode("\n", array_map(static fn(array $m): string => "{$m['date']} | {$m['label']}", $markers));
  }

  /**
   * Non-empty lines keyed by their 1-based line number.
   */
  private static function lines(string $text): array {
    $lines = [];
    foreach (preg_split('/\R/', $text) as $i => $line) {
      if (trim($line) !== '') {
        $lines[$i + 1] = trim($line);
      }
    }
    return $lines;
  }

  /**
   * Whether a credentials file is named and readable, without reading it.
   *
   * @param string $key
   *   The $settings key that names the file.
   * @param string $env
   *   An environment variable read when the setting is empty, or ''.
   */
  private function status(string $key, string $env = ''): string {
    $path = (string) (Settings::get($key) ?: ($env !== '' ? getenv($env) : '') ?: '');
    if ($path === '') {
      return (string) $this->t('Credentials: not named (<code>$settings["@key"]</code>).', ['@key' => $key]);
    }
    return is_readable($path)
      ? (string) $this->t('Credentials: named, and the file is readable.')
      : (string) $this->t('Credentials: named, but the file cannot be read by the web server.');
  }

}
