<?php

declare(strict_types=1);

namespace Drupal\business_schema;

/**
 * Builds schema.org JSON-LD from facts already gathered and resolved.
 *
 * Pure functions over arrays: no service, no entity, no request. Whatever
 * the facts leave empty is left out of the markup, never filled with a guess
 * (Google's guidelines, and the fleet's rule). No review or aggregateRating
 * is ever built: Google allows them only on sites that review other
 * businesses, and a business's own stars live on its Business Profile.
 */
final class JsonLd {

  /**
   * The schema.org vocabulary.
   */
  public const CONTEXT = 'https://schema.org';

  /**
   * The front page's graph: Organization and, with an address, the business.
   *
   * @param array $facts
   *   Business facts, as BusinessFacts::facts() returns them.
   *
   * @return array
   *   The JSON-LD document.
   */
  public static function business(array $facts): array {
    $url = (string) ($facts['url'] ?? '');
    $organizationId = $url !== '' ? $url . '#organization' : '';
    $address = self::postalAddress($facts['address'] ?? []);
    $policy = self::returnPolicy($facts['return_policy'] ?? []);

    $graph = [];
    $graph[] = self::clean([
      '@type' => 'Organization',
      '@id' => $organizationId,
      'name' => $facts['name'] ?? '',
      'alternateName' => $facts['alternate_name'] ?? '',
      'legalName' => $facts['legal_name'] ?? '',
      'description' => $facts['description'] ?? '',
      'url' => $url,
      'logo' => $facts['logo'] ?? '',
      'email' => $facts['email'] ?? '',
      'telephone' => $facts['telephone'] ?? '',
      'taxID' => $facts['tax_id'] ?? '',
      'address' => $address,
      'sameAs' => array_values($facts['same_as'] ?? []),
      'contactPoint' => self::contactPoints($facts['contact_points'] ?? []),
      'hasMerchantReturnPolicy' => $policy,
    ]);

    // Google requires a LocalBusiness to have a name and an address. Without
    // an address the business is described as an Organization alone.
    if ($address !== [] && ($facts['name'] ?? '') !== '') {
      $graph[] = self::clean([
        '@type' => self::type($facts['types'] ?? []),
        '@id' => $url !== '' ? $url . '#business' : '',
        'name' => $facts['name'],
        'url' => $url,
        'image' => array_values($facts['images'] ?? []),
        'logo' => $facts['logo'] ?? '',
        'telephone' => $facts['telephone'] ?? '',
        'email' => $facts['email'] ?? '',
        'priceRange' => $facts['price_range'] ?? '',
        'address' => $address,
        'geo' => self::geo($facts['geo'] ?? NULL),
        'openingHoursSpecification' => array_merge(
          self::openingHours($facts['opening_hours'] ?? []),
          self::specialHours($facts['special_hours'] ?? []),
        ),
        'servesCuisine' => array_values($facts['serves_cuisine'] ?? []),
        'menu' => $facts['menu'] ?? '',
        'areaServed' => array_values($facts['area_served'] ?? []),
        'department' => self::departments($facts['departments'] ?? [], $address),
        'parentOrganization' => $organizationId !== '' ? ['@id' => $organizationId] : [],
      ]);
    }

    return [
      '@context' => self::CONTEXT,
      '@graph' => $graph,
    ];
  }

  /**
   * A product page's Product, or NULL when Google's required fields are short.
   *
   * Google requires a name, an image and an offer with a price above zero
   * and a currency. A product without them is not marked up at all: a
   * merchant listing with an error in it helps nobody, and a placeholder
   * value would be a guess.
   *
   * @param array $product
   *   Product facts, as ProductFacts::facts() returns them.
   * @param array $business
   *   Business facts (the seller, the return policy, the item condition).
   *
   * @return array|null
   *   The JSON-LD document, or NULL.
   */
  public static function product(array $product, array $business): ?array {
    $images = array_values($product['images'] ?? []);
    if (($product['name'] ?? '') === '' || $images === []) {
      return NULL;
    }
    $seller = self::clean([
      '@type' => 'Organization',
      'name' => $business['name'] ?? '',
      'url' => $business['url'] ?? '',
    ]);
    if (!isset($seller['name'])) {
      $seller = [];
    }
    $policy = self::returnPolicy($business['return_policy'] ?? []);
    $condition = (string) ($business['item_condition'] ?? '');

    $offers = [];
    foreach ($product['offers'] ?? [] as $offer) {
      $price = self::price((string) ($offer['price'] ?? ''));
      $currency = (string) ($offer['currency'] ?? '');
      if ($price === NULL || $currency === '') {
        continue;
      }
      $offers[] = self::clean([
        '@type' => 'Offer',
        'sku' => $offer['sku'] ?? '',
        'gtin' => $offer['gtin'] ?? '',
        'price' => $price,
        'priceCurrency' => $currency,
        'availability' => self::enum((string) ($offer['availability'] ?? '')),
        'itemCondition' => self::enum($condition),
        'url' => $offer['url'] ?? '',
        'seller' => $seller,
        'hasMerchantReturnPolicy' => $policy,
      ]);
    }
    if ($offers === []) {
      return NULL;
    }

    $document = [
      '@context' => self::CONTEXT,
      '@type' => 'Product',
      'name' => $product['name'],
      'description' => $product['description'] ?? '',
      'image' => $images,
      'brand' => ($product['brand'] ?? '') !== '' ? ['@type' => 'Brand', 'name' => $product['brand']] : [],
      'category' => $product['category'] ?? '',
    ];
    // One offer: the product is the variation, and its SKU and GTIN describe
    // the product. Several: each offer carries its own.
    if (count($offers) === 1) {
      $offer = $offers[0];
      $document['sku'] = $offer['sku'] ?? '';
      $document['gtin'] = $offer['gtin'] ?? '';
      unset($offer['sku'], $offer['gtin']);
      $document['offers'] = $offer;
    }
    else {
      $document['offers'] = $offers;
    }
    return self::clean($document);
  }

