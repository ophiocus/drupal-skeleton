<?php

declare(strict_types=1);

namespace Drupal\Tests\edge_purge\Kernel;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\edge_purge\SoftRebuild;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * A soft rebuild keeps the page bins and empties the rest; a hard one, all.
 */
#[Group('edge_purge')]
#[RunTestsInSeparateProcesses]
final class SoftRebuildTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'page_cache',
    'dynamic_page_cache',
    'edge_purge',
  ];

  /**
   * {@inheritdoc}
   *
   * Kernel tests keep every bin in memory, which a container rebuild drops;
   * the database backend, as on the site, is what can show what survives.
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    foreach (array_keys($container->findTaggedServiceIds('cache.bin')) as $id) {
      $definition = $container->getDefinition($id);
      $tags = $definition->getTags();
      $tags['cache.bin'][0]['default_backend'] = 'cache.backend.database';
      $definition->setTags($tags);
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('edge_purge', ['edge_purge_queue']);
    foreach (['page', 'dynamic_page_cache', 'render', 'data', 'default'] as $bin) {
      \Drupal::cache($bin)->set('probe', $bin);
    }
  }

  /**
   * Which of the probed bins still hold their item.
   *
   * @return string[]
   *   Bin names.
   */
  private function held(): array {
    $held = [];
    foreach (['page', 'dynamic_page_cache', 'render', 'data', 'default'] as $bin) {
      if (\Drupal::cache($bin)->get('probe')) {
        $held[] = $bin;
      }
    }
    return $held;
  }

  /**
   * Render code unchanged: finished pages survive, everything else goes.
   */
  public function testKeepsPageBins(): void {
    SoftRebuild::flush(TRUE);
    $this->assertSame(['page', 'dynamic_page_cache', 'render'], $this->held());
    // The rebuilt container serves the real bins again, not the shields.
    $this->assertStringNotContainsString('FlushShield', get_class(\Drupal::cache('render')));
    // Nothing reached the purge queue: the edge keeps its copies too.
    $queued = \Drupal::database()->query('SELECT COUNT(*) FROM {edge_purge_queue} WHERE tag <> :t', [':t' => '4xx-response'])->fetchField();
    $this->assertSame(0, (int) $queued);
  }

  /**
   * Render code changed: every bin is emptied, as by "drush cr".
   */
  public function testEmptiesAllWhenRenderCodeChanged(): void {
    SoftRebuild::flush(FALSE);
    $this->assertSame([], $this->held());
  }

  /**
   * No release recorded means changed; an equal map means unchanged.
   */
  public function testChanged(): void {
    $this->assertNull(SoftRebuild::changed(NULL, ['themes/custom/a.twig' => '1']));
    $this->assertSame([], SoftRebuild::changed(['themes/custom/a.twig' => '1'], ['themes/custom/a.twig' => '1']));
    $this->assertSame(['themes/custom/a.twig'], SoftRebuild::changed(['themes/custom/a.twig' => '1'], ['themes/custom/a.twig' => '2']));
  }

}
