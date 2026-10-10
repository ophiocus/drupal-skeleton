<?php

/**
 * @file
 * Parses every YAML file named on the command line; exits 1 if any fails.
 *
 * Part of the integrity gate of scripts/ci/tests.sh. Tests install from each
 * module's config/install, so a broken file in config/sync passes every test
 * suite and fails only at `drush cim` on the server.
 */

declare(strict_types=1);

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

require __DIR__ . '/../../vendor/autoload.php';

$bad = 0;
foreach (array_slice($argv, 1) as $file) {
  try {
    Yaml::parseFile($file, Yaml::PARSE_CUSTOM_TAGS);
  }
  catch (ParseException $e) {
    fwrite(STDERR, $file . ': ' . $e->getMessage() . PHP_EOL);
    $bad++;
  }
}
echo sprintf('%d YAML files parsed, %d broken', $argc - 1, $bad) . PHP_EOL;
exit($bad > 0 ? 1 : 0);
