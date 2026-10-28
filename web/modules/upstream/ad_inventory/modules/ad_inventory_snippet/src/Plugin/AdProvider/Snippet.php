<?php

declare(strict_types=1);

namespace Drupal\ad_inventory_snippet\Plugin\AdProvider;

use Drupal\ad_inventory\Attribute\AdProvider;
use Drupal\ad_inventory\Entity\AdFormat;
use Drupal\ad_inventory\Entity\AdSlot;
use Drupal\ad_inventory\Plugin\AdProviderBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Markup pasted from a network: one unit per format, plus its loaders.
 *
 * For networks with no plugin of their own, and for internal tests. The
 * markup is inert until the browser fills a space after consent; a network
 * whose terms forbid covering or hiding its unit is configured with no house
 * banner behind it by leaving that space's house fill off.
 */
#[AdProvider(
  id: 'snippet',
  label: new TranslatableMarkup('Pasted snippet'),
  permission: 'use ad snippet provider',
)]
final class Snippet extends AdProviderBase implements ContainerFactoryPluginInterface {

  /**
   * The entity type manager, for the formats.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = new static($configuration, $plugin_id, $plugin_definition);
    $instance->entityTypeManager = $container->get('entity_type.manager');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return ['units' => [], 'loaders' => [], 'ads_txt' => [], 'verification' => ''];
  }

  /**
   * {@inheritdoc}
   */
  public function unit(AdSlot $slot, AdFormat $format): string {
    return trim((string) ($this->configuration['units'][$format->id()] ?? ''));
  }

  /**
   * {@inheritdoc}
   */
  public function loaders(): array {
    return array_values(array_filter((array) $this->configuration['loaders'], static fn ($u) => is_string($u) && str_starts_with($u, 'https://')));
  }

  /**
   * {@inheritdoc}
   */
  public function adsTxtLines(): array {
    return array_values(array_filter((array) $this->configuration['ads_txt'], 'is_string'));
  }

  /**
   * {@inheritdoc}
   */
  public function verificationTag(): string {
    return trim((string) $this->configuration['verification']);
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form['units'] = [
      '#type' => 'details',
      '#title' => $this->t('Unit markup per format'),
      '#open' => TRUE,
      '#tree' => TRUE,
    ];
    foreach ($this->entityTypeManager->getStorage('ad_format')->loadMultiple() as $id => $format) {
      /** @var \Drupal\ad_inventory\Entity\AdFormat $format */
      $form['units'][$id] = [
        '#type' => 'textarea',
        '#title' => $format->label() . ' ' . $format->size(),
        '#default_value' => $this->configuration['units'][$id] ?? '',
        '#rows' => 3,
      ];
    }
    $form['loaders'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Script URLs'),
      '#description' => $this->t("One https:// URL per line, loaded once per page after the reader's consent."),
      '#default_value' => implode("\n", (array) $this->configuration['loaders']),
      '#rows' => 2,
    ];
    $form['ads_txt'] = [
      '#type' => 'textarea',
      '#title' => $this->t('ads.txt lines'),
      '#description' => $this->t('As the network gives them, one per line. Shown in /ads.txt only while this provider is approved.'),
      '#default_value' => implode("\n", (array) $this->configuration['ads_txt']),
      '#rows' => 2,
    ];
    $form['verification'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Verification meta tag'),
      '#description' => $this->t('For example &lt;meta name="network-verification" content="abc123"&gt;.'),
      '#default_value' => $this->configuration['verification'],
      '#maxlength' => 255,
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsFromForm(array $values): array {
    $lines = static fn ($text) => array_values(array_filter(array_map('trim', preg_split('/\R/', (string) $text) ?: [])));
    return [
      'units' => array_filter(array_map('trim', (array) ($values['units'] ?? [])), 'strlen'),
      'loaders' => $lines($values['loaders'] ?? ''),
      'ads_txt' => $lines($values['ads_txt'] ?? ''),
      'verification' => trim((string) ($values['verification'] ?? '')),
    ];
  }

}
