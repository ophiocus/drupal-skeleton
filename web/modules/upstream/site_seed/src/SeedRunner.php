<?php

declare(strict_types=1);

namespace Drupal\site_seed;

use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\site_seed\Handler\SeedHandlerInterface;

/**
 * Converges a site's seed file onto the database, section by section.
 *
 * A site's `property:seed` command (the alias every deploy runs) calls run()
 * with its own seed file. Sections no handler takes are returned untouched in
 * `unhandled`, for the site's own code.
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
   */
  public function __construct(
    private readonly iterable $handlers,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

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
