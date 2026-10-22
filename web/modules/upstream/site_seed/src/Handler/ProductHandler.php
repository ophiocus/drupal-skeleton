<?php

declare(strict_types=1);

namespace Drupal\site_seed\Handler;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\site_seed\SeedContext;
use Drupal\site_seed\SeedException;

/**
 * Section `products:`, Commerce products located through their variations.
 *
 * Titles are editable copy; SKUs are keys, so a product is never matched by
 * its title. Each row may set:
 * - title, type, status, body, stores (names), category (a term name in the
 *   category vocabulary), image ({file, alt} from the seed's images/);
 * - variations: [{sku, price, currency, status, title}].
 *
 * Field names default to the shop format and can be changed in the seed
 * file's `site_seed:` section: category_field (field_categoria),
 * category_vocabulary (categoria), image_field (field_image),
 * image_directory (public://productos/seed), body_format (basic_html).
 */
final class ProductHandler implements SeedHandlerInterface {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly FileSystemInterface $fileSystem,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function section(): string {
    return 'products';
  }

  /**
   * {@inheritdoc}
   */
  public function weight(): int {
    return 20;
  }

  /**
   * {@inheritdoc}
   */
  public function applies(): bool {
    return $this->entityTypeManager->hasDefinition('commerce_product')
      && $this->entityTypeManager->hasDefinition('commerce_product_variation');
  }

  /**
   * {@inheritdoc}
   */
  public function converge(array $rows, SeedContext $context): void {
    $product_storage = $this->entityTypeManager->getStorage('commerce_product');
    $variation_storage = $this->entityTypeManager->getStorage('commerce_product_variation');
    $stores = $context->stores ?? $this->storesByName();
    $category_field = (string) $context->option('category_field', 'field_categoria');
    $category_vocabulary = (string) $context->option('category_vocabulary', 'categoria');
    $image_field = (string) $context->option('image_field', 'field_image');

    foreach ($rows as $row) {
      $type = $row['type'] ?? 'default';
      $product = NULL;
      $variations = [];
      foreach ($row['variations'] ?? [] as $vrow) {
        $hit = $variation_storage->loadByProperties(['sku' => $vrow['sku']]);
        $variation = $hit ? reset($hit) : NULL;
        if ($variation instanceof ContentEntityInterface) {
          $variations[$vrow['sku']] = $variation;
          $owner = $variation->get('product_id')->entity;
          $product ??= $owner instanceof ContentEntityInterface ? $owner : NULL;
        }
      }
      if (!$product) {
        $product = $product_storage->create(['type' => $type, 'title' => $row['title']]);
        assert($product instanceof ContentEntityInterface);
        $product->save();
        $context->count('products');
      }

      // Variations.
      $variation_ids = [];
      foreach ($row['variations'] ?? [] as $vrow) {
        $variation = $variations[$vrow['sku']] ?? NULL;
        if (!$variation) {
          $variation = $variation_storage->create([
            'type' => $vrow['type'] ?? $type,
            'sku' => $vrow['sku'],
            'product_id' => $product->id(),
          ]);
          assert($variation instanceof ContentEntityInterface);
          $context->count('variations');
        }
        $dirty = $variation->isNew();
        $currency = (string) ($vrow['currency'] ?? 'COP');
        $have = $variation->get('price')->first()?->getValue() ?? [];
        if (($have['currency_code'] ?? NULL) !== $currency
          || !self::sameNumber((string) ($have['number'] ?? ''), (string) $vrow['price'])) {
          $variation->set('price', ['number' => (string) $vrow['price'], 'currency_code' => $currency]);
          $dirty = TRUE;
        }
        $status = (bool) ($vrow['status'] ?? TRUE);
        if ((bool) $variation->get('status')->value !== $status) {
          $variation->set('status', $status);
          $dirty = TRUE;
        }
        // A variation saved without a title breaks add-to-cart: the cart
        // message prints its label, and a NULL label is a TypeError, a 500 on
        // every click. Where the variation type generates titles, Commerce
        // owns the title and only an empty one is repaired (saving with the
        // product set regenerates it); elsewhere the row's title, or the
        // product's, is the title.
        $have_title = (string) $variation->get('title')->value;
        $want_title = (string) ($vrow['title'] ?? $row['title']);
        if (trim($have_title) === '' || (!$this->generatesTitle((string) $variation->bundle()) && $have_title !== $want_title)) {
          $variation->set('title', $want_title);
          $dirty = TRUE;
        }
        if ($dirty) {
          $variation->save();
          $context->count('updated');
        }
        else {
          $context->count('unchanged');
        }
        $variation_ids[] = (int) $variation->id();
      }

      // The product.
      $dirty = FALSE;
      if ((string) $product->label() !== $row['title']) {
        $product->set('title', $row['title']);
        $dirty = TRUE;
      }
      $status = (bool) ($row['status'] ?? TRUE);
      if ((bool) $product->get('status')->value !== $status) {
        $product->set('status', $status);
        $dirty = TRUE;
      }
      if (isset($row['body']) && $product->hasField('body')
        && (string) $product->get('body')->value !== (string) $row['body']) {
        $product->set('body', [
          'value' => $row['body'],
          'format' => (string) $context->option('body_format', 'basic_html'),
        ]);
        $dirty = TRUE;
      }
      if (isset($row['stores'])) {
        $want = [];
        foreach ($row['stores'] as $name) {
          if (isset($stores[$name])) {
            $want[] = (int) $stores[$name]->id();
          }
        }
        $have = array_map(static fn ($item): int => (int) $item['target_id'], $product->get('stores')->getValue());
        sort($want);
        sort($have);
        if ($want !== $have) {
          $product->set('stores', $want);
          $dirty = TRUE;
        }
      }
      if (!empty($row['category']) && $product->hasField($category_field)) {
        $term = $context->terms[$category_vocabulary][$row['category']] ?? $this->term($category_vocabulary, $row['category']);
        if ($term && (int) ($product->get($category_field)->target_id ?? 0) !== (int) $term->id()) {
          $product->set($category_field, (int) $term->id());
          $dirty = TRUE;
        }
      }
      // The photo is seed-owned while the row names one: a file of that name
      // with that alt text is left alone, anything else is replaced.
      if (!empty($row['image']['file']) && $product->hasField($image_field)) {
        $want_alt = (string) ($row['image']['alt'] ?? $row['title']);
        $item = $product->get($image_field)->first();
        $have_file = $item?->get('entity')->getValue()?->getFilename();
        if ($have_file !== $row['image']['file'] || (string) $item?->get('alt')->getValue() !== $want_alt) {
          $file = $this->file($context, $context->dir . '/images/' . $row['image']['file']);
          $product->set($image_field, ['target_id' => $file->id(), 'alt' => $want_alt]);
          $context->count('images');
          $dirty = TRUE;
        }
      }
      $have_variations = array_map(static fn ($item): int => (int) $item['target_id'], $product->get('variations')->getValue());
      if ($have_variations !== $variation_ids) {
        $product->set('variations', $variation_ids);
        $dirty = TRUE;
      }
      if ($dirty) {
        $product->save();
        $context->count('updated');
      }
    }
  }

