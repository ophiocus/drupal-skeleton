<?php

declare(strict_types=1);

namespace Drupal\ad_inventory;

/**
 * Reads what a page's beacon sends.
 *
 * Body: {"v": [[slot, creative], ...], "u": [[slot, "provider:<id>"], ...]}.
 * "v" are viewed slots (at least half on screen for one second), "u" network
 * units that came back unfilled. A creative is an ad_creative id, or
 * "provider:<id>" for a network fill. Nothing else is read.
 */
final class BeaconPayload {

  /**
   * Pairs read per list at most; a page has a handful of slots.
   */
  public const MAX_PAIRS = 50;

  /**
   * Parses a body into unique, well-formed pairs.
   *
   * @return array{views: array<int, array{0: string, 1: string}>, unfilled: array<int, array{0: string, 1: string}>}
   *   Pairs; malformed ones are dropped.
   */
  public static function parse(string $body): array {
    $data = json_decode($body, TRUE);
    if (!is_array($data)) {
      return ['views' => [], 'unfilled' => []];
    }
    return [
      'views' => self::pairs($data['v'] ?? []),
      'unfilled' => self::pairs($data['u'] ?? [], TRUE),
    ];
  }

  /**
   * Unique, well-formed pairs of a list.
   *
   * @param mixed $list
   *   The list as decoded.
   * @param bool $providers_only
   *   Whether only network fills are allowed.
   */
  private static function pairs(mixed $list, bool $providers_only = FALSE): array {
    if (!is_array($list)) {
      return [];
    }
    $pairs = [];
    foreach (array_slice($list, 0, self::MAX_PAIRS) as $pair) {
      if (!is_array($pair) || count($pair) !== 2) {
        continue;
      }
      [$slot, $creative] = array_values($pair);
      if (!is_string($slot) || !preg_match('/^[a-z0-9_]{1,64}$/', $slot)) {
        continue;
      }
      $creative = is_int($creative) ? (string) $creative : $creative;
      if (!is_string($creative) || !self::isCreative($creative) || ($providers_only && !str_starts_with($creative, 'provider:'))) {
        continue;
      }
      $pairs[$slot . '|' . $creative] = [$slot, $creative];
    }
    return array_values($pairs);
  }

  /**
   * Whether a value names a creative or a provider fill.
   */
  public static function isCreative(string $value): bool {
    return (bool) preg_match('/^(\d{1,10}|provider:[a-z0-9_]{1,64})$/', $value);
  }

}
