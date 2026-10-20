<?php

declare(strict_types=1);

namespace Drupal\ga4_report\Report;

/**
 * Sorts page paths into the kinds of page a site configured.
 *
 * Google matches each kind's pattern as RE2 against the whole path (the
 * per-kind totals); this class matches the same pattern as PCRE to label the
 * rows of the top-pages and first-page reports. Plain patterns (literals,
 * classes, groups, alternation, quantifiers) mean the same in both.
 */
final class PageKinds {

  /**
   * The id given to a path no kind matches.
   */
  public const OTHER = 'other';

  /**
   * Compiled patterns keyed by kind id, in the configured order.
   *
   * @var array<string, string>
   */
  private array $patterns = [];

  /**
   * Compiles the configured kinds, skipping any whose pattern is broken.
   *
   * @param array $kinds
   *   The configured kinds: id, label, pattern.
   */
  public function __construct(array $kinds) {
    foreach ($kinds as $kind) {
      $regex = self::compile((string) $kind['pattern']);
      if ($regex !== NULL) {
        $this->patterns[(string) $kind['id']] = $regex;
      }
    }
  }

  /**
   * The first kind whose pattern matches the whole path, or OTHER.
   */
  public function classify(string $path): string {
    foreach ($this->patterns as $id => $regex) {
      if (preg_match($regex, $path) === 1) {
        return $id;
      }
    }
    return self::OTHER;
  }

  /**
   * The PCRE form of a whole-path pattern, or NULL when it does not compile.
   */
  public static function compile(string $pattern): ?string {
    if ($pattern === '') {
      return NULL;
    }
    $regex = '{^(?:' . $pattern . ')$}u';
    // A broken pattern is reported by the settings form; here it matches
    // nothing rather than raising a warning on every page.
    return @preg_match($regex, '') === FALSE ? NULL : $regex;
  }

}
