<?php

declare(strict_types=1);

namespace Drupal\ad_inventory;

use Drupal\ad_inventory\Entity\AdBookingInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;

/**
 * Every gate that keeps a banner from serving, by name.
 *
 * Read by the status page, `drush ad-inventory:status` and the report
 * submodule's alerts. Severity "error" means something that should serve
 * cannot; "warning" means something is missing or unused; "ok" says a gate
 * is open.
 */
final class StatusChecker {

  public function __construct(
    private readonly Inventory $inventory,
    private readonly NetworkGate $gate,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly Calendar $calendar,
    private readonly Placements $placements,
  ) {}

  /**
   * The gates.
   *
   * @return array<int, array{severity: string, gate: string, message: string}>
   *   Errors first, then warnings, then open gates.
   */
  public function gates(): array {
    $gates = [];
    $settings = $this->configFactory->get('ad_inventory.settings');
    $label = (string) $settings->get('label');

    $global = (string) $settings->get('consent.global');
    if ($global === '' || (string) $settings->get('consent.property') === '') {
      $gates[] = $this->line('error', 'consent', 'No consent signal is named (settings: consent object and property). Every slot shows the blocked banner, for every reader.');
    }
    else {
      $gates[] = $this->line('ok', 'consent', sprintf('Creatives wait for window.%s.%s === true, re-read on the "%s" event.', $global, $settings->get('consent.property'), $settings->get('consent.event')));
    }
    if (trim($label) === '') {
      $gates[] = $this->line('error', 'label', 'The slot label is empty; every slot must carry a visible label.');
    }

    $providers = $this->inventory->providers();
    if (!$this->gate->enabled()) {
      $gates[] = $this->line($providers ? 'warning' : 'ok', 'network', sprintf('Network serving is off (set %s=1 or $settings[\'ad_inventory_network\']). Bookings and house creatives still serve.', NetworkGate::ENV));
    }
    else {
      $gates[] = $this->line('ok', 'network', 'Network serving is on in this environment.');
    }

    $described = [];
    $ads_txt = $this->adsTxt();
    foreach ($providers as $id => $provider) {
      $plugin = $provider->plugin();
      $name = (string) $provider->label();
      if ($plugin === NULL) {
        $gates[] = $this->line('error', 'provider:' . $id, sprintf('%s: its plugin "%s" is missing.', $name, $provider->pluginId()));
        continue;
      }
      $described[] = ProviderPolicy::describe((string) $id, $name, $plugin);
      if ($plugin->requiresAccount() && $plugin->account() === NULL) {
        $gates[] = $this->line('error', 'provider:' . $id, sprintf('%s: no account id. Set $settings[\'ad_inventory_accounts\'][\'%s\'] or the provider\'s environment variable.', $name, $id));
      }
      if (!ProviderPolicy::labelAllowed($label, $plugin->allowedLabels())) {
        $gates[] = $this->line('error', 'label:' . $id, sprintf('%s allows only %s above its units; the site label is "%s".', $name, implode(' / ', $plugin->allowedLabels()), $label));
      }
      foreach ($plugin->adsTxtLines() as $line) {
        if (!self::adsTxtHas($ads_txt, $line)) {
          $loud = $plugin->adsTxtLineRequired() && trim($ads_txt) !== '';
          $gates[] = $this->line($loud ? 'error' : 'warning', 'ads_txt:' . $id, sprintf(
            '%s: /ads.txt lacks "%s".%s',
            $name,
            $line,
            $loud ? ' The file exists without it, so the network rejects every request from this site.' : '',
          ));
        }
      }
      $units = 0;
      foreach ($this->inventory->slots() as $slot) {
        $units += isset($slot->units()[$id]) ? 1 : 0;
      }
      if ($units === 0) {
        $gates[] = $this->line('warning', 'units:' . $id, sprintf('%s is enabled but no slot names a unit for it.', $name));
      }
    }
    foreach (ProviderPolicy::conflicts($described) as $conflict) {
      $gates[] = $this->line('error', 'conflict', $conflict);
    }

    $styles = $this->entityTypeManager->getStorage('image_style');
    foreach ($this->inventory->formats() as $format) {
      foreach ([1, 2] as $density) {
        if ($styles->load($format->styleName($density)) === NULL) {
          $gates[] = $this->line('warning', 'format:' . $format->id(), sprintf('The image style %s of the format %s is missing; save the format to create it.', $format->styleName($density), $format->label()));
        }
      }
    }

    $placed = $this->placedSlots();
    foreach ($this->inventory->slots() as $id => $slot) {
      foreach (array_unique([$slot->desktopFormat(), $slot->phoneFormat()]) as $format) {
        if ($this->inventory->format($format) === NULL) {
          $gates[] = $this->line('error', 'slot:' . $id, sprintf('The slot %s names the unknown format "%s".', $slot->label(), $format));
        }
      }
      if ($slot->channel() === 'web' && !isset($placed[$id])) {
        $gates[] = $this->line('warning', 'slot:' . $id, sprintf('The slot %s is not placed: no block, and no template has printed it.', $slot->label()));
      }
    }

    $today = $this->calendar->today();
    $bookings = $this->entityTypeManager->getStorage('ad_booking');
    $ids = $bookings->getQuery()->accessCheck(FALSE)->condition('state', 'approved')->condition('end_day', $today, '>=')->execute();
    foreach ($bookings->loadMultiple($ids) as $booking) {
      if (!$booking instanceof AdBookingInterface || ($creative = $booking->creative()) === NULL) {
        continue;
      }
      $problems = $this->inventory->renditionProblems($creative, $this->inventory->neededFormats($booking));
      if ($problems) {
        $gates[] = $this->line('error', 'booking:' . $booking->id(), sprintf('Booking "%s": %s', $booking->label(), implode(' ', $problems)));
      }
    }

    $order = ['error' => 0, 'warning' => 1, 'ok' => 2];
    usort($gates, static fn(array $a, array $b): int => $order[$a['severity']] <=> $order[$b['severity']]);
    return $gates;
  }

