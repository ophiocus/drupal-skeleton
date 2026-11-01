<?php

declare(strict_types=1);

namespace Drupal\Tests\edge_purge\Kernel;

use Drupal\entity_test\Entity\EntityTest;
use Drupal\entity_test\Entity\EntityTestMulRevPub;
use Drupal\KernelTests\KernelTestBase;
use Drupal\path_alias\Entity\PathAlias;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * A cached 4xx page is purged only when its address may answer differently.
 */
#[Group('edge_purge')]
#[RunTestsInSeparateProcesses]
final class PathPurgerTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'path_alias', 'language', 'entity_test', 'edge_purge'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('edge_purge', ['edge_purge_queue']);
    $this->installEntitySchema('user');
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema('entity_test');
    $this->installEntitySchema('entity_test_mulrevpub');
    $this->installConfig(['language']);
  }

  /**
   * The path tags queued now, without their prefix, and empties the queue.
   *
   * @return string[]
   *   Paths, sorted.
   */
  private function takePaths(): array {
    $database = $this->container->get('database');
    $tags = $database->query('SELECT tag FROM {edge_purge_queue} WHERE tag LIKE :p', [':p' => 'edge_purge:path:%'])->fetchCol();
    $database->truncate('edge_purge_queue')->execute();
    $paths = array_map(static fn (string $tag): string => substr($tag, strlen('edge_purge:path:')), $tags);
    sort($paths);
    return $paths;
  }

  /**
   * A new entity purges the address of its page.
   */
  public function testCreatedEntityPurgesItsPath(): void {
    $entity = EntityTest::create(['name' => 'Concierto']);
    $entity->save();
    $this->assertSame(['/entity_test/' . $entity->id()], $this->takePaths());

    // Saving it again changes nothing an address answers.
    $entity->setName('Concierto 2')->save();
    $this->assertSame([], $this->takePaths());
  }

  /**
   * Publishing or unpublishing purges; any other edit does not.
   */
  public function testPublishingPurgesItsPath(): void {
    $entity = EntityTestMulRevPub::create(['name' => 'Borrador', 'status' => 0]);
    $entity->save();
    $path = '/entity_test_mulrevpub/manage/' . $entity->id();
    $this->assertSame([$path], $this->takePaths());

    $entity->setName('Otro')->save();
    $this->assertSame([], $this->takePaths());

    $entity->setPublished()->save();
    $this->assertSame([$path], $this->takePaths());
  }

  /**
   * An alias purges its address, and on a change the old one too.
   */
  public function testAliasPurgesOldAndNewAddress(): void {
    $alias = PathAlias::create(['path' => '/entity_test/1', 'alias' => '/Eventos/La-Maestra', 'langcode' => 'und']);
    $alias->save();
    $this->assertSame(['/eventos/la-maestra'], $this->takePaths());

    $alias->setAlias('/eventos/la-maestra-2')->save();
    $this->assertSame(['/eventos/la-maestra', '/eventos/la-maestra-2'], $this->takePaths());

    $alias->delete();
    $this->assertSame(['/eventos/la-maestra-2'], $this->takePaths());
  }

  /**
   * With language prefixes, an address is purged as each prefix serves it.
   */
  public function testLanguagePrefixes(): void {
    $this->config('language.negotiation')->set('url', [
      'source' => 'path_prefix',
      'prefixes' => ['es' => '', 'en' => 'en'],
      'domains' => ['es' => '', 'en' => ''],
    ])->save();

    PathAlias::create(['path' => '/entity_test/1', 'alias' => '/about', 'langcode' => 'en'])->save();
    $this->assertSame(['/en/about'], $this->takePaths());

    PathAlias::create(['path' => '/entity_test/2', 'alias' => '/lugares', 'langcode' => 'und'])->save();
    $this->assertSame(['/en/lugares', '/lugares'], $this->takePaths());
  }

}
