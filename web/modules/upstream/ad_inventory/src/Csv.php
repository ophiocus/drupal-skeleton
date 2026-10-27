<?php

declare(strict_types=1);

namespace Drupal\ad_inventory;

/**
 * CSV for spreadsheets, safe against formula injection.
 */
final class Csv {

  /**
   * A cell as text a spreadsheet will not run.
   *
   * A value starting with "=", "+", "-" or "@" (or a tab or carriage return)
   * is read as a formula by spreadsheet programs, so it is prefixed with a
   * single quote.
   */
  public static function cell(mixed $value): string {
    if (is_bool($value)) {
      $value = $value ? '1' : '0';
    }
    $text = (string) $value;
    if ($text !== '' && !is_int($value) && !is_float($value) && preg_match('/^[=+\-@\t\r]/', $text)) {
      $text = "'" . $text;
    }
    return $text;
  }

  /**
   * A whole CSV document.
   *
   * @param string[] $header
   *   Column names.
   * @param array<int, array> $rows
   *   Rows of values, in header order.
   */
  public static function build(array $header, array $rows): string {
    $handle = fopen('php://temp', 'r+');
    if ($handle === FALSE) {
      return '';
    }
    fputcsv($handle, array_map([self::class, 'cell'], $header), ',', '"', '');
    foreach ($rows as $row) {
      fputcsv($handle, array_map([self::class, 'cell'], array_values($row)), ',', '"', '');
    }
    rewind($handle);
    $csv = (string) stream_get_contents($handle);
    fclose($handle);
    return $csv;
  }

}
