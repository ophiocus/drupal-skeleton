<?php

declare(strict_types=1);

namespace Drupal\site_seed;

use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\site_seed\Handler\SeedHandlerInterface;

/**
 * Converges a site's seed files onto the database, section by section.
 *
 * `drush property:seed` (the alias every deploy runs) calls runSite(), which
 * runs every seed file the site's installed modules carry in data/seeds/.
 * Sections no handler takes are returned in `unhandled`.
 */
final class SeedRunner {

  /**
   * Builds the runner from every tagged handler.
   *
   * @param iterable<\Drupal\site_seed\Handler\SeedHandlerInterface> $handlers
   *   Every service tagged site_seed.handler.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   Entity storages, whose in-memory copies a check run resets.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   Config, whose in-memory copies a check run resets.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The installed modules, whose data/seeds/ hold the site's seed files.
   * @param string $appRoot
   *   The Drupal root, which module paths are relative to.
   */
  public function __construct(
    private readonly iterable $handlers,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly string $appRoot,
  ) {}

  /**
   * The site's seed files: data/seeds/*.yml of every installed site module.
   *
   * Core and contrib are skipped. Upstream modules carry no data/seeds/ (seed
   * data is the site's own), so in practice these are the custom modules'.
   *
   * @return string[]
   *   Absolute paths, ordered by path.
   */
  public function seedFiles(): array {
    $files = [];
    foreach ($this->moduleHandler->getModuleList() as $module) {
      $path = $module->getPath();
      if (str_starts_with($path, 'core/') || str_contains($path, '/contrib/')) {
        continue;
      }
      foreach (glob($this->appRoot . '/' . $path . '/data/seeds/*.yml') ?: [] as $file) {
        $files[] = $file;
      }
    }
    sort($files);
    return $files;
  }

  /**
   * Converges every seed file of the site (seedFiles()), in order.
   *
   * @param bool $check
   *   Report what would change, and write nothing (see run()).
   *
   * @return array{tally: array<string, int>, unhandled: string[], files: string[]}
   *   The tallies summed over every file, the sections no handler took, and
   *   the files run.
   *
   * @throws \Drupal\site_seed\SeedException
   *   When there is no seed file, or one cannot be converged.
   */
  public function runSite(bool $check = FALSE): array {
    $files = $this->seedFiles();
    if (!$files) {
      throw new SeedException('no seed file: no installed site module has data/seeds/*.yml');
    }
    $tally = [];
    $unhandled = [];
    foreach ($files as $file) {
      $result = $this->run($file, $check);
      foreach ($result['tally'] as $kind => $n) {
        $tally[$kind] = ($tally[$kind] ?? 0) + $n;
      }
      array_push($unhandled, ...$result['unhandled']);
    }
    return [
      'tally' => $tally,
      'unhandled' => array_values(array_unique($unhandled)),
      'files' => $files,
    ];
  }

  /**
   * Converges one seed file.
   *
   * @param string $file
   *   The seed file (YAML).
   * @param bool $check
   *   Report what would change, and write nothing. Handlers compare and count
   *   as in a real run, and every write goes through the context, which skips
   *   it. Not a rolled-back transaction: on MySQL a table created on first use
   *   during the run commits it implicitly, and the "rollback" would not.
   *
   * @return array{tally: array<string, int>, unhandled: string[]}
   *   What changed (or would), by kind, and the sections no handler took.
   *
   * @throws \Drupal\site_seed\SeedException
   */
  public function run(string $file, bool $check = FALSE): array {
    if (!is_file($file)) {
      throw new SeedException("seed file missing at $file");
    }
    $doc = Yaml::decode((string) file_get_contents($file)) ?: [];
    $context = new SeedContext(dirname($file), (array) ($doc['site_seed'] ?? []), $check);
    unset($doc['site_seed']);

    $handlers = [];
    foreach ($this->handlers as $handler) {
      if ($handler instanceof SeedHandlerInterface) {
        $handlers[] = $handler;
      }
    }
    usort($handlers, static fn (SeedHandlerInterface $a, SeedHandlerInterface $b): int => $a->weight() <=> $b->weight());

    $taken = [];
    try {
      foreach ($handlers as $handler) {
        $section = $handler->section();
        if (!array_key_exists($section, $doc) || !$handler->applies()) {
          continue;
        }
        $handler->converge((array) $doc[$section], $context);
        $taken[] = $section;
      }
    }
    finally {
      if ($check) {
        $this->forget();
      }
    }

    return [
      'tally' => $context->tally,
      'unhandled' => array_values(array_diff(array_keys($doc), $taken)),
    ];
  }

  /**
   * Drops the entities and config a check changed in memory and never saved.
   */
  private function forget(): void {
    foreach (array_keys($this->entityTypeManager->getDefinitions()) as $type) {
      $this->entityTypeManager->getStorage($type)->resetCache();
    }
    $this->configFactory->reset();
  }

}
