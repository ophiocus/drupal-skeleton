<?php

declare(strict_types=1);

namespace Drupal\Tests\site_seed\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\site_seed\Handler\MenuHandler;
use Drupal\site_seed\SeedContext;
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
    $this->assertSame(3, $first['tally']['menu_links']);
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
    $this->assertSame(3, $tally['menu_links'], 'A child finds its unsaved parent.');
    $this->assertSame(0, $this->entityCount('taxonomy_term'));
    $this->assertSame(0, $this->entityCount('menu_link_content'));
    $this->assertSame(0, $this->entityCount('path_alias'));
  }

  /**
   * A link with a parent nests under it; a parent moved by hand returns.
   */
  public function testMenuParent(): void {
    $this->seed();
    $storage = $this->container->get('entity_type.manager')->getStorage('menu_link_content');
    $tienda = current($storage->loadByProperties(['link__uri' => 'internal:/tienda']));
    $pan = current($storage->loadByProperties(['link__uri' => 'internal:/tienda/pan']));
    $this->assertTrue((bool) $tienda->get('expanded')->value);
    $this->assertSame('menu_link_content:' . $tienda->uuid(), $pan->get('parent')->value);

    $pan->set('parent', '')->save();
    $this->assertSame(1, $this->seed()['tally']['updated']);
    $storage->resetCache();
    $this->assertSame('menu_link_content:' . $tienda->uuid(), $storage->load($pan->id())->get('parent')->value);
  }

  /**
   * A parent no earlier row seeds fails loudly.
   */
  public function testMenuUnknownParent(): void {
    $handler = new MenuHandler($this->container->get('entity_type.manager'));
    $this->expectException(SeedException::class);
    $handler->converge([
      ['menu' => 'main', 'uri' => 'internal:/a', 'title' => 'A', 'parent' => 'internal:/missing'],
    ], new SeedContext('/tmp'));
  }

  /**
   * A missing seed file fails loudly.
   */
  public function testMissingFile(): void {
    $this->expectException(SeedException::class);
    $this->container->get('site_seed.runner')->run('/nonexistent/seed.yml');
  }

  /**
   * With no installed module carrying data/seeds/, the site seed fails loudly.
   */
  public function testRunSiteWithoutSeedFiles(): void {
    $this->assertSame([], $this->container->get('site_seed.runner')->seedFiles());
    $this->expectException(SeedException::class);
    $this->container->get('site_seed.runner')->runSite();
  }

  /**
   * The site seed runs data/seeds/*.yml of every installed site module.
   */
  public function testRunSiteFindsModuleSeeds(): void {
    $this->enableModules(['site_seed_test']);
    $runner = $this->container->get('site_seed.runner');

    $files = $runner->seedFiles();
    $this->assertCount(1, $files);
    $this->assertStringEndsWith('/site_seed_test/data/seeds/site.yml', $files[0]);

    $check = $runner->runSite(TRUE);
    $this->assertSame(1, $check['tally']['terms']);
    $this->assertSame(0, $this->entityCount('taxonomy_term'));

    $first = $runner->runSite();
    $this->assertSame(1, $first['tally']['terms']);
    $this->assertSame(1, $first['tally']['menu_links']);
    $this->assertSame([], $first['unhandled']);
    $this->assertSame($files, $first['files']);

    $second = $runner->runSite()['tally'];
    $this->assertSame(0, $second['terms']);
    $this->assertSame(0, $second['menu_links']);
  }

}
