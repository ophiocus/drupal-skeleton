<?php

declare(strict_types=1);

namespace Drupal\Tests\business_schema\Kernel;

use Drupal\business_schema\JsonLd;
use Drupal\business_schema\ProductFacts;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\entity_test\Entity\EntityTestRev;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\file\Entity\File;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Product JSON-LD: the assembly, and the facts read field by field.
 *
 * Commerce is not in the upstream's own codebase, so the facts are read from
 * stand-ins that carry Commerce's field names: an entity_test "product" with
 * body, an image, a brand and "variations", referencing entity_test_rev
 * "variations" with sku, status, an image, a stock and a GTIN field. The
 * price field is Commerce's own type and has no stand-in: the assembly test
 * covers prices. The product page hook itself (route
 * entity.commerce_product.canonical) is not exercised here.
 */
#[Group('business_schema')]
#[RunTestsInSeparateProcesses]
final class ProductSchemaTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'file',
    'image',
    'text',
    'filter',
    'entity_test',
    'business_schema',
  ];

  /**
   * The seller, as the business facts give it.
   */
  private const BUSINESS = [
    'name' => 'Example Bakery',
    'url' => 'https://example.com/',
    'item_condition' => 'NewCondition',
    'return_policy' => [
      'category' => 'MerchantReturnNotPermitted',
      'days' => 0,
      'country' => 'CO',
      'method' => '',
      'fees' => '',
    ],
  ];

  /**
   * One product, one offer, as product facts give them.
   */
  private const PRODUCT = [
    'name' => 'Pan de bono',
    'description' => 'Recién horneado.',
    'images' => ['https://example.com/files/pan.jpg'],
    'brand' => '',
    'category' => 'Panes',
    'offers' => [
      [
        'sku' => 'PAN-1',
        'gtin' => '',
        'price' => '4000.000000',
        'currency' => 'COP',
        'availability' => 'InStock',
        'url' => 'https://example.com/producto/pan-de-bono',
      ],
    ],
  ];

  /**
   * One offer: SKU on the product, the offer whole, nothing empty.
   */
  public function testAssembly(): void {
    $document = JsonLd::product(self::PRODUCT, self::BUSINESS);
    $this->assertSame([
      '@context' => 'https://schema.org',
      '@type' => 'Product',
      'name' => 'Pan de bono',
      'description' => 'Recién horneado.',
      'image' => ['https://example.com/files/pan.jpg'],
      'category' => 'Panes',
      'sku' => 'PAN-1',
      'offers' => [
        '@type' => 'Offer',
        'price' => '4000',
        'priceCurrency' => 'COP',
        'availability' => 'https://schema.org/InStock',
        'itemCondition' => 'https://schema.org/NewCondition',
        'url' => 'https://example.com/producto/pan-de-bono',
        'seller' => [
          '@type' => 'Organization',
          'name' => 'Example Bakery',
          'url' => 'https://example.com/',
        ],
        'hasMerchantReturnPolicy' => [
          '@type' => 'MerchantReturnPolicy',
          'applicableCountry' => 'CO',
          'returnPolicyCategory' => 'https://schema.org/MerchantReturnNotPermitted',
        ],
      ],
    ], $document);
    $json = JsonLd::encode($document);
    $this->assertStringNotContainsStringIgnoringCase('aggregateRating', $json);
    $this->assertStringNotContainsStringIgnoringCase('"review', $json);

    // A brand, when the product has one.
    $document = JsonLd::product(['brand' => 'Lara'] + self::PRODUCT, self::BUSINESS);
    $this->assertSame(['@type' => 'Brand', 'name' => 'Lara'], $document['brand']);

    // Several offers each carry their own SKU.
    $offers = self::PRODUCT['offers'];
    $offers[] = ['sku' => 'PAN-6', 'price' => '21000', 'availability' => 'OutOfStock'] + $offers[0];
    $document = JsonLd::product(['offers' => $offers] + self::PRODUCT, self::BUSINESS);
    $this->assertArrayNotHasKey('sku', $document);
    $this->assertSame(['PAN-1', 'PAN-6'], array_column($document['offers'], 'sku'));
    $this->assertSame('https://schema.org/OutOfStock', $document['offers'][1]['availability']);
  }

  /**
   * Without what Google requires, no markup at all.
   */
  public function testRequiredFields(): void {
    $this->assertNull(JsonLd::product(['images' => []] + self::PRODUCT, self::BUSINESS), 'No image.');
    $this->assertNull(JsonLd::product(['name' => ''] + self::PRODUCT, self::BUSINESS), 'No name.');
    foreach (['0', '0.000000', '', 'gratis', '-5'] as $price) {
      $offers = [['price' => $price] + self::PRODUCT['offers'][0]];
      $this->assertNull(JsonLd::product(['offers' => $offers] + self::PRODUCT, self::BUSINESS), "Price '$price'.");
    }
    $offers = [['currency' => ''] + self::PRODUCT['offers'][0]];
    $this->assertNull(JsonLd::product(['offers' => $offers] + self::PRODUCT, self::BUSINESS), 'No currency.');
    $this->assertSame('12.5', JsonLd::price('12.500000'));
    $this->assertSame('0.5', JsonLd::price('000.50'));
  }

  /**
   * The facts, read through the entity API from fields named like Commerce's.
   */
  public function testFacts(): void {
    $this->installEntitySchema('user');
    $this->installEntitySchema('file');
    $this->installEntitySchema('entity_test');
    $this->installEntitySchema('entity_test_rev');
    $this->installSchema('file', ['file_usage']);
    $this->installConfig(['system', 'filter', 'business_schema']);
    $this->container->get('router.builder')->rebuild();

    $fields = [
      ['entity_test', 'body', 'text_with_summary', []],
      ['entity_test', 'field_image', 'image', []],
      ['entity_test', 'field_brand', 'string', []],
      ['entity_test', 'variations', 'entity_reference', ['target_type' => 'entity_test_rev']],
      ['entity_test_rev', 'sku', 'string', []],
      ['entity_test_rev', 'status', 'boolean', []],
      ['entity_test_rev', 'field_image', 'image', []],
      ['entity_test_rev', 'field_stock', 'integer', []],
      ['entity_test_rev', 'field_gtin', 'string', []],
    ];
    foreach ($fields as [$entity_type, $name, $type, $settings]) {
      if (!FieldStorageConfig::loadByName($entity_type, $name)) {
        FieldStorageConfig::create([
          'entity_type' => $entity_type,
          'field_name' => $name,
          'type' => $type,
          'settings' => $settings,
          'cardinality' => $name === 'variations' ? -1 : 1,
        ])->save();
      }
      FieldConfig::create([
        'entity_type' => $entity_type,
        'bundle' => $entity_type,
        'field_name' => $name,
        'label' => $name,
      ])->save();
    }
    $this->config('business_schema.settings')
      ->set('products.brand_field', 'field_brand')
      ->set('products.availability_field', 'field_stock')
      ->set('products.gtin_field', 'field_gtin')
      ->save();

    $files = [];
    foreach (['image-1.png', 'image-2.jpg'] as $name) {
      $extension = pathinfo($name, PATHINFO_EXTENSION);
      \Drupal::service('file_system')->copy("core/tests/fixtures/files/$name", "public://$name");
      $file = File::create(['uri' => "public://$name"]);
      $file->save();
      $files[$extension] = $file;
    }

    $in_stock = EntityTestRev::create([
      'name' => 'six',
      'sku' => 'PAN-6',
      'status' => TRUE,
      'field_stock' => 3,
      'field_gtin' => '7701234567890',
      'field_image' => ['target_id' => $files['jpg']->id(), 'alt' => 'Six'],
    ]);
    $in_stock->save();
    $sold_out = EntityTestRev::create(['name' => 'one', 'sku' => 'PAN-1', 'status' => TRUE, 'field_stock' => 0]);
    $sold_out->save();
    $inactive = EntityTestRev::create(['name' => 'old', 'sku' => 'PAN-OLD', 'status' => FALSE]);
    $inactive->save();

    $product = EntityTest::create([
      'name' => 'Pan de bono',
      'body' => [
        'value' => '<p>Recién <strong>horneado</strong>,   cada mañana.</p>',
        'summary' => '',
        'format' => 'plain_text',
      ],
      'field_image' => ['target_id' => $files['png']->id(), 'alt' => 'Pan'],
      'field_brand' => 'Lara',
      'variations' => [$in_stock, $sold_out, $inactive],
    ]);
    $product->save();

    $cacheability = new CacheableMetadata();
    $facts = $this->container->get(ProductFacts::class)->facts($product, $cacheability);
    $this->assertSame('Pan de bono', $facts['name']);
    $this->assertSame('Recién horneado, cada mañana.', $facts['description']);
    $this->assertSame('Lara', $facts['brand']);
    $this->assertSame('', $facts['category'], 'No category field named: none.');
    $this->assertCount(2, $facts['images']);
    $this->assertStringEndsWith('image-1.png', $facts['images'][0]);
    $this->assertStringEndsWith('image-2.jpg', $facts['images'][1]);

    $this->assertSame(['PAN-6', 'PAN-1'], array_column($facts['offers'], 'sku'), 'The inactive variation is no offer.');
    $this->assertSame(['InStock', 'OutOfStock'], array_column($facts['offers'], 'availability'));
    $this->assertSame('7701234567890', $facts['offers'][0]['gtin']);
    $this->assertStringContainsString('/entity_test/' . $product->id() . '?v=' . $in_stock->id(), $facts['offers'][0]['url']);
    $this->assertContains('entity_test:' . $product->id(), $cacheability->getCacheTags());
    $this->assertContains('entity_test_rev:' . $in_stock->id(), $cacheability->getCacheTags());
    $this->assertContains('config:business_schema.settings', $cacheability->getCacheTags());

    // A summary wins over the body. With no availability field, the default.
    $product->set('body', ['value' => '<p>Long text.</p>', 'summary' => 'Short.', 'format' => 'plain_text']);
    $this->config('business_schema.settings')->set('products.availability_field', '')->save();
    $facts = $this->container->get(ProductFacts::class)->facts($product, new CacheableMetadata());
    $this->assertSame('Short.', $facts['description']);
    $this->assertSame(['InStock', 'InStock'], array_column($facts['offers'], 'availability'));
    // A site that states no default states nothing.
    $this->config('business_schema.settings')->set('products.default_availability', '')->save();
    $facts = $this->container->get(ProductFacts::class)->facts($product, new CacheableMetadata());
    $this->assertSame(['', ''], array_column($facts['offers'], 'availability'));
  }

}
