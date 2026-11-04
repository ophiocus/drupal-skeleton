<?php

declare(strict_types=1);

namespace Drupal\business_schema\Plugin\Block;

use Drupal\business_schema\BusinessFacts;
use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The business's name, address, phone and hours, as the JSON-LD has them.
 *
 * Google wants the facts on the page to match the structured data. Both are
 * built from BusinessFacts, so they cannot drift apart.
 */
#[Block(
  id: 'business_schema_details',
  admin_label: new TranslatableMarkup('Business details'),
  category: new TranslatableMarkup('Business'),
)]
final class BusinessDetailsBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * The schema.org day names, in week order.
   */
  private const DAYS = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    // Not private, not readonly: DependencySerializationTrait restores it on
    // unserialization (a block is serialized with a form's state).
    protected BusinessFacts $businessFacts,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get(BusinessFacts::class),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'show_hours' => TRUE,
      'show_email' => TRUE,
      'map_link' => TRUE,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state): array {
    $form['show_hours'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show the opening hours'),
      '#default_value' => $this->configuration['show_hours'],
    ];
    $form['show_email'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show the e-mail address'),
      '#default_value' => $this->configuration['show_email'],
    ];
    $form['map_link'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Link to a map of the location'),
      '#description' => $this->t('Shown when the business has coordinates.'),
      '#default_value' => $this->configuration['map_link'],
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state): void {
    foreach (['show_hours', 'show_email', 'map_link'] as $key) {
      $this->configuration[$key] = (bool) $form_state->getValue($key);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $cacheability = new CacheableMetadata();
    $facts = $this->businessFacts->facts($cacheability);
    $address = $facts['address'];

    $locality = implode(', ', array_filter([
      trim($address['postal_code'] . ' ' . $address['address_locality']),
      $facts['region_name'],
    ]));
    $lines = array_values(array_filter([
      $address['street_address'],
      $locality,
      $facts['country_name'],
    ]));

    $telephone = NULL;
    if ($facts['telephone'] !== '') {
      $telephone = [
        'display' => $facts['telephone'],
        'href' => 'tel:' . preg_replace('/(?!^\+)[^0-9]/', '', $facts['telephone']),
      ];
    }

    $map = '';
    if ($this->configuration['map_link'] && $facts['geo'] !== NULL) {
      $map = 'https://www.google.com/maps/search/?' . UrlHelper::buildQuery([
        'api' => 1,
        'query' => $facts['geo']['latitude'] . ',' . $facts['geo']['longitude'],
      ]);
    }

    $build = [
      '#theme' => 'business_details',
      '#name' => $facts['name'],
      '#address' => $lines,
      '#telephone' => $telephone,
      '#email' => $this->configuration['show_email'] ? $facts['email'] : '',
      '#hours' => $this->configuration['show_hours'] ? $this->hours($facts['opening_hours']) : [],
      '#special_hours' => $this->configuration['show_hours'] ? $this->specialHours($facts['special_hours']) : [],
      '#map_url' => $map,
    ];
    $cacheability->applyTo($build);
    return $build;
  }

  /**
   * Regular hours as lines: the days, and the time.
   *
   * @return array<int, array{days: \Drupal\Core\StringTranslation\TranslatableMarkup|string, time: string}>
   *   The lines.
   */
  private function hours(array $hours): array {
    $lines = [];
    foreach ($hours as $entry) {
      $days = array_values(array_intersect(self::DAYS, $entry['days'] ?? []));
      if ($days === [] || empty($entry['opens']) || empty($entry['closes'])) {
        continue;
      }
      $lines[] = [
        'days' => $this->days($days),
        'time' => $entry['opens'] . '–' . $entry['closes'],
      ];
    }
    return $lines;
  }

  /**
   * Special hours as lines: the dates, and the time or "Closed".
   *
   * @return array<int, array{dates: string, time: \Drupal\Core\StringTranslation\TranslatableMarkup|string}>
   *   The lines.
   */
  private function specialHours(array $hours): array {
    $lines = [];
    foreach ($hours as $entry) {
      $from = (string) ($entry['valid_from'] ?? '');
      if ($from === '') {
        continue;
      }
      $through = (string) ($entry['valid_through'] ?? '') ?: $from;
      if (!empty($entry['closed'])) {
        $time = $this->t('Closed');
      }
      elseif (!empty($entry['opens']) && !empty($entry['closes'])) {
        $time = $entry['opens'] . '–' . $entry['closes'];
      }
      else {
        continue;
      }
      $lines[] = [
        'dates' => $through === $from ? $from : $from . ' – ' . $through,
        'time' => $time,
      ];
    }
    return $lines;
  }

  /**
   * A list of days for people: a run of three or more as "first to last".
   *
   * @param string[] $days
   *   The schema.org day names, in week order.
   */
  private function days(array $days): TranslatableMarkup|string {
    $names = [
      'Monday' => $this->t('Monday'),
      'Tuesday' => $this->t('Tuesday'),
      'Wednesday' => $this->t('Wednesday'),
      'Thursday' => $this->t('Thursday'),
      'Friday' => $this->t('Friday'),
      'Saturday' => $this->t('Saturday'),
      'Sunday' => $this->t('Sunday'),
    ];
    $positions = array_map(static fn (string $day): int => (int) array_search($day, self::DAYS, TRUE), $days);
    $consecutive = $positions === range($positions[0], $positions[0] + count($positions) - 1);
    if (count($days) >= 3 && $consecutive) {
      return $this->t('@first to @last', [
        '@first' => $names[$days[0]],
        '@last' => $names[end($days)],
      ]);
    }
    return implode(', ', array_map(static fn (string $day): string => (string) $names[$day], $days));
  }

}
