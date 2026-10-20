<?php

/**
 * @file
 * Builds data/cities.json from a GeoNames "cities15000.txt" dump.
 *
 * Usage, from the project root, inside DDEV:
 *
 *   curl -sSO https://download.geonames.org/export/dump/cities15000.zip
 *   unzip cities15000.zip
 *   ddev exec php web/modules/upstream/ga4_report/scripts/build-cities.php \
 *     cities15000.txt web/modules/upstream/ga4_report/data/cities.json
 *
 * The table is { "CC": { "folded name": [lat, lon], ... }, ... }, positions
 * rounded to two decimals (about a kilometre). Each city is filed under its
 * name and its ASCII name, folded as CityLocator::fold() does; when two
 * cities of one country fold to the same name, the more populous one wins.
 * GeoNames data is CC BY 4.0: keep the credit on the report page.
 */

declare(strict_types=1);

use Drupal\ga4_report\Report\CityLocator;

// The file sits under the docroot; over HTTP it does nothing.
if (PHP_SAPI !== 'cli') {
  exit;
}

[$script, $source, $target] = $argv + [NULL, NULL, NULL];
if (!$source || !$target || !is_readable($source)) {
  fwrite(STDERR, "Usage: php $script <cities15000.txt> <cities.json>\n");
  exit(2);
}

$root = dirname(__DIR__, 5);
require $root . '/vendor/autoload.php';
require dirname(__DIR__) . '/src/Report/CityLocator.php';

$best = [];
$handle = fopen($source, 'r');
while (($line = fgets($handle)) !== FALSE) {
  $f = explode("\t", rtrim($line, "\n"));
  if (count($f) < 15) {
    continue;
  }
  [$name, $ascii, $lat, $lon, $country, $population] = [$f[1], $f[2], (float) $f[4], (float) $f[5], $f[8], (int) $f[14]];
  foreach (array_unique([CityLocator::fold($name), CityLocator::fold($ascii)]) as $key) {
    if ($key === '' || ($best[$country][$key][2] ?? -1) >= $population) {
      continue;
    }
    $best[$country][$key] = [round($lat, 2), round($lon, 2), $population];
  }
}
fclose($handle);

ksort($best);
$table = [];
$count = 0;
foreach ($best as $country => $cities) {
  ksort($cities);
  foreach ($cities as $key => [$lat, $lon]) {
    $table[$country][$key] = [$lat, $lon];
    $count++;
  }
}
file_put_contents($target, json_encode($table, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION) . "\n");
printf("%d names in %d countries -> %s (%d bytes)\n", $count, count($table), $target, filesize($target));
