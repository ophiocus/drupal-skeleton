<?php

declare(strict_types=1);

namespace Drupal\edge_purge;

use Drupal\Core\Site\Settings;

/**
 * The files whose change can change a rendered page, and their hashes.
 *
 * A release purges the edge only when one of them changed: templates, theme
 * and module code that builds pages or sets the tags they carry, theme CSS
 * and JS (a page cached with the old aggregate URL gets the new file
 * uncached, so old markup would meet new styles), and composer.lock (core and
 * contrib). Never counted (Carlos, 2026-10-19: "narrow it"): Drush commands,
 * queue workers, stack middleware, install and seed code, routing and
 * permission YAML, tests, config, docs, and this class. A release that
 * changes only those purges nothing; config and content have their own tags.
 *
 * Paths are relative to the Drupal root. settings.php can replace the roots
 * ($settings['edge_purge_render_paths']) and ADD names never counted
 * ($settings['edge_purge_render_skip']: a folder or file name, matched
 * wherever it appears).
 */
final class RenderFingerprint {

  /**
   * State key of the last released file map.
   */
  public const STATE = 'edge_purge.render_files';

  /**
   * Files and folders whose change can change a page.
   */
  public const PATHS = ['themes/custom', 'modules/custom', 'modules/upstream', '../composer.lock'];

  /**
   * Folder and file names never counted, wherever they appear.
   */
  public const SKIP = [
    'tests', 'Drush', 'Commands', 'QueueWorker', 'StackMiddleware',
    'node_modules', 'config', '.git', 'RenderFingerprint.php',
  ];

  /**
   * File name endings never counted.
   */
  public const SKIP_ENDINGS = [
    '.md', '.txt', '.install', '.info.yml', '.routing.yml',
    '.permissions.yml', '.links.menu.yml', '.links.task.yml',
    '.links.action.yml', '.links.contextual.yml',
  ];

  /**
   * Whether a path relative to the Drupal root counts.
   */
  public static function counts(string $relative): bool {
    $skip = array_merge(self::SKIP, (array) Settings::get('edge_purge_render_skip', []));
    foreach (explode('/', $relative) as $segment) {
      if (in_array($segment, $skip, TRUE)) {
        return FALSE;
      }
    }
    $name = strtolower(basename($relative));
    foreach (self::SKIP_ENDINGS as $ending) {
      if (str_ends_with($name, $ending)) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * Each counted file under a Drupal root, with its content hash.
   *
   * @return array<string, string>
   *   Path relative to the root => xxh3 hash, sorted by path.
   */
  public static function files(string $root): array {
    $paths = (array) Settings::get('edge_purge_render_paths', self::PATHS);
    $skip = array_merge(self::SKIP, (array) Settings::get('edge_purge_render_skip', []));
    $files = [];
    foreach ($paths as $path) {
      $full = $root . '/' . $path;
      if (is_file($full)) {
        $files[$path] = hash_file('xxh3', $full);
        continue;
      }
      if (!is_dir($full)) {
        continue;
      }
      // Never descend into a skipped folder (node_modules can be huge).
      $iterator = new \RecursiveIteratorIterator(
        new \RecursiveCallbackFilterIterator(
          new \RecursiveDirectoryIterator($full, \FilesystemIterator::SKIP_DOTS),
          static fn (\SplFileInfo $file): bool => !$file->isDir() || !in_array($file->getFilename(), $skip, TRUE),
        ),
      );
      foreach ($iterator as $file) {
        if (!$file->isFile()) {
          continue;
        }
        $relative = str_replace('\\', '/', $path . substr($file->getPathname(), strlen($full)));
        if (self::counts($relative)) {
          $files[$relative] = hash_file('xxh3', $file->getPathname());
        }
      }
    }
    ksort($files);
    return $files;
  }

  /**
   * The files added, removed or changed between two maps.
   *
   * A file the last release counted and the rules now leave out is not a
   * change: narrowing the rules must not purge every page by itself. Nor is a
   * file that moved, unchanged, from one root to another (a module the
   * upstream takes over moves from modules/custom to modules/upstream).
   *
   * @param array<string, string> $before
   *   The map of the last release.
   * @param array<string, string> $after
   *   The map now.
   *
   * @return string[]
   *   Paths, sorted.
   */
  public static function changed(array $before, array $after): array {
    $before = array_filter($before, static fn (string $path): bool => self::counts($path), ARRAY_FILTER_USE_KEY);
    $added = array_diff_key($after, $before);
    $removed = array_diff_key($before, $after);
    $edited = array_diff_key(array_diff_assoc($after, $before), $added);
    // The same file, same content, under another root: moved, not changed.
    $moved = [];
    foreach ($added as $path => $hash) {
      $key = self::underRoot($path);
      foreach ($removed as $old => $oldHash) {
        if ($oldHash === $hash && $key !== NULL && self::underRoot($old) === $key) {
          $moved[$path] = $moved[$old] = TRUE;
          break;
        }
      }
    }
    $paths = array_keys(array_diff_key($edited + $added + $removed, $moved));
    sort($paths);
    return $paths;
  }

  /**
   * A path with its root (one of PATHS) taken off, or NULL outside them.
   */
  private static function underRoot(string $path): ?string {
    foreach (self::PATHS as $root) {
      if (str_starts_with($path, $root . '/')) {
        return substr($path, strlen($root) + 1);
      }
    }
    return NULL;
  }

}
