<?php

declare(strict_types=1);

namespace Drupal\ad_inventory_report;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Statement\FetchAs;

/**
 * Sums over the rollup table; nothing here reads the raw counts.
 */
final class ReportData {

  public function __construct(
    private readonly Connection $database,
  ) {}

  /**
   * Views, clicks and unfilled units of a period.
   *
   * @return array{views: int, clicks: int, unfilled: int}
   *   Totals.
   */
  public function totals(string $from, string $to): array {
    $row = $this->sum($from, $to, [])[0] ?? [];
    return [
      'views' => (int) ($row['views'] ?? 0),
      'clicks' => (int) ($row['clicks'] ?? 0),
      'unfilled' => (int) ($row['unfilled'] ?? 0),
    ];
  }

  /**
   * Sums grouped by columns.
   *
   * @param string $from
   *   First day.
   * @param string $to
   *   Last day.
   * @param string[] $by
   *   Columns of the rollup table to group by.
   * @param array $conditions
   *   Column => value (or list of values) filters.
   *
   * @return array<int, array>
   *   Rows: the grouped columns, views, clicks, unfilled; views first.
   */
  public function sum(string $from, string $to, array $by, array $conditions = []): array {
    $query = $this->database->select(Rollup::TABLE, 'd')
      ->condition('day', [$from, $to], 'BETWEEN');
    foreach ($conditions as $column => $value) {
      $query->condition($column, $value, is_array($value) ? 'IN' : '=');
    }
    foreach ($by as $column) {
      $query->addField('d', $column);
      $query->groupBy('d.' . $column);
    }
    $query->addExpression('SUM([views])', 'views');
    $query->addExpression('SUM([clicks])', 'clicks');
    $query->addExpression('SUM([unfilled])', 'unfilled');
    if ($by) {
      $query->orderBy('views', 'DESC');
      foreach ($by as $column) {
        $query->orderBy('d.' . $column);
      }
    }
    $rows = [];
    foreach ($query->execute()->fetchAll(FetchAs::Associative) as $row) {
      if (!$by && $row['views'] === NULL) {
        continue;
      }
      foreach (['views', 'clicks', 'unfilled'] as $metric) {
        $row[$metric] = (int) $row[$metric];
      }
      $rows[] = $row;
    }
    return $rows;
  }

  /**
   * One metric per day for every day of a period, keyed by a column.
   *
   * @param string[] $days
   *   The period's days.
   * @param string $column
   *   The column to key by, or '' for the whole site.
   * @param string $metric
   *   The metric: views, clicks or unfilled.
   * @param array $conditions
   *   Filters, as for sum().
   *
   * @return array<string, int[]>
   *   Values per key, one per day in order (zeros filled in).
   */
  public function series(array $days, string $column, string $metric, array $conditions = []): array {
    if (!$days) {
      return [];
    }
    $by = $column === '' ? ['day'] : ['day', $column];
    $index = array_flip($days);
    $series = [];
    foreach ($this->sum(reset($days), end($days), $by, $conditions) as $row) {
      $key = $column === '' ? '' : (string) $row[$column];
      $series[$key] ??= array_fill(0, count($days), 0);
      if (isset($index[$row['day']])) {
        $series[$key][$index[$row['day']]] = $row[$metric];
      }
    }
    if ($column === '' && !$series) {
      $series[''] = array_fill(0, count($days), 0);
    }
    return $series;
  }

  /**
   * Whether any rollup row exists at all.
   */
  public function hasData(): bool {
    return (bool) $this->database->select(Rollup::TABLE, 'd')->countQuery()->execute()->fetchField();
  }

}
