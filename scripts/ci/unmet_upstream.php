<?php

/**
 * @file
 * Prints the upstream extensions this codebase cannot run, one path per line.
 *
 * Every site carries every upstream module (docs/UPSTREAM.md) but not every
 * module's dependencies: a site without Commerce carries shop_delivery and
 * cannot install it. The test gate leaves such an extension out of static
 * analysis and of the PHPUnit suites, where its missing parent classes would
 * otherwise fail a site that never enables it. An extension is unmet when a
 * module it depends on is not in the codebase, or is itself unmet.
 *
 * Usage (from the project root): php scripts/ci/unmet_upstream.php
 */

declare(strict_types=1);

$root = getcwd();

/**
 * Finds every *.info.yml of a module under a directory, tests excluded.
 *
 * @return array<string, string>
 *   Module directory (relative to the project root), keyed by module name.
 */
function modules_under(string $root, string $dir): array {
  $found = [];
  if (!is_dir("$root/$dir")) {
    return $found;
  }
  $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$root/$dir", FilesystemIterator::SKIP_DOTS));
  foreach ($it as $file) {
    $name = $file->getFilename();
    if (!str_ends_with($name, '.info.yml')) {
      continue;
    }
    $path = substr($file->getPath(), strlen($root) + 1);
    $module = substr($name, 0, -9);
    if (basename($path) !== $module || preg_match('#/(tests|node_modules)/#', "/$path/")) {
      continue;
    }
    $found[$module] = $path;
  }
  return $found;
}

/**
 * The module names an info file depends on.
 *
 * @return string[]
 *   Machine names, without project prefix or version constraint.
 */
function dependencies_of(string $info): array {
  $deps = [];
  $in = FALSE;
  foreach (file($info, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
    if (preg_match('/^dependencies:\s*$/', $line)) {
      $in = TRUE;
      continue;
    }
    if ($in && preg_match('/^\s+-\s*[\'"]?([^\'"]+)/', $line, $m)) {
      $dep = trim(preg_replace('/\(.*$/', '', $m[1]));
      $deps[] = str_contains($dep, ':') ? substr($dep, strpos($dep, ':') + 1) : $dep;
      continue;
    }
    if ($in && preg_match('/^\S/', $line)) {
      $in = FALSE;
    }
  }
  return $deps;
}

$available = modules_under($root, 'web/core/modules') + modules_under($root, 'web/modules');
$upstream = modules_under($root, 'web/modules/upstream');

$unmet = [];
do {
  $changed = FALSE;
  foreach ($upstream as $module => $path) {
    if (isset($unmet[$module])) {
      continue;
    }
    foreach (dependencies_of("$root/$path/$module.info.yml") as $dep) {
      if (!isset($available[$dep]) || isset($unmet[$dep])) {
        $unmet[$module] = $path;
        fwrite(STDERR, "upstream $module is not tested here: it needs $dep, which this codebase does not carry\n");
        $changed = TRUE;
        break;
      }
    }
  }
} while ($changed);

foreach ($unmet as $path) {
  echo $path, "\n";
}
