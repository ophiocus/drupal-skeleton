<?php

declare(strict_types=1);

namespace Drupal\business_schema;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ThemeSettingsProvider;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Locale\CountryManagerInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManager;
use Drupal\Core\Theme\ThemeInitializationInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The business's facts, from configuration, the site and its store.
 *
 * One reader for the JSON-LD and for the "Business details" block, so what
 * people see and what Google reads can never disagree.
 *
 * - Name and URL: business_schema.settings, else system.site and the front
 *   page.
 * - Logo: the setting, else the default theme's logo.
 * - Address and point: the default Commerce store (its address field, and
 *   its field_ubicacion point when it has one), else the settings. Commerce
 *   is optional: it is reached through the entity API only, never through
 *   its classes, so the module runs on a site without it.
 * - Everything else: the settings.
 */
final class BusinessFacts {

  /**
   * The store field holding its point (a maper_point: lat, lon).
   */
  public const STORE_POINT_FIELD = 'field_ubicacion';

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityRepositoryInterface $entityRepository,
    private readonly ThemeSettingsProvider $themeSettings,
    private readonly ThemeInitializationInterface $themeInitialization,
    private readonly FileUrlGeneratorInterface $fileUrlGenerator,
    private readonly CountryManagerInterface $countryManager,
    #[Autowire(param: 'app.root')]
    private readonly string $root,
    private readonly ?object $subdivisions = NULL,
  ) {}

  /**
   * The facts, resolved: absolute URLs, the store's address, the logo.
   *
   * @param \Drupal\Core\Cache\CacheableMetadata $cacheability
   *   Collects what the facts depend on.
   *
   * @return array
   *   Keys as in business_schema.settings, plus country_name and
   *   region_name for display; geo is NULL or numeric latitude/longitude.
   */
  public function facts(CacheableMetadata $cacheability): array {
    $settings = $this->configFactory->get('business_schema.settings');
    $site = $this->configFactory->get('system.site');
    $cacheability->addCacheableDependency($settings)->addCacheableDependency($site);

    $url = $this->link((string) $settings->get('url'), $cacheability);
    if ($url === '') {
      $generated = Url::fromRoute('<front>', [], ['absolute' => TRUE])->toString(TRUE);
      $cacheability->addCacheableDependency($generated);
      $url = $generated->getGeneratedUrl();
    }
    $logo = $this->file((string) $settings->get('logo'));
    if ($logo === '' && (string) $settings->get('logo') === '') {
      $logo = $this->themeLogo($cacheability);
    }

    [$address, $geo] = $this->place($cacheability);
    $country = $address['address_country'];
    $countries = $this->countryManager->getList();

    $departments = [];
    foreach ($settings->get('departments') ?? [] as $department) {
      $department['url'] = $this->link((string) ($department['url'] ?? ''), $cacheability);
      $departments[] = $department;
    }

    $policy = $settings->get('return_policy') ?? [];
    return [
      'types' => $settings->get('types') ?? [],
      'name' => trim((string) $settings->get('name')) ?: trim((string) $site->get('name')),
      'alternate_name' => (string) $settings->get('alternate_name'),
      'legal_name' => (string) $settings->get('legal_name'),
      'description' => (string) $settings->get('description'),
      'url' => $url,
      'logo' => $logo,
      'images' => array_values(array_filter(array_map(fn ($image) => $this->file((string) $image), $settings->get('images') ?? []))),
      'telephone' => trim((string) $settings->get('telephone')),
      'email' => trim((string) $settings->get('email')),
      'tax_id' => trim((string) $settings->get('tax_id')),
      'price_range' => trim((string) $settings->get('price_range')),
      'same_as' => array_values(array_filter($settings->get('same_as') ?? [])),
      'address' => $address,
      'country_name' => $country !== '' && isset($countries[$country]) ? (string) $countries[$country] : $country,
      'region_name' => $this->regionName($country, $address['address_region']),
      'geo' => $geo,
      'opening_hours' => $settings->get('opening_hours') ?? [],
      'special_hours' => $settings->get('special_hours') ?? [],
      'serves_cuisine' => array_values(array_filter($settings->get('serves_cuisine') ?? [])),
      'menu' => $this->link((string) $settings->get('menu'), $cacheability),
      'area_served' => array_values(array_filter($settings->get('area_served') ?? [])),
      'departments' => $departments,
      'contact_points' => $settings->get('contact_points') ?? [],
      'return_policy' => $policy + ['country' => ''],
      'item_condition' => (string) $settings->get('products.item_condition'),
    ];
  }

  /**
   * The address and point: the default store's, else the settings'.
   *
   * Each comes from the store when the store has one, so a site whose store
   * holds its address keeps one copy of it.
   *
   * @return array{0: array<string, string>, 1: array{latitude: float, longitude: float}|null}
   *   The address (street_address, address_locality, address_region,
   *   postal_code, address_country) and the point.
   */
  private function place(CacheableMetadata $cacheability): array {
    $settings = $this->configFactory->get('business_schema.settings');
    $address = array_map('strval', ($settings->get('address') ?? []) + [
      'street_address' => '',
      'address_locality' => '',
      'address_region' => '',
      'postal_code' => '',
      'address_country' => '',
    ]);
    $geo = $this->point($settings->get('geo.latitude'), $settings->get('geo.longitude'));

    $store = $this->store($cacheability);
    if ($store !== NULL) {
      if ($store->hasField('address') && !$store->get('address')->isEmpty()) {
        $item = $store->get('address')->first()->getValue();
        $street = array_filter(array_map('trim', [
          (string) ($item['address_line1'] ?? ''),
          (string) ($item['address_line2'] ?? ''),
          (string) ($item['address_line3'] ?? ''),
        ]));
        $address = [
          'street_address' => implode(', ', $street),
          'address_locality' => trim((string) ($item['locality'] ?? '')),
          'address_region' => trim((string) ($item['administrative_area'] ?? '')),
          'postal_code' => trim((string) ($item['postal_code'] ?? '')),
          'address_country' => trim((string) ($item['country_code'] ?? '')),
        ];
      }
      if ($store->hasField(self::STORE_POINT_FIELD) && !$store->get(self::STORE_POINT_FIELD)->isEmpty()) {
        $item = $store->get(self::STORE_POINT_FIELD)->first()->getValue();
        $geo = $this->point($item['lat'] ?? NULL, $item['lon'] ?? NULL) ?? $geo;
      }
    }
    return [$address, $geo];
  }

  /**
   * The default Commerce store, when Commerce is installed and has one.
   *
   * Commerce names it in commerce_store.settings (the store storage's own
   * loadDefault() reads it); a site with exactly one store has that one.
   */
  private function store(CacheableMetadata $cacheability): ?ContentEntityInterface {
    if (!$this->entityTypeManager->hasDefinition('commerce_store')) {
      return NULL;
    }
    $cacheability->addCacheTags(['config:commerce_store.settings', 'commerce_store_list']);
    $storage = $this->entityTypeManager->getStorage('commerce_store');
    $store = method_exists($storage, 'loadDefault') ? $storage->loadDefault() : NULL;
    if (!$store) {
      $ids = $storage->getQuery()->accessCheck(FALSE)->range(0, 2)->execute();
      $store = count($ids) === 1 ? $storage->load(reset($ids)) : NULL;
    }
    if (!$store instanceof ContentEntityInterface) {
      return NULL;
    }
    $store = $this->entityRepository->getTranslationFromContext($store);
    $cacheability->addCacheableDependency($store);
    return $store instanceof ContentEntityInterface ? $store : NULL;
  }

  /**
   * A point from two numbers, or NULL when either is missing or not one.
   *
   * @return array{latitude: float, longitude: float}|null
   *   The point.
   */
  private function point(mixed $latitude, mixed $longitude): ?array {
    if (!is_numeric($latitude) || !is_numeric($longitude)) {
      return NULL;
    }
    $latitude = (float) $latitude;
    $longitude = (float) $longitude;
    if (abs($latitude) > 90 || abs($longitude) > 180) {
      return NULL;
    }
    return ['latitude' => $latitude, 'longitude' => $longitude];
  }

  /**
   * The region's name, for people: the address module knows it by code.
   */
  private function regionName(string $country, string $region): string {
    if ($country === '' || $region === '' || $this->subdivisions === NULL || !method_exists($this->subdivisions, 'getList')) {
      return $region;
    }
    $list = $this->subdivisions->getList([$country]);
    return is_array($list) && isset($list[$region]) ? (string) $list[$region] : $region;
  }

  /**
   * The default theme's logo as an absolute URL, or '' when it shows none.
   */
  private function themeLogo(CacheableMetadata $cacheability): string {
    $theme = (string) $this->configFactory->get('system.theme')->get('default');
    $cacheability->addCacheTags(['config:system.theme']);
    if ($theme === '') {
      return '';
    }
    $cacheability->addCacheTags(['config:system.theme.global', 'config:' . $theme . '.settings']);
    $path = $this->themeSettings->getSetting('logo.use_default', $theme)
      ? $this->themeInitialization->getActiveThemeByName($theme)->getLogo()
      : (string) $this->themeSettings->getSetting('logo.path', $theme);
    return $this->file((string) $path);
  }

  /**
   * A file's absolute URL: an absolute URL as is, a stream URI or a path.
   *
   * A path is relative to the Drupal root. A file that does not exist
   * gives '', so no URL to nothing is published.
   */
  public function file(string $value): string {
    $value = trim($value);
    if ($value === '') {
      return '';
    }
    if (preg_match('#^https?://#i', $value)) {
      return $value;
    }
    $stream = (bool) StreamWrapperManager::getScheme($value);
    $path = $stream ? $value : ltrim($value, '/');
    if ($path === '' || !is_file($stream ? $path : $this->root . '/' . $path)) {
      return '';
    }
    return $this->fileUrlGenerator->generateAbsoluteString($path);
  }

  /**
   * A link's absolute URL: an absolute URL as is, else a path on this site.
   */
  public function link(string $value, CacheableMetadata $cacheability): string {
    $value = trim($value);
    if ($value === '') {
      return '';
    }
    if (preg_match('#^https?://#i', $value)) {
      return $value;
    }
    try {
      $generated = Url::fromUserInput('/' . ltrim($value, '/'), ['absolute' => TRUE])->toString(TRUE);
    }
    catch (\InvalidArgumentException) {
      return '';
    }
    $cacheability->addCacheableDependency($generated);
    return $generated->getGeneratedUrl();
  }

}
