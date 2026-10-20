<?php

declare(strict_types=1);

namespace Drupal\ga4_report\Report;

use Drupal\Component\Transliteration\PhpTransliteration;

/**
 * Places a city Analytics names on a latitude and longitude.
 *
 * Analytics reports a city by name and never by position. The positions come
 * from a table bundled with the module (data/cities.json), built from GeoNames
 * "cities15000" (every city of 15,000 people or more; CC BY 4.0) by
 * scripts/build-cities.php. Cities are matched on the ISO country code plus
 * the name folded to plain lower-case ASCII; when two cities of one country
 * fold to the same name, the table keeps the more populous one. A city the
 * table does not hold is reported as not placed, never guessed.
 */
final class CityLocator {

  /**
   * The table, loaded on first use: country code => folded name => [lat, lon].
   *
   * @var array<string, array<string, array{0: float, 1: float}>>|null
   */
  private ?array $table = NULL;

  /**
   * Reads its table from a file, on first use.
   *
   * @param string $path
   *   The JSON table to read.
   */
  public function __construct(
    private readonly string $path,
  ) {}

  /**
   * The table bundled with the module.
   */
  public static function bundled(): self {
    return new self(dirname(__DIR__, 2) . '/data/cities.json');
  }

  /**
   * The position of a city, or NULL when the table does not hold it.
   *
   * @return array{0: float, 1: float}|null
   *   Latitude and longitude in degrees.
   */
  public function locate(string $countryCode, string $city): ?array {
    if ($this->table === NULL) {
      $json = is_readable($this->path) ? file_get_contents($this->path) : FALSE;
      $this->table = $json ? (array) json_decode($json, TRUE) : [];
    }
    $point = $this->table[strtoupper($countryCode)][self::fold($city)] ?? NULL;
    return $point ? [(float) $point[0], (float) $point[1]] : NULL;
  }

  /**
   * A name as plain lower-case ASCII words: "Bogotá D.C." becomes "bogota d c".
   */
  public static function fold(string $name): string {
    static $transliteration;
    $transliteration ??= new PhpTransliteration();
    $ascii = strtolower($transliteration->transliterate($name, 'en', ''));
    return trim((string) preg_replace('/[^a-z0-9]+/', ' ', $ascii));
  }

}
