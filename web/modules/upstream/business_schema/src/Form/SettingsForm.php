<?php

declare(strict_types=1);

namespace Drupal\business_schema\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * The business's facts, in one form.
 *
 * Lists are edited one item per line, the parts of an item separated by
 * "|". A site normally keeps these values in its config/sync
 * (business_schema.settings.yml); the form is for reading and adjusting
 * them on a local copy before exporting.
 */
final class SettingsForm extends ConfigFormBase {

  /**
   * The schema.org day names, in week order.
   */
  private const DAYS = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

  /**
   * HH:MM, 24 hours.
   */
  private const TIME = '/^([01][0-9]|2[0-3]):[0-5][0-9]$/';

  /**
   * YYYY-MM-DD.
   */
  private const DATE = '/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/';

  /**
   * A schema.org type name.
   */
  private const TYPE = '/^[A-Z][A-Za-z]+$/';

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'business_schema_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['business_schema.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('business_schema.settings');

    $form['intro'] = [
      '#markup' => '<p>' . $this->t('What the site tells Google about the business: Organization and LocalBusiness on the front page, Product on product pages, and the "Business details" block for people. Only real facts: a field left empty is left out, never guessed.') . '</p>',
    ];

    $form['identity'] = [
      '#type' => 'details',
      '#title' => $this->t('Who the business is'),
      '#open' => TRUE,
    ];
    $form['identity']['types'] = [
      '#type' => 'textarea',
      '#title' => $this->t('schema.org types'),
      '#description' => $this->t('One per line, the most specific first: Bakery, PetStore, BeautySalon, GroceryStore… A business that is two things lists both (PetStore and VeterinaryCare).'),
      '#default_value' => $this->lines($config->get('types')),
      '#rows' => 3,
    ];
    $form['identity']['name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Name'),
      '#description' => $this->t('The real-world name, as on the sign and the Business Profile. Empty: the site name.'),
      '#default_value' => $config->get('name'),
    ];
    $form['identity']['alternate_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Alternate name'),
      '#default_value' => $config->get('alternate_name'),
    ];
    $form['identity']['legal_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Legal name'),
      '#default_value' => $config->get('legal_name'),
    ];
    $form['identity']['tax_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Tax ID (NIT)'),
      '#default_value' => $config->get('tax_id'),
    ];
    $form['identity']['description'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Description'),
      '#default_value' => $config->get('description'),
      '#rows' => 3,
    ];
    $form['identity']['url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('URL'),
      '#description' => $this->t('Empty: the front page.'),
      '#default_value' => $config->get('url'),
    ];
    $form['identity']['logo'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Logo'),
      '#description' => $this->t("A path relative to the Drupal root, a stream URI (public://…) or an absolute URL; at least 112×112 pixels and legible on white. Empty: the default theme's logo."),
      '#default_value' => $config->get('logo'),
    ];
    $form['identity']['images'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Photos of the business'),
      '#description' => $this->t('One per line, as the logo. Google asks for several, high resolution, in 16:9, 4:3 and 1:1.'),
      '#default_value' => $this->lines($config->get('images')),
      '#rows' => 3,
    ];
    $form['identity']['same_as'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Profiles elsewhere'),
      '#description' => $this->t('One URL per line: Instagram, Facebook, the Business Profile…'),
      '#default_value' => $this->lines($config->get('same_as')),
      '#rows' => 3,
    ];

    $form['contact'] = [
      '#type' => 'details',
      '#title' => $this->t('Contact and place'),
      '#open' => TRUE,
    ];
    $form['contact']['telephone'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Telephone'),
      '#description' => $this->t('With the country code: +57 301 000 0000.'),
      '#default_value' => $config->get('telephone'),
    ];
    $form['contact']['email'] = [
      '#type' => 'email',
      '#title' => $this->t('Public e-mail address'),
      '#default_value' => $config->get('email'),
    ];
    $form['contact']['contact_points'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Contact points'),
      '#description' => $this->t('One per line: <code>what it is for | telephone | e-mail</code>, e.g. <code>wholesale | +57 301 000 0000 |</code>.'),
      '#default_value' => $this->rows($config->get('contact_points'), ['contact_type', 'telephone', 'email']),
      '#rows' => 3,
    ];
    $form['contact']['place_note'] = [
      '#markup' => '<p>' . $this->t("With Commerce, the default store's address and its point (field_ubicacion) are used, and the values below only fill what the store lacks.") . '</p>',
    ];
    $labels = [
      'street_address' => $this->t('Street address'),
      'address_locality' => $this->t('Locality (city)'),
      'address_region' => $this->t('Region (department, state)'),
      'postal_code' => $this->t('Postal code'),
      'address_country' => $this->t('Country (two-letter code)'),
    ];
    foreach ($labels as $key => $label) {
      $form['contact']['address_' . $key] = [
        '#type' => 'textfield',
        '#title' => $label,
        '#default_value' => $config->get('address.' . $key),
        '#maxlength' => $key === 'address_country' ? 2 : 255,
      ];
    }
    $form['contact']['latitude'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Latitude'),
      '#description' => $this->t('WGS 84, at least five decimals.'),
      '#default_value' => $config->get('geo.latitude'),
    ];
    $form['contact']['longitude'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Longitude'),
      '#default_value' => $config->get('geo.longitude'),
    ];
    $form['contact']['area_served'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Areas served'),
      '#description' => $this->t('One place per line.'),
      '#default_value' => $this->lines($config->get('area_served')),
      '#rows' => 2,
    ];

    $form['hours'] = [
      '#type' => 'details',
      '#title' => $this->t('Opening hours'),
      '#open' => TRUE,
    ];
    $form['hours']['opening_hours'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Regular hours'),
      '#description' => $this->t('One line per group of days with the same hours: <code>Monday, Tuesday, Wednesday, Thursday, Friday | 09:00 | 18:00</code>. Days in English, as schema.org names them; times as HH:MM, 24 hours.'),
      '#default_value' => $this->hoursText($config->get('opening_hours') ?? []),
      '#rows' => 4,
    ];
    $form['hours']['special_hours'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Special hours'),
      '#description' => $this->t('Holidays and seasonal hours, one line each: <code>2026-12-25 | 2026-12-25 | closed</code> or <code>2026-12-24 | 2026-12-24 | 09:00 | 13:00</code>.'),
      '#default_value' => $this->specialHoursText($config->get('special_hours') ?? []),
      '#rows' => 4,
    ];

    $form['offer'] = [
      '#type' => 'details',
      '#title' => $this->t('What the business offers'),
    ];
    $form['offer']['price_range'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Price range'),
      '#description' => $this->t('Under 100 characters, e.g. $$.'),
      '#default_value' => $config->get('price_range'),
      '#maxlength' => 99,
    ];
    $form['offer']['serves_cuisine'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Cuisine'),
      '#description' => $this->t('Food establishments only. One per line.'),
      '#default_value' => $this->lines($config->get('serves_cuisine')),
      '#rows' => 2,
    ];
    $form['offer']['menu'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Menu'),
      '#description' => $this->t('Food establishments only: a path on this site (/tienda) or an absolute URL.'),
      '#default_value' => $config->get('menu'),
    ];
    $form['offer']['departments'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Departments'),
      '#description' => $this->t('One per line: <code>types | name | path or URL | telephone</code>, e.g. <code>VeterinaryCare | Example Veterinaria | /veterinaria |</code>. Name a department "{business} {department}".'),
      '#default_value' => $this->departmentsText($config->get('departments') ?? []),
      '#rows' => 3,
    ];

    $form['shop'] = [
      '#type' => 'details',
      '#title' => $this->t('Shop'),
    ];
    $form['shop']['return_category'] = [
      '#type' => 'select',
      '#title' => $this->t('Return policy'),
      '#options' => [
        '' => $this->t('- Not stated -'),
        'MerchantReturnFiniteReturnWindow' => $this->t('Returns within a number of days'),
        'MerchantReturnNotPermitted' => $this->t('No returns'),
        'MerchantReturnUnlimitedWindow' => $this->t('Returns at any time'),
      ],
      '#default_value' => $config->get('return_policy.category'),
    ];
    $form['shop']['return_days'] = [
      '#type' => 'number',
      '#title' => $this->t('Days to return'),
      '#min' => 0,
      '#default_value' => $config->get('return_policy.days'),
    ];
    $form['shop']['return_country'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Country the policy applies to (two-letter code)'),
      '#maxlength' => 2,
      '#default_value' => $config->get('return_policy.country'),
    ];
    $form['shop']['return_method'] = [
      '#type' => 'select',
      '#title' => $this->t('Return method'),
      '#options' => [
        '' => $this->t('- Not stated -'),
        'ReturnInStore' => $this->t('In the shop'),
        'ReturnByMail' => $this->t('By mail'),
        'ReturnAtKiosk' => $this->t('At a kiosk'),
      ],
      '#default_value' => $config->get('return_policy.method'),
    ];
    $form['shop']['return_fees'] = [
      '#type' => 'select',
      '#title' => $this->t('Return fees'),
      '#options' => [
        '' => $this->t('- Not stated -'),
        'FreeReturn' => $this->t('Free'),
        'ReturnFeesCustomerResponsibility' => $this->t('Paid by the customer'),
        'ReturnShippingFees' => $this->t('Shipping fees charged'),
      ],
      '#default_value' => $config->get('return_policy.fees'),
    ];
    $form['shop']['products_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Mark up product pages'),
      '#description' => $this->t('Off while the catalogue is placeholders: a placeholder price published to Google is a false one.'),
      '#default_value' => $config->get('products.enabled'),
    ];
    $form['shop']['item_condition'] = [
      '#type' => 'select',
      '#title' => $this->t('Condition of what the shop sells'),
      '#options' => [
        '' => $this->t('- Not stated -'),
        'NewCondition' => $this->t('New'),
        'UsedCondition' => $this->t('Used'),
        'RefurbishedCondition' => $this->t('Refurbished'),
        'DamagedCondition' => $this->t('Damaged'),
      ],
      '#default_value' => $config->get('products.item_condition'),
    ];
    $form['shop']['default_availability'] = [
      '#type' => 'select',
      '#title' => $this->t('Availability of an active variation'),
      '#description' => $this->t('When no availability field says otherwise.'),
      '#options' => [
        '' => $this->t('- Not stated -'),
        'InStock' => $this->t('In stock'),
        'MadeToOrder' => $this->t('Made to order'),
        'PreOrder' => $this->t('Pre-order'),
        'InStoreOnly' => $this->t('In the shop only'),
        'OnlineOnly' => $this->t('Online only'),
      ],
      '#default_value' => $config->get('products.default_availability'),
    ];
    $fields = [
      'availability_field' => $this->t('Variation field that says whether it is available'),
      'gtin_field' => $this->t('Variation field holding the GTIN (barcode)'),
      'brand_field' => $this->t('Product field holding the brand'),
      'category_field' => $this->t('Product field holding the category'),
    ];
    foreach ($fields as $key => $label) {
      $form['shop'][$key] = [
        '#type' => 'textfield',
        '#title' => $label,
        '#description' => $this->t('A machine name; a field the entity lacks is ignored.'),
        '#default_value' => $config->get('products.' . $key),
      ];
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);
    $parsed = [];
    foreach (['types', 'opening_hours', 'special_hours', 'departments', 'contact_points'] as $key) {
      [$parsed[$key], $errors] = match ($key) {
        'types' => $this->parseTypes((string) $form_state->getValue($key)),
        'opening_hours' => $this->parseHours((string) $form_state->getValue($key)),
        'special_hours' => $this->parseSpecialHours((string) $form_state->getValue($key)),
        'departments' => $this->parseDepartments((string) $form_state->getValue($key)),
        'contact_points' => $this->parseContactPoints((string) $form_state->getValue($key)),
      };
      foreach ($errors as $error) {
        $form_state->setErrorByName($key, $error);
      }
    }
    $form_state->set('business_schema_parsed', $parsed);

    foreach (['latitude' => 90, 'longitude' => 180] as $key => $limit) {
      $value = trim((string) $form_state->getValue($key));
      if ($value !== '' && (!is_numeric($value) || abs((float) $value) > $limit)) {
        $form_state->setErrorByName($key, $this->t('%value is not a coordinate.', ['%value' => $value]));
      }
    }
    foreach (['address_address_country', 'return_country'] as $key) {
      $value = trim((string) $form_state->getValue($key));
      if ($value !== '' && !preg_match('/^[A-Za-z]{2}$/', $value)) {
        $form_state->setErrorByName($key, $this->t('A country is its two-letter code, e.g. CO.'));
      }
    }
    if ($form_state->getValue('return_category') === 'MerchantReturnFiniteReturnWindow' && (int) $form_state->getValue('return_days') <= 0) {
      $form_state->setErrorByName('return_days', $this->t('A return window needs its number of days.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $parsed = $form_state->get('business_schema_parsed');
    $value = static fn (string $key): string => trim((string) $form_state->getValue($key));
    $list = fn (string $key): array => $this->parseLines((string) $form_state->getValue($key));

    $address = [];
    foreach (['street_address', 'address_locality', 'address_region', 'postal_code', 'address_country'] as $key) {
      $address[$key] = $value('address_' . $key);
    }
    $address['address_country'] = strtoupper($address['address_country']);

    $this->config('business_schema.settings')
      ->set('types', $parsed['types'])
      ->set('name', $value('name'))
      ->set('alternate_name', $value('alternate_name'))
      ->set('legal_name', $value('legal_name'))
      ->set('description', $value('description'))
      ->set('url', $value('url'))
      ->set('logo', $value('logo'))
      ->set('images', $list('images'))
      ->set('telephone', $value('telephone'))
      ->set('email', $value('email'))
      ->set('tax_id', $value('tax_id'))
      ->set('price_range', $value('price_range'))
      ->set('same_as', $list('same_as'))
      ->set('address', $address)
      ->set('geo', ['latitude' => $value('latitude'), 'longitude' => $value('longitude')])
      ->set('opening_hours', $parsed['opening_hours'])
      ->set('special_hours', $parsed['special_hours'])
      ->set('serves_cuisine', $list('serves_cuisine'))
      ->set('menu', $value('menu'))
      ->set('area_served', $list('area_served'))
      ->set('departments', $parsed['departments'])
      ->set('contact_points', $parsed['contact_points'])
      ->set('return_policy', [
        'category' => $value('return_category'),
        'days' => (int) $form_state->getValue('return_days'),
        'country' => strtoupper($value('return_country')),
        'method' => $value('return_method'),
        'fees' => $value('return_fees'),
      ])
      ->set('products', [
        'enabled' => (bool) $form_state->getValue('products_enabled'),
        'item_condition' => $value('item_condition'),
        'default_availability' => $value('default_availability'),
        'availability_field' => $value('availability_field'),
        'brand_field' => $value('brand_field'),
        'category_field' => $value('category_field'),
        'gtin_field' => $value('gtin_field'),
      ])
      ->save();
    parent::submitForm($form, $form_state);
  }

  /**
   * Non-empty, trimmed lines.
   *
   * @return string[]
   *   The lines.
   */
  private function parseLines(string $text): array {
    return array_values(array_filter(array_map('trim', preg_split('/\R/', $text) ?: [])));
  }

  /**
   * A line's parts, split on "|", trimmed.
   *
   * @return string[]
   *   The parts.
   */
  private function parts(string $line): array {
    return array_map('trim', explode('|', $line));
  }

  /**
   * Types, one per line.
   *
   * @return array{0: string[], 1: array}
   *   The types and the errors.
   */
  private function parseTypes(string $text): array {
    $types = [];
    $errors = [];
    foreach ($this->parseLines($text) as $line) {
      if (!preg_match(self::TYPE, $line)) {
        $errors[] = $this->t('%type is not a schema.org type name.', ['%type' => $line]);
        continue;
      }
      $types[] = $line;
    }
    return [array_values(array_unique($types)), $errors];
  }

  /**
   * Regular hours: "days | opens | closes" per line.
   *
   * @return array{0: array, 1: array}
   *   The hours and the errors.
   */
  private function parseHours(string $text): array {
    $hours = [];
    $errors = [];
    foreach ($this->parseLines($text) as $line) {
      $parts = $this->parts($line);
      $days = $this->parseDays($parts[0]);
      if (count($parts) !== 3 || $days === NULL || !preg_match(self::TIME, $parts[1]) || !preg_match(self::TIME, $parts[2])) {
        $errors[] = $this->t('%line: write the days, the opening time and the closing time, e.g. "Monday, Tuesday | 09:00 | 18:00".', ['%line' => $line]);
        continue;
      }
      $hours[] = ['days' => $days, 'opens' => $parts[1], 'closes' => $parts[2]];
    }
    return [$hours, $errors];
  }

  /**
   * Days from a comma-separated list, in week order, or NULL if one is wrong.
   *
   * @return string[]|null
   *   schema.org day names.
   */
  private function parseDays(string $text): ?array {
    $wanted = array_filter(array_map(static fn (string $day): string => ucfirst(strtolower(trim($day))), explode(',', $text)));
    if ($wanted === [] || array_diff($wanted, self::DAYS) !== []) {
      return NULL;
    }
    return array_values(array_intersect(self::DAYS, $wanted));
  }

  /**
   * Special hours, one range per line.
   *
   * Either "from | through | closed" or "from | through | opens | closes".
   *
   * @return array{0: array, 1: array}
   *   The special hours and the errors.
   */
  private function parseSpecialHours(string $text): array {
    $hours = [];
    $errors = [];
    foreach ($this->parseLines($text) as $line) {
      $parts = $this->parts($line);
      $dates = count($parts) >= 3 && preg_match(self::DATE, $parts[0]) && preg_match(self::DATE, $parts[1]) && $parts[0] <= $parts[1];
      $range = ['valid_from' => $parts[0], 'valid_through' => $parts[1] ?? ''];
      if ($dates && count($parts) === 3 && strtolower($parts[2]) === 'closed') {
        $hours[] = $range + ['closed' => TRUE, 'opens' => '', 'closes' => ''];
      }
      elseif ($dates && count($parts) === 4 && preg_match(self::TIME, $parts[2]) && preg_match(self::TIME, $parts[3])) {
        $hours[] = $range + ['closed' => FALSE, 'opens' => $parts[2], 'closes' => $parts[3]];
      }
      else {
        $errors[] = $this->t('%line: write "from | through | closed" or "from | through | opens | closes", dates as YYYY-MM-DD.', ['%line' => $line]);
      }
    }
    return [$hours, $errors];
  }

  /**
   * Departments: "types | name | path or URL | telephone" per line.
   *
   * @return array{0: array, 1: array}
   *   The departments and the errors.
   */
  private function parseDepartments(string $text): array {
    $departments = [];
    $errors = [];
    foreach ($this->parseLines($text) as $line) {
      $parts = $this->parts($line) + ['', '', '', ''];
      $types = array_values(array_filter(array_map('trim', explode(',', $parts[0]))));
      $bad = array_filter($types, static fn (string $type): bool => !preg_match(self::TYPE, $type));
      if ($types === [] || $bad !== [] || $parts[1] === '') {
        $errors[] = $this->t('%line: write the types and the name at least, e.g. "VeterinaryCare | Example Veterinaria | /veterinaria |".', ['%line' => $line]);
        continue;
      }
      $departments[] = ['types' => $types, 'name' => $parts[1], 'url' => $parts[2], 'telephone' => $parts[3]];
    }
    return [$departments, $errors];
  }

  /**
   * Contact points: "what it is for | telephone | e-mail" per line.
   *
   * @return array{0: array, 1: array}
   *   The contact points and the errors.
   */
  private function parseContactPoints(string $text): array {
    $points = [];
    $errors = [];
    foreach ($this->parseLines($text) as $line) {
      $parts = $this->parts($line) + ['', '', ''];
      $mailOk = $parts[2] === '' || filter_var($parts[2], FILTER_VALIDATE_EMAIL);
      if ($parts[0] === '' || ($parts[1] === '' && $parts[2] === '') || !$mailOk) {
        $errors[] = $this->t('%line: write what it is for and a telephone or an e-mail address.', ['%line' => $line]);
        continue;
      }
      $points[] = ['contact_type' => $parts[0], 'telephone' => $parts[1], 'email' => $parts[2]];
    }
    return [$points, $errors];
  }

  /**
   * A list as lines.
   */
  private function lines(?array $values): string {
    return implode("\n", $values ?? []);
  }

  /**
   * A list of mappings as "a | b | c" lines.
   */
  private function rows(?array $rows, array $keys): string {
    $lines = [];
    foreach ($rows ?? [] as $row) {
      $lines[] = implode(' | ', array_map(static fn (string $key): string => (string) ($row[$key] ?? ''), $keys));
    }
    return implode("\n", $lines);
  }

  /**
   * Regular hours as lines.
   */
  private function hoursText(array $hours): string {
    $lines = [];
    foreach ($hours as $entry) {
      $lines[] = implode(', ', $entry['days'] ?? []) . ' | ' . ($entry['opens'] ?? '') . ' | ' . ($entry['closes'] ?? '');
    }
    return implode("\n", $lines);
  }

  /**
   * Special hours as lines.
   */
  private function specialHoursText(array $hours): string {
    $lines = [];
    foreach ($hours as $entry) {
      $line = ($entry['valid_from'] ?? '') . ' | ' . ($entry['valid_through'] ?? '');
      $line .= !empty($entry['closed']) ? ' | closed' : ' | ' . ($entry['opens'] ?? '') . ' | ' . ($entry['closes'] ?? '');
      $lines[] = $line;
    }
    return implode("\n", $lines);
  }

  /**
   * Departments as lines.
   */
  private function departmentsText(array $departments): string {
    $lines = [];
    foreach ($departments as $department) {
      $lines[] = implode(', ', $department['types'] ?? []) . ' | ' . ($department['name'] ?? '') . ' | ' . ($department['url'] ?? '') . ' | ' . ($department['telephone'] ?? '');
    }
    return implode("\n", $lines);
  }

}