  /**
   * Encodes a document for a script element.
   *
   * JSON_HEX_TAG turns < and > into < and >, so no value can close
   * the script element it is printed in.
   */
  public static function encode(array $document): string {
    return json_encode($document, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_THROW_ON_ERROR);
  }

  /**
   * The business's @type: one type as a string, several as a list.
   *
   * @param string[] $types
   *   The schema.org type names.
   */
  public static function type(array $types): string|array {
    $types = array_values(array_unique(array_filter(array_map('strval', $types))));
    if ($types === []) {
      return 'LocalBusiness';
    }
    return count($types) === 1 ? $types[0] : $types;
  }

  /**
   * A PostalAddress, or an empty array when no part of it is known.
   *
   * @param array $address
   *   Keys street_address, address_locality, address_region, postal_code,
   *   address_country.
   */
  public static function postalAddress(array $address): array {
    $postal = self::clean([
      'streetAddress' => $address['street_address'] ?? '',
      'addressLocality' => $address['address_locality'] ?? '',
      'addressRegion' => $address['address_region'] ?? '',
      'postalCode' => $address['postal_code'] ?? '',
      'addressCountry' => $address['address_country'] ?? '',
    ]);
    return $postal === [] ? [] : ['@type' => 'PostalAddress'] + $postal;
  }

  /**
   * GeoCoordinates, or an empty array.
   *
   * @param array|null $geo
   *   Keys latitude and longitude, numbers.
   */
  public static function geo(?array $geo): array {
    if (!isset($geo['latitude'], $geo['longitude'])) {
      return [];
    }
    return [
      '@type' => 'GeoCoordinates',
      'latitude' => (float) $geo['latitude'],
      'longitude' => (float) $geo['longitude'],
    ];
  }

  /**
   * Regular hours as OpeningHoursSpecification, one per group of days.
   *
   * @param array $hours
   *   A list of days (schema.org day names), opens and closes (HH:MM).
   */
  public static function openingHours(array $hours): array {
    $specifications = [];
    foreach ($hours as $entry) {
      $days = array_values(array_filter(array_map('strval', $entry['days'] ?? [])));
      $opens = self::time((string) ($entry['opens'] ?? ''));
      $closes = self::time((string) ($entry['closes'] ?? ''));
      if ($days === [] || $opens === '' || $closes === '') {
        continue;
      }
      $specifications[] = [
        '@type' => 'OpeningHoursSpecification',
        'dayOfWeek' => $days,
        'opens' => $opens,
        'closes' => $closes,
      ];
    }
    return $specifications;
  }

  /**
   * Special hours: a date range with its own hours, or closed.
   *
   * Google's form for a day the business is closed: opens and closes both
   * 00:00:00.
   *
   * @param array $hours
   *   A list of valid_from, valid_through (YYYY-MM-DD), closed, opens and
   *   closes.
   */
  public static function specialHours(array $hours): array {
    $specifications = [];
    foreach ($hours as $entry) {
      $from = (string) ($entry['valid_from'] ?? '');
      $through = (string) ($entry['valid_through'] ?? '') ?: $from;
      if ($from === '') {
        continue;
      }
      if (!empty($entry['closed'])) {
        $opens = $closes = '00:00:00';
      }
      else {
        $opens = self::time((string) ($entry['opens'] ?? ''));
        $closes = self::time((string) ($entry['closes'] ?? ''));
        if ($opens === '' || $closes === '') {
          continue;
        }
      }
      $specifications[] = [
        '@type' => 'OpeningHoursSpecification',
        'validFrom' => $from,
        'validThrough' => $through,
        'opens' => $opens,
        'closes' => $closes,
      ];
    }
    return $specifications;
  }

