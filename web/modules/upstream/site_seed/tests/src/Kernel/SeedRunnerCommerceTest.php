<?php

declare(strict_types=1);

namespace Drupal\Tests\site_seed\Kernel;

use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The shop catalogue: stores, products, variations.
 *
 * Runs wherever Commerce is installed (every shop's own CI runs it, since the
 * test gate covers web/modules/upstream); skipped where it is not, such as the
 * upstream itself.
 */
#[Group('site_seed')]
#[RunTestsInSeparateProcesses]
final class SeedRunnerCommerceTest extends KernelTestBase {

  /**
   * The uuid config/sync names for the default store.
   */
  private const CONFIGURED_STORE = '0b9d5c0e-1d1a-4c3c-9d33-7d1a4f8c2a10';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'options',
    'datetime',
    'address',
    'entity',
    'inline_entity_form',
    'views',
    'path',
    'path_alias',
    'link',
    'taxonomy',
    'menu_link_content',
    'commerce',
    'commerce_price',
    'commerce_store',
    'commerce_product',
    'site_seed',
    'site_seed_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    // web/modules/upstream/site_seed/tests/src/Kernel -> web/modules.
    if (!is_dir(dirname(__DIR__, 5) . '/contrib/commerce')) {
      $this->markTestSkipped('Commerce is not installed here.');
    }
    parent::setUp();
    foreach (['user', 'path_alias', 'commerce_currency', 'commerce_store', 'commerce_product',
      'commerce_product_variation', 'commerce_product_attribute', 'commerce_product_attribute_value',
      'taxonomy_term', 'menu_link_content',
    ] as $type) {
      $this->installEntitySchema($type);
    }
    $this->installConfig(['commerce_store', 'commerce_product']);
    $this->container->get('commerce_price.currency_importer')->import('COP');
    $this->container->get('entity_type.manager')->getStorage('taxonomy_vocabulary')
      ->create(['vid' => 'categoria', 'name' => 'Categoría'])->save();
    $this->config('commerce_store.settings')->set('default_store', self::CONFIGURED_STORE)->save();
  }

  /**
   * Runs the catalogue fixture.
   *
   * @return array<string, int>
   *   The tally.
   */
  private function seed(bool $check = FALSE): array {
    return $this->container->get('site_seed.runner')
      ->run(dirname(__DIR__, 2) . '/fixtures/seeds/catalogue.yml', $check)['tally'];
  }

  /**
   * Loads entities of a type, fresh from the database.
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface[]
   *   Keyed by id.
   */
  private function all(string $type): array {
    $storage = $this->container->get('entity_type.manager')->getStorage($type);
    $storage->resetCache();
    return $storage->loadMultiple();
  }

  /**
   * A fresh build: everything created, titled, priced, and in config's store.
   */
  public function testFreshBuild(): void {
    $tally = $this->seed();
    $this->assertSame(1, $tally['stores']);
    $this->assertSame(2, $tally['terms']);
    $this->assertSame(2, $tally['products']);
    $this->assertSame(3, $tally['variations']);
    $this->assertSame(1, $tally['menu_links']);

    $stores = $this->all('commerce_store');
    $store = reset($stores);
    $this->assertSame(self::CONFIGURED_STORE, $store->uuid(), 'The default store is born under the uuid config names.');
    $this->assertSame(self::CONFIGURED_STORE, $this->config('commerce_store.settings')->get('default_store'));

    $titles = [];
    foreach ($this->all('commerce_product_variation') as $variation) {
      $titles[$variation->get('sku')->value] = (string) $variation->get('title')->value;
    }
    ksort($titles);
    $this->assertSame([
      'ARROZ-1000' => 'Arroz integral',
      'ARROZ-500' => 'Arroz integral',
      'CAFE-250' => 'Café de origen',
    ], $titles, 'Every variation has a title, so add-to-cart can print it.');
  }

  /**
   * The second run on an unchanged seed changes nothing.
   */
  public function testIdempotent(): void {
    $this->seed();
    $tally = $this->seed();
    foreach (['stores', 'terms', 'products', 'variations', 'menu_links', 'updated'] as $kind) {
      $this->assertSame(0, $tally[$kind], "second run: $kind");
    }
    $this->assertSame(3, $tally['unchanged']);
    $this->assertCount(2, $this->all('commerce_product'));
  }

  /**
   * A variation left untitled by an older seeder gets its product's title.
   */
  public function testUntitledVariationIsRepaired(): void {
    $this->seed();
    $variations = $this->container->get('entity_type.manager')->getStorage('commerce_product_variation')
      ->loadByProperties(['sku' => 'CAFE-250']);
    $variation = reset($variations);
    // What the old seeders left behind: a title of NULL, written straight to
    // the table, as a variation saved before its product used to get.
    $this->container->get('database')->update('commerce_product_variation_field_data')
      ->fields(['title' => NULL])->condition('variation_id', $variation->id())->execute();
    $this->container->get('cache.entity')->deleteAll();
    $this->container->get('entity_type.manager')->getStorage('commerce_product_variation')->resetCache();

    $tally = $this->seed();
    $this->assertGreaterThan(0, $tally['updated']);
    $this->assertSame('Café de origen', (string) $this->all('commerce_product_variation')[$variation->id()]->get('title')->value);
  }

  /**
   * A store and a term under a former name are renamed, keeping their ids.
   */
  public function testFormerlyRenamesInPlace(): void {
    $etm = $this->container->get('entity_type.manager');
    $store = $etm->getStorage('commerce_store')->create([
      'type' => 'online',
      'name' => 'Tienda vieja',
      'mail' => 'vieja@example.com',
      'default_currency' => 'COP',
      'timezone' => 'America/Bogota',
      'address' => ['country_code' => 'CO'],
    ]);
    $store->save();

    $tally = $this->seed();
    $this->assertSame(0, $tally['stores']);
    $stores = $this->all('commerce_store');
    $this->assertCount(1, $stores);
    $this->assertSame('Tienda de prueba', $stores[$store->id()]->label());
    $this->assertSame('tienda@example.com', $stores[$store->id()]->get('mail')->value);
  }

  /**
   * A check run reports what it would do and writes nothing.
   */
  public function testCheckWritesNothing(): void {
    $tally = $this->seed(TRUE);
    $this->assertSame(2, $tally['products']);
    $this->assertCount(0, $this->all('commerce_product'));
    $this->assertCount(0, $this->all('commerce_store'));
    $this->assertSame(self::CONFIGURED_STORE, $this->config('commerce_store.settings')->get('default_store'));
  }

}
