<?php

declare(strict_types=1);

namespace Drupal\ad_inventory;

/**
 * A plain PNG of one colour, written without an image library.
 *
 * The email image answers with a transparent one of the slot's size when
 * nothing fills the slot, so a mail's layout holds.
 */
final class Png {

  /**
   * A PNG of a size, every pixel one RGBA colour.
   *
   * @param int $width
   *   Pixels.
   * @param int $height
   *   Pixels.
   * @param int[] $rgba
   *   Red, green, blue (0–255) and alpha (0 transparent, 255 opaque).
   */
  public static function solid(int $width, int $height, array $rgba = [0, 0, 0, 0]): string {
    $width = max(1, $width);
    $height = max(1, $height);
    [$r, $g, $b, $a] = array_map(static fn($v): int => max(0, min(255, (int) $v)), array_pad($rgba, 4, 255));
    $row = "\0" . str_repeat(pack('C4', $r, $g, $b, $a), $width);
    $data = (string) gzcompress(str_repeat($row, $height), 9);
    return "\x89PNG\r\n\x1a\n"
      . self::chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 6, 0, 0, 0))
      . self::chunk('IDAT', $data)
      . self::chunk('IEND', '');
  }

  /**
   * One PNG chunk.
   */
  private static function chunk(string $type, string $data): string {
    return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
  }

}
