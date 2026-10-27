<?php

declare(strict_types=1);

namespace Drupal\ad_inventory;

/**
 * The CSS that reserves every format's box and sizes network units.
 *
 * One style block for the whole site, generated from the formats and the
 * breakpoint, the same on every page and for every reader. A slot box is
 * `width: 100%; max-width: <format width>; aspect-ratio: <format ratio>`
 * (rules in css/ad-inventory.css), so it shrinks in a narrow column and its
 * space is known before anything loads. The phone format applies under the
 * breakpoint. Network units take a fixed size per viewport width.
 */
final class FormatStyles {

  /**
   * The style block.
   *
   * @param array<string, array{width: int, height: int, network: array}> $formats
   *   Formats keyed by id; network rows as AdFormatInterface::networkSizes().
   * @param int $breakpoint
   *   The viewport width, in pixels, from which the desktop format applies;
   *   0 uses the desktop format everywhere.
   */
  public static function css(array $formats, int $breakpoint): string {
    $breakpoint = max(0, $breakpoint);
    $below = $breakpoint > 0 ? sprintf('(max-width: %spx)', self::number($breakpoint - 0.02)) : '';
    $rules = [];
    ksort($formats);
    foreach ($formats as $id => $format) {
      if (!preg_match('/^[a-z0-9_]+$/', (string) $id) || $format['width'] < 1 || $format['height'] < 1) {
        continue;
      }
      $box = sprintf('{max-width:%dpx;aspect-ratio:%d/%d}', $format['width'], $format['width'], $format['height']);
      $desktop = sprintf('.ad-slot[data-ad-desktop="%s"]', $id);
      $phone = sprintf('.ad-slot[data-ad-phone="%s"]', $id);
      $rules[] = $desktop . ' .ad-slot__box' . $box;
      if ($below !== '') {
        $rules[] = '@media ' . $below . '{' . $phone . ' .ad-slot__box' . $box . '}';
      }
      foreach ($format['network'] as $row) {
        if ($row['width'] < 1 || $row['height'] < 1) {
          continue;
        }
        $unit = sprintf(' .ad-slot__unit{width:%dpx;height:%dpx}', $row['width'], $row['height']);
        $from = max($row['min_width'], $breakpoint);
        $rules[] = $from > 0
          ? sprintf('@media (min-width: %dpx){%s%s}', $from, $desktop, $unit)
          : $desktop . $unit;
        if ($below !== '' && $row['min_width'] < $breakpoint) {
          $rules[] = $row['min_width'] > 0
            ? sprintf('@media (min-width: %dpx) and %s{%s%s}', $row['min_width'], $below, $phone, $unit)
            : sprintf('@media %s{%s%s}', $below, $phone, $unit);
        }
      }
    }
    return implode("\n", $rules);
  }

  /**
   * A number for CSS, without trailing zeros.
   */
  private static function number(float $value): string {
    return rtrim(rtrim(sprintf('%.2F', $value), '0'), '.');
  }

}
