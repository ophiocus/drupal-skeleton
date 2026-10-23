<?php

declare(strict_types=1);

namespace Drupal\Tests\site_seed\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\site_seed\SeedException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The engine and its core handlers, on any site: terms, menu links, aliases.
 */
#[Group('site_seed')]
#[RunTestsInSeparateProcesses]
final class SeedRunnerCoreTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'link',
    'taxonomy',
    'menu_link_content',
    'path_alias',
    'site_seed',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('taxonomy_term');
    $this->installEntitySchema('menu_link_content');
    $this->installEntitySchema('path_alias');
    $this->container->get('entity_type.manager')->getStorage('taxonomy_vocabulary')
      ->create(['vid' => 'tags', 'name' => 'Tags'])->save();
  }

  /**
   * Runs the core fixture.
   *
   * @return array{tally: array<string, int>, unhandled: string[]}
   *   The runner's result.
   */
  private function seed(bool $check = FALSE): array {
    return $this->container->get('site_seed.runner')
      ->run(dirname(__DIR__, 2) . '/fixtures/seeds/core.yml', $check);
  }

  /**
   * How many entities of a type exist.
   */
  private function entityCount(string $type): int {
    return (int) $this->container->get('entity_type.manager')->getStorage($type)
      ->getQuery()->accessCheck(FALSE)->count()->execute();
  }

  /**
   * First run creates; second run changes nothing.
   */
  public function testIdempotent(): void {
    $first = $this->seed();
    $this->assertSame(2, $first['tally']['terms']);
    $this->assertSame(2, $first['tally']['menu_links']);
    $this->assertSame(1, $first['tally']['aliases']);
    $this->assertSame(['site_specific'], $first['unhandled']);

    $second = $this->seed()['tally'];
    $this->assertSame(0, $second['terms']);
    $this->assertSame(0, $second['menu_links']);
    $this->assertSame(0, $second['aliases'] ?? 0);
    $this->assertSame(0, $second['updated']);
    $this->assertSame(2, $this->entityCount('taxonomy_term'));
  }

  /**
   * A term listed under a former name is renamed in place, not duplicated.
   */
  public function testFormerlyRenamesInPlace(): void {
    $storage = $this->container->get('entity_type.manager')->getStorage('taxonomy_term');
    $old = $storage->create(['vid' => 'tags', 'name' => 'Bebidas', 'weight' => 1]);
    $old->save();

    $tally = $this->seed()['tally'];
    $this->assertSame(1, $tally['terms'], 'Only Granos is new.');
    $storage->resetCache();
    $this->assertSame('Café', $storage->load($old->id())->label());
    $this->assertSame(2, $this->entityCount('taxonomy_term'));
  }

  /**
   * A check run reports the changes and writes none of them.
   */
  public function testCheckWritesNothing(): void {
    $tally = $this->seed(TRUE)['tally'];
    $this->assertSame(2, $tally['terms']);
    $this->assertSame(0, $this->entityCount('taxonomy_term'));
    $this->assertSame(0, $this->entityCount('menu_link_content'));
    $this->assertSame(0, $this->entityCount('path_alias'));
  }

  /**
   * A missing seed file fails loudly.
   */
  public function testMissingFile(): void {
    $this->expectException(SeedException::class);
    $this->container->get('site_seed.runner')->run('/nonexistent/seed.yml');
  }

}
