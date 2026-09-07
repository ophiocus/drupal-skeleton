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

// …and name who is allowed to send them. This line is not optional decoration:
// Symfony honours the trusted headers above ONLY when the connecting address
// appears in this list, and an unset list means "trust nobody". Without it the
// two settings above silently do nothing, and the site advertises itself as
// http:// while being served over TLS — putting the wrong scheme in every
// canonical tag, JSON-LD url and sitemap entry. The failure is invisible from
// inside the container (Drupal is simply told the request was plain HTTP), so
// it survives indefinitely until someone reads a canonical tag from outside.
//
// Trusting the immediate peer is correct for the standard topology this
// skeleton targets: the app container publishes NO ports and is reachable only
// from the reverse proxy on an internal Docker network, so REMOTE_ADDR *is* the
// proxy. If a deployment exposes the container directly, set
// DRUPAL_REVERSE_PROXY_ADDRESS to the proxy's address instead — with a directly
// reachable container, trusting the peer would let any client forge
// X-Forwarded-Proto and X-Forwarded-For.
$settings['reverse_proxy_addresses'] = array_values(array_filter([
  getenv('DRUPAL_REVERSE_PROXY_ADDRESS') ?: ($_SERVER['REMOTE_ADDR'] ?? NULL),
]));

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

// --- Outbound mail ----------------------------------------------------------
// Drupal's default transport is PHP mail(), which shells out to sendmail — and
// the drupal:*-apache base image ships NO MTA. With no explicit transport every
// mail Drupal sends (contact form, password reset, order receipt) fails. The
// dangerous part is the contact form: Drupal reports "Your message has been
// sent" to the visitor either way, so the failure is invisible from the outside
// and the enquiry is simply gone. See docs/DEPLOY.md §6 and BATTLE_SCARS §22.
//
// MAILER_DSN is a single Symfony Mailer DSN, so the whole credential travels as
// one env var and no secret can reach config/sync:
//   smtp://user:pass@smtp.example.com:587
//   smtp://apikey:SG.xxxxxxxx@smtp.sendgrid.net:587
//
// Deliberately NOT defaulted to the null transport: with MAILER_DSN unset the
// site keeps the native transport, so mail fails LOUDLY in dblog rather than
// being swallowed. A silent success is the exact failure this block exists to
// prevent — never "fix" a noisy log here by pointing it at null.
$dsn = getenv('MAILER_DSN');
if ($dsn) {
  $config['symfony_mailer_lite.symfony_mailer_lite_transport.env']['configuration']['dsn'] = $dsn;
  $config['symfony_mailer_lite.settings']['default_transport'] = 'env';
}

// From: address for everything Drupal sends. It must sit on a domain the relay
// is authorised to send for (SPF/DKIM) or the mail is spam-filed.
$siteMail = getenv('SITE_MAIL');
if ($siteMail) {
  $config['system.site']['mail'] = $siteMail;
}

// Where the site-wide contact form delivers. Env-driven because the recipient
// is per-property and per-environment (a dev env must never mail the real
// inbox), and because it is a personal address that does not belong in git.
$contactTo = getenv('CONTACT_RECIPIENT');
if ($contactTo) {
  $config['contact.form.feedback']['recipients'] = array_map('trim', explode(',', $contactTo));
}

// --- Analytics + advertising (env-driven) ----------------------------------
// Neither ID is secret (both appear in page source), but env-driving keeps the
// committed config inert: dev never pollutes prod analytics, and AdSense stays
// dark until the account is approved and the ID lands in the VPS .env.
// google_tag + adsense are skeleton defaults, enabled per site:
//   drush en google_tag adsense
//
//   GTM_CONTAINER_ID     GTM container "GTM-XXXXXXX" or a GA4 id "G-XXXXXXXXXX"
//   ADSENSE_PUBLISHER_ID AdSense publisher id, e.g. "pub-1234567890123456"
//                        (no "ca-" prefix; the module adds it for ad markup and
//                         omits it for ads.txt)

// GTM/GA4: empty list => google_tag injects nothing. Consent Mode v2 stays on.
$tag = getenv('GTM_CONTAINER_ID');
$config['google_tag.container.default']['tag_container_ids'] = $tag ? [$tag] : [];

// AdSense: live only when a publisher ID is present. Otherwise fully dark —
// no ad markup, no /ads.txt (404), no placeholder box.
$pub = getenv('ADSENSE_PUBLISHER_ID');
if ($pub) {
  $config['adsense.settings']['adsense_basic_id'] = $pub;
  $config['adsense.settings']['adsense_disable'] = FALSE;
  $config['adsense.settings']['adsense_placeholder'] = FALSE;
}
else {
  $config['adsense.settings']['adsense_basic_id'] = '';
  $config['adsense.settings']['adsense_disable'] = TRUE;
  $config['adsense.settings']['adsense_placeholder'] = FALSE;
}
