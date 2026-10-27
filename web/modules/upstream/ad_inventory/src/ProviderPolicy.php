<?php

declare(strict_types=1);

namespace Drupal\ad_inventory;

use Drupal\ad_inventory\Plugin\AdProvider\AdProviderPluginInterface;

/**
 * Rules between providers, and between providers and the site's label.
 *
 * Pure functions, so they are unit tested without Drupal.
 */
final class ProviderPolicy {

  /**
   * Formats that take over the page and that other networks may forbid.
   *
   * Pop-unders, push prompts, vignettes, interstitials, exit and slide-in
   * widgets. Google bans its ads on sites that carry pop-unders.
   */
  public const EXCLUSIVE_KINDS = ['pop_under', 'push', 'vignette', 'interstitial', 'exit', 'slide_in'];

  /**
   * Every format kind a provider may declare.
   */
  public const KINDS = ['banner', 'native', 'pop_under', 'push', 'vignette', 'interstitial', 'exit', 'slide_in'];

  /**
   * What the policy needs to know about one provider.
   *
   * @return array{id: string, label: string, kinds: string[], forbids: string[]}
   *   The provider's description.
   */
  public static function describe(string $id, string $label, AdProviderPluginInterface $plugin): array {
    return [
      'id' => $id,
      'label' => $label,
      'kinds' => $plugin->kinds(),
      'forbids' => $plugin->forbids(),
    ];
  }

  /**
   * The conflicts among enabled providers.
   *
   * @param array<int, array{id: string, label: string, kinds: string[], forbids: string[]}> $providers
   *   Every enabled provider, as describe() returns it.
   * @param string|null $involving
   *   Only conflicts this provider takes part in, or NULL for all.
   *
   * @return string[]
   *   One sentence per conflict; empty when none.
   */
  public static function conflicts(array $providers, ?string $involving = NULL): array {
    $messages = [];
    foreach ($providers as $a) {
      foreach ($providers as $b) {
        if ($a['id'] === $b['id']) {
          continue;
        }
        if ($involving !== NULL && $a['id'] !== $involving && $b['id'] !== $involving) {
          continue;
        }
        $clash = array_values(array_intersect($a['forbids'], $b['kinds']));
        if ($clash) {
          $messages[] = sprintf('%s forbids %s formats on the same site, and %s uses them.', $a['label'], implode(', ', $clash), $b['label']);
        }
      }
    }
    return $messages;
  }

  /**
   * Whether the site's label is one a provider accepts.
   *
   * @param string $label
   *   The site-wide slot label.
   * @param string[] $allowed
   *   The provider's accepted labels; empty accepts any.
   */
  public static function labelAllowed(string $label, array $allowed): bool {
    if (!$allowed) {
      return TRUE;
    }
    $label = mb_strtolower(trim($label));
    foreach ($allowed as $candidate) {
      if (mb_strtolower(trim($candidate)) === $label) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Whether an image matches a format's ratio within a tolerance.
   *
   * @param int $width
   *   The image width.
   * @param int $height
   *   The image height.
   * @param int $format_width
   *   The format width.
   * @param int $format_height
   *   The format height.
   * @param float $tolerance
   *   The allowed relative difference; 0.01 is 1%.
   */
  public static function ratioMatches(int $width, int $height, int $format_width, int $format_height, float $tolerance = 0.01): bool {
    if ($width < 1 || $height < 1 || $format_width < 1 || $format_height < 1) {
      return FALSE;
    }
    $ratio = ($width / $height) / ($format_width / $format_height);
    return abs($ratio - 1) <= $tolerance;
  }

}
