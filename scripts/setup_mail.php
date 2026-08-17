<?php

/**
 * @file
 * Wires outbound mail + the site-wide contact form. Idempotent.
 *
 * Run:  ddev drush php:script scripts/setup_mail.php
 * Prod: docker compose exec -T -u www-data drupal drush php:script scripts/setup_mail.php
 *
 * What it creates:
 *   - a symfony_mailer_lite DSN transport with an EMPTY dsn (safe to commit;
 *     deploy/settings.prod.php fills it from MAILER_DSN at runtime);
 *   - the core contact module's site-wide "feedback" form, reachable at
 *     /contact, with a placeholder recipient that settings.prod.php overrides
 *     from CONTACT_RECIPIENT;
 *   - a Main-menu link to /contact.
 *
 * Nothing here contains a credential or a real address — those arrive from the
 * host .env. Export afterwards with `drush config:export` so the placeholders
 * (not the runtime values) are what lands in config/sync.
 */

declare(strict_types=1);

use Drupal\contact\Entity\ContactForm;
use Drupal\menu_link_content\Entity\MenuLinkContent;

$moduleInstaller = \Drupal::service('module_installer');
$moduleHandler = \Drupal::moduleHandler();

// 1. Modules ---------------------------------------------------------------
foreach (['contact', 'symfony_mailer_lite'] as $m) {
  if (!$moduleHandler->moduleExists($m)) {
    $moduleInstaller->install([$m]);
    print "  installed module: $m\n";
  }
  else {
    print "  module already enabled: $m\n";
  }
}

// 2. Transport -------------------------------------------------------------
// A DSN transport whose dsn stays EMPTY in config. settings.prod.php sets both
// the dsn and default_transport when MAILER_DSN is present; when it is absent
// the site keeps the native transport and mail fails loudly, which is
// deliberate — a silently-swallowed contact form is the failure being avoided.
$storage = \Drupal::entityTypeManager()->getStorage('symfony_mailer_lite_transport');
$transport = $storage->load('env');
if (!$transport) {
  $transport = $storage->create([
    'id' => 'env',
    'label' => 'Environment DSN',
    'plugin' => 'dsn',
    'configuration' => ['dsn' => ''],
  ]);
  $transport->save();
  print "  created transport 'env' (dsn empty; filled from MAILER_DSN)\n";
}
else {
  print "  transport 'env' already present\n";
}

// 2b. Route Drupal's mail THROUGH that transport ---------------------------
// Installing symfony_mailer_lite does NOT make it the active mailer. Its
// hook_install only registers it as an *available* option under
// mailsystem.settings modules.symfony_mailer_lite.none; the site-wide default
// stays `php_mail`, i.e. PHP mail() -> sendmail. So the module is enabled, a
// transport exists, the DSN is set — and every mail still ignores all of it.
//
// Worse, this passes a naive test: a dev box (DDEV) has a working sendmail
// that catches mail, so "the message arrived" proves nothing about the
// transport. Assert the defaults explicitly. See BATTLE_SCARS §22.
$mailsystem = \Drupal::configFactory()->getEditable('mailsystem.settings');
$mailsystem
  ->set('defaults.sender', 'symfony_mailer_lite')
  ->set('defaults.formatter', 'symfony_mailer_lite')
  ->save();
print "  mailsystem defaults -> symfony_mailer_lite (sender + formatter)\n";

// 3. Contact form ----------------------------------------------------------
// Core ships a "feedback" form on install; create it if this site lacks one.
$form = ContactForm::load('feedback');
if (!$form) {
  $form = ContactForm::create([
    'id' => 'feedback',
    'label' => 'Website feedback',
    'recipients' => ['change-me@example.com'],
    'reply' => '',
    'weight' => 0,
    'message' => 'Your message has been sent.',
    'redirect' => '',
  ]);
  $form->save();
  print "  created contact form 'feedback'\n";
}
else {
  print "  contact form 'feedback' already present\n";
}

// Make it the default so /contact resolves without a form argument.
\Drupal::configFactory()->getEditable('contact.settings')
  ->set('default_form', 'feedback')
  ->save();

// Anonymous visitors must be able to use the form, or /contact 403s for
// exactly the audience a contact page exists to serve.
user_role_grant_permissions('anonymous', ['access site-wide contact form']);
user_role_grant_permissions('authenticated', ['access site-wide contact form']);
print "  granted 'access site-wide contact form' to anonymous + authenticated\n";

// 4. Menu link -------------------------------------------------------------
// menu_link_content is not in every install profile (the `minimal` profile
// ships without it), so this step is optional rather than fatal — the contact
// form is reachable at /contact regardless of whether it is in a menu.
if ($moduleHandler->moduleExists('menu_link_content')) {
  $menuStorage = \Drupal::entityTypeManager()->getStorage('menu_link_content');
  $links = $menuStorage->loadByProperties([
    'menu_name' => 'main',
    'link__uri' => 'internal:/contact',
  ]);
  $link = $links ? reset($links) : MenuLinkContent::create([
    'menu_name' => 'main',
    'link' => ['uri' => 'internal:/contact'],
  ]);
  $link->set('title', 'Contact');
  $link->set('weight', 10);
  $link->set('expanded', FALSE);
  $link->save();
  print "  main-menu link -> /contact\n";
}
else {
  print "  menu_link_content not installed — skipped the menu link (/contact still works)\n";
}

print "Mail + contact form ready. Set MAILER_DSN, SITE_MAIL and CONTACT_RECIPIENT in the host .env.\n";