  /**
   * Whether a variation type generates its variations' titles.
   */
  private function generatesTitle(string $bundle): bool {
    $type = $this->entityTypeManager->getStorage('commerce_product_variation_type')->load($bundle);
    return $type && (bool) $type->get('generateTitle');
  }

  /**
   * Whether two decimal amounts are the same number ("25000" == "25000.00").
   */
  private static function sameNumber(string $a, string $b): bool {
    if ($a === '' || $b === '') {
      return $a === $b;
    }
    return round((float) $a, 6) === round((float) $b, 6);
  }

  /**
   * Stores by name, when the seed file has no stores section of its own.
   *
   * @return array<string, \Drupal\Core\Entity\ContentEntityInterface>
   *   Keyed by store name.
   */
  private function storesByName(): array {
    $by_name = [];
    if ($this->entityTypeManager->hasDefinition('commerce_store')) {
      foreach ($this->entityTypeManager->getStorage('commerce_store')->loadMultiple() as $store) {
        if ($store instanceof ContentEntityInterface) {
          $by_name[(string) $store->label()] = $store;
        }
      }
    }
    return $by_name;
  }

  /**
   * A term by vocabulary and name, when the seed file did not seed it.
   */
  private function term(string $vid, string $name): ?ContentEntityInterface {
    $hit = $this->entityTypeManager->getStorage('taxonomy_term')->loadByProperties(['vid' => $vid, 'name' => $name]);
    $term = $hit ? reset($hit) : NULL;
    return $term instanceof ContentEntityInterface ? $term : NULL;
  }

  /**
   * The managed file for a seed image, copied into public:// when needed.
   *
   * One file entity per seed image, found by its URI, so re-seeding never
   * piles up copies. A fresh files directory gets the bytes again under the
   * same entity.
   *
   * @throws \Drupal\site_seed\SeedException
   *   When the seed names an image that is not there.
   */
  private function file(SeedContext $context, string $source): ContentEntityInterface {
    if (!is_file($source)) {
      throw new SeedException('seed image missing: ' . basename($source));
    }
    $dir = (string) $context->option('image_directory', 'public://productos/seed');
    $uri = $dir . '/' . basename($source);
    if (!is_file($uri)) {
      $this->fileSystem->prepareDirectory($dir, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
      $this->fileSystem->copy($source, $uri, FileExists::Replace);
    }
    $storage = $this->entityTypeManager->getStorage('file');
    $hit = $storage->loadByProperties(['uri' => $uri]);
    $file = $hit ? reset($hit) : NULL;
    if (!$file instanceof ContentEntityInterface) {
      $file = $storage->create(['uri' => $uri, 'uid' => 1, 'status' => 1]);
      assert($file instanceof ContentEntityInterface);
      $file->save();
    }
    return $file;
  }

}
