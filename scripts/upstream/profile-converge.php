<?php

/**
 * @file
 * Makes the site's install profile the one config/sync names. Idempotent.
 *
 * Run:   ddev drush php:script scripts/upstream/profile-converge.php
 * Check: ddev drush php:script scripts/upstream/profile-converge.php -- --check
 * Host:  the deploy runs it before `drush deploy` (docs/INSTALL_PROFILES.md).
 *
 * Why it exists: config/sync is the contract for everything but this. Core's
 * config import refuses a core.extension whose `profile` differs from the
 * active one ("Cannot change the install profile from standard to
 * skeleton_site once Drupal is installed", ConfigImportSubscriber), so a
 * deployed site cannot change profile through `drush cim`. Core does let a
 * profile be uninstalled (InstallProfileUninstallValidator), and a profile is
 * a module, so the switch is: uninstall the old profile, name the new one in
 * core.extension, install it with the weight the installer gives a profile
 * (1000). After that the next `drush cim` finds nothing to change in
 * core.extension. No update hook (PROTOCOL D25), no database push.
 *
 * It refuses, and changes nothing, when:
 *   - config/sync names no profile, or a profile its own module list lacks;
 *   - the profile it names is not in the codebase, ships config of its own,
 *     or needs a module the site has not installed;
 *   - a module the site has installed is not in the codebase (the switch
 *     rebuilds the container, which needs every one of them: deploy the code
 *     first);
 *   - config depends on the profile being left (uninstalling it would delete
 *     that config), or a module or core's validators hold that profile.
 * On a site whose profile already matches it prints one line and does nothing.
 * A run that stopped halfway (the new profile named but not installed) is
 * finished by the next one.
 */

declare(strict_types=1);

use Drupal\Core\Extension\Dependency;

$check = in_array('--check', $extra ?? [], TRUE);

$sync = \Drupal::service('config.storage.sync')->read('core.extension');
if ($sync === FALSE) {
  throw new \RuntimeException('profile: config/sync has no core.extension.yml; nothing to converge onto.');
}
$want = $sync['profile'] ?? NULL;
$active = \Drupal::config('core.extension');
$have = $active->get('profile') ?: NULL;
$installed = $active->get('module') ?: [];

if ($want === $have && isset($installed[$want])) {
  print "profile: $have, as config/sync names it; nothing to do\n";
  return;
}
if (!$want) {
  throw new \RuntimeException("profile: config/sync names no profile while the site runs '$have'; leaving a profile without a successor is not converged here.");
}
if (!isset($sync['module'][$want])) {
  throw new \RuntimeException("profile: config/sync names '$want' but its module list lacks it; config import would refuse it.");
}

$profiles = \Drupal::service('extension.list.profile');
if (!$profiles->exists($want)) {
  throw new \RuntimeException("profile: '$want' is not in this codebase.");
}
// A profile installed on a built site installs its own config too, which
// would collide with the site's; skeleton_site ships none.
foreach (['install', 'optional'] as $kind) {
  if (glob($profiles->get($want)->getPath() . "/config/$kind/*.yml")) {
    throw new \RuntimeException("profile: '$want' ships config in config/$kind, which would collide with this site's config.");
  }
}
$absent = array_keys(array_diff_key($installed, \Drupal::service('extension.list.module')->getList()));
if ($absent) {
  throw new \RuntimeException('profile: modules installed on this site are not in this codebase: ' . implode(', ', $absent) . '. Deploy the code they need first.');
}
$module_handler = \Drupal::moduleHandler();
$missing = [];
foreach ($profiles->getAllAvailableInfo()[$want]['dependencies'] ?? [] as $dependency) {
  $name = Dependency::createFromString($dependency)->getName();
  if (!$module_handler->moduleExists($name)) {
    $missing[] = $name;
  }
}
if ($missing) {
  throw new \RuntimeException("profile: '$want' depends on modules this site has not installed: " . implode(', ', $missing) . '.');
}

// The profile being left, if there is one (none when a run stopped halfway).
$leaving = $have && $have !== $want ? $have : NULL;
if ($leaving) {
  // Uninstalling a module deletes the config entities that depend on it.
  $dependents = [];
  foreach (\Drupal::service('config.manager')->findConfigEntityDependencies('module', [$leaving]) as $dependent) {
    $dependents[] = $dependent->getConfigDependencyName();
  }
  if ($dependents) {
    throw new \RuntimeException("profile: config depends on '$leaving' and would be deleted with it: " . implode(', ', $dependents) . '.');
  }
  $holders = array_keys(array_intersect_key(
    \Drupal::service('extension.list.module')->get($leaving)->required_by,
    $module_handler->getModuleList(),
  ));
  if ($holders) {
    throw new \RuntimeException("profile: installed modules depend on '$leaving': " . implode(', ', $holders) . '.');
  }
  $reasons = \Drupal::service('module_installer')->validateUninstall([$leaving]);
  if ($reasons) {
    throw new \RuntimeException("profile: '$leaving' cannot be uninstalled: " . implode(' ', array_map('strval', array_merge(...array_values($reasons)))));
  }
}

$from = $leaving ?? ($have ? "$have (named, not installed)" : '(none)');
if ($check) {
  print "profile: $from -> $want would be converged; --check changes nothing\n";
  return;
}

if ($leaving && !\Drupal::service('module_installer')->uninstall([$leaving], FALSE)) {
  throw new \RuntimeException("profile: uninstalling '$leaving' failed.");
}
\Drupal::configFactory()->getEditable('core.extension')->set('profile', $want)->save();
// The active profile is the container parameter install_profile, and the
// module list finds a profile only once it is the active one; the list is
// cached, so it is rebuilt too.
\Drupal::service('kernel')->rebuildContainer();
\Drupal::service('extension.list.module')->reset();
\Drupal::service('module_installer')->install([$want], FALSE);
// The installer gives the profile this weight (install_finished()), and
// config/sync records it.
module_set_weight($want, 1000);
drupal_flush_all_caches();

print "profile: $from -> $want\n";