  /**
   * Whether any gate is an error.
   */
  public function hasErrors(): bool {
    foreach ($this->gates() as $gate) {
      if ($gate['severity'] === 'error') {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Slot ids placed by a block or recorded from a template, as keys.
   *
   * @return array<string, bool>
   *   Placed slot ids.
   */
  public function placedSlots(): array {
    $placed = [];
    foreach ($this->entityTypeManager->getStorage('block')->loadMultiple() as $block) {
      $plugin = (string) $block->get('plugin');
      if (str_starts_with($plugin, 'ad_inventory_slot:')) {
        $placed[substr($plugin, 18)] = TRUE;
      }
    }
    foreach ($this->placements->all() as $placement) {
      $placed[$placement['slot']] = TRUE;
    }
    return $placed;
  }

  /**
   * The ads.txt the site serves: adstxt's content plus every module's lines.
   */
  public function adsTxt(): string {
    $lines = [(string) $this->configFactory->get('adstxt.settings')->get('content')];
    $additions = $this->moduleHandler->invokeAll('adstxt', [new CacheableMetadata()]);
    return implode("\n", array_merge($lines, array_map('strval', $additions)));
  }

  /**
   * Whether a file holds a line, ignoring case and spacing.
   */
  public static function adsTxtHas(string $file, string $line): bool {
    $normal = static fn(string $text): string => strtolower(preg_replace('/\s+/', '', $text) ?? '');
    $wanted = $normal($line);
    foreach (preg_split('/\R/', $file) ?: [] as $candidate) {
      $candidate = trim(explode('#', $candidate, 2)[0]);
      if ($candidate !== '' && $normal($candidate) === $wanted) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * One gate.
   */
  private function line(string $severity, string $gate, string $message): array {
    return ['severity' => $severity, 'gate' => $gate, 'message' => $message];
  }

}
