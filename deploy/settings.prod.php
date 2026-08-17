<?php

/**
 * @file
 * Production settings, baked into the container image.
 *
 * web/sites/default/ is gitignored, so the local DDEV settings.php never
 * reaches the repo. This file is the tracked, deployable counterpart: the
 * Dockerfile copies it to web/sites/default/settings.php in the image.
 *
 * Every secret and every per-environment value comes from the environment
 * (deploy/.env.example lists the keys) — nothing is committed here, and the
 * same image serves production and any dev/<slug> environment unchanged.
 * Project-specific settings (analytics IDs, API endpoints, feature flags)
 * go below the "Project settings" marker, env-driven the same way.
 */

declare(strict_types=1);

// --- Database ---------------------------------------------------------------
$databases['default']['default'] = [
  'driver' => 'mysql',
  'database' => getenv('DRUPAL_DB_NAME') ?: 'drupal',
  'username' => getenv('DRUPAL_DB_USER') ?: 'drupal',
  'password' => getenv('DRUPAL_DB_PASSWORD') ?: '',
  'host' => getenv('DRUPAL_DB_HOST') ?: 'db',
  'port' => getenv('DRUPAL_DB_PORT') ?: '3306',
  'prefix' => '',
  'collation' => 'utf8mb4_general_ci',
];

// --- Config -----------------------------------------------------------------
// Config lives in the repo and is the deploy source of truth: `drush deploy`
// imports from here on every release. Path is relative to the docroot.
$settings['config_sync_directory'] = '../config/sync';

// --- Core hardening ---------------------------------------------------------
$settings['hash_salt'] = getenv('DRUPAL_HASH_SALT') ?: '';
$settings['update_free_access'] = FALSE;
$settings['file_private_path'] = getenv('DRUPAL_PRIVATE_PATH') ?: '/opt/drupal/private';
$settings['file_temp_path'] = '/tmp';

// Releases are immutable images; errors go to the log, never to the page.
$config['system.logging']['error_level'] = 'hide';

// --- Reverse proxy (Traefik terminates TLS) ---------------------------------
// Honour the forwarded proto/host so Drupal builds https:// URLs and sees the
// real client IP.
$settings['reverse_proxy'] = TRUE;
$settings['reverse_proxy_trusted_headers'] =
  \Symfony\Component\HttpFoundation\Request::HEADER_X_FORWARDED_FOR
  | \Symfony\Component\HttpFoundation\Request::HEADER_X_FORWARDED_PROTO
  | \Symfony\Component\HttpFoundation\Request::HEADER_X_FORWARDED_PORT;

// --- Trusted hosts ----------------------------------------------------------
// Comma-separated regexes in DRUPAL_TRUSTED_HOSTS, e.g.
//   ^example\.com$,^www\.example\.com$
// The fallback below is deliberately unusable ("example.com") so a
// misconfigured environment fails loudly (Drupal refuses the host) instead
// of quietly serving under an unexpected name.
$trusted = getenv('DRUPAL_TRUSTED_HOSTS');
$settings['trusted_host_patterns'] = $trusted
  ? array_map('trim', explode(',', $trusted))
  : ['^example\.com$', '^www\.example\.com$'];

// --- Project settings -------------------------------------------------------
// Keep every value env-driven so committed config stays inert across
// environments (dev never pollutes prod analytics; integrations stay dark
// until their key lands in the host .env). Pattern:
//
//   $ga4 = getenv('DRUPAL_GA4_ID');
//   $config['google_tag.container.default']['tag_container_ids'] = $ga4 ? [$ga4] : [];