  /**
   * The shop-wide MerchantReturnPolicy, or an empty array.
   *
   * Google requires the category and the country it applies to; a finite
   * window also needs its number of days.
   *
   * @param array $policy
   *   Keys category, days, country, method, fees.
   */
  public static function returnPolicy(array $policy): array {
    $category = (string) ($policy['category'] ?? '');
    $country = (string) ($policy['country'] ?? '');
    if ($category === '' || $country === '') {
      return [];
    }
    $days = (int) ($policy['days'] ?? 0);
    $finite = $category === 'MerchantReturnFiniteReturnWindow';
    if ($finite && $days <= 0) {
      return [];
    }
    $permitted = $category !== 'MerchantReturnNotPermitted';
    return self::clean([
      '@type' => 'MerchantReturnPolicy',
      'applicableCountry' => $country,
      'returnPolicyCategory' => self::enum($category),
      'merchantReturnDays' => $finite ? $days : NULL,
      'returnMethod' => $permitted ? self::enum((string) ($policy['method'] ?? '')) : '',
      'returnFees' => $permitted ? self::enum((string) ($policy['fees'] ?? '')) : '',
    ]);
  }

  /**
   * Departments, each a nested business in the same place.
   *
   * @param array $departments
   *   A list of types, name, url, telephone.
   * @param array $address
   *   The business's PostalAddress, which every department shares.
   */
  public static function departments(array $departments, array $address): array {
    $nested = [];
    foreach ($departments as $department) {
      if (($department['name'] ?? '') === '') {
        continue;
      }
      $nested[] = self::clean([
        '@type' => self::type($department['types'] ?? []),
        'name' => $department['name'],
        'url' => $department['url'] ?? '',
        'telephone' => $department['telephone'] ?? '',
        'address' => $address,
      ]);
    }
    return $nested;
  }

  /**
   * ContactPoints, each with a type and a telephone or an e-mail address.
   *
   * @param array $points
   *   A list of contact_type, telephone, email.
   */
  public static function contactPoints(array $points): array {
    $contacts = [];
    foreach ($points as $point) {
      $contact = self::clean([
        '@type' => 'ContactPoint',
        'contactType' => $point['contact_type'] ?? '',
        'telephone' => $point['telephone'] ?? '',
        'email' => $point['email'] ?? '',
      ]);
      if (isset($contact['contactType']) && (isset($contact['telephone']) || isset($contact['email']))) {
        $contacts[] = $contact;
      }
    }
    return $contacts;
  }

  /**
   * A price as Google reads it: digits and a dot, above zero, else NULL.
   *
   * Commerce stores six decimals; trailing zeros go ("4000.000000" is
   * "4000", "12.50" is "12.5").
   */
  public static function price(string $number): ?string {
    $number = trim($number);
    if (!preg_match('/^[0-9]+(\.[0-9]+)?$/', $number)) {
      return NULL;
    }
    if (str_contains($number, '.')) {
      $number = rtrim(rtrim($number, '0'), '.');
    }
    $number = ltrim($number, '0');
    if ($number === '' || $number[0] === '.') {
      $number = '0' . $number;
    }
    return (float) $number > 0 ? $number : NULL;
  }

  /**
   * HH:MM as Google's hh:mm:ss, or an empty string when malformed.
   */
  public static function time(string $time): string {
    if (preg_match('/^([01][0-9]|2[0-3]):([0-5][0-9])(:[0-5][0-9])?$/', $time, $parts)) {
      return $parts[1] . ':' . $parts[2] . ($parts[3] ?? ':00');
    }
    return '';
  }

  /**
   * A schema.org enumeration member as its full URL ('' stays '').
   */
  public static function enum(string $member): string {
    if ($member === '' || str_starts_with($member, 'https://') || str_starts_with($member, 'http://')) {
      return $member;
    }
    return self::CONTEXT . '/' . $member;
  }

  /**
   * Drops every empty value (NULL, '', []), recursively.
   *
   * Zero and FALSE are values and stay. A nested node left with nothing but
   * its @type goes too: a type with no properties says nothing.
   */
  public static function clean(array $data): array {
    foreach ($data as $key => $value) {
      if (is_array($value)) {
        $value = self::clean($value);
        if (array_is_list($data[$key])) {
          $value = array_values($value);
        }
        if (array_keys($value) === ['@type']) {
          $value = [];
        }
        $data[$key] = $value;
      }
      if ($value === NULL || $value === '' || $value === []) {
        unset($data[$key]);
      }
    }
    return $data;
  }

}
