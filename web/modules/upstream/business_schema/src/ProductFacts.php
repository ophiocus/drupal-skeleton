<?php

declare(strict_types=1);

namespace Drupal\business_schema;

use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\Unicode;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;

/**
 * A Commerce product's facts, read field by field.
 *
 * No Commerce class is used: the product, its variations, their price and
 * SKU are read through the entity and field APIs, each guarded with
 * hasField(), so the module installs and runs where Commerce is absent.
 *
 * Read from the product: its title, body, every image field, the brand and
 * category fields named in the settings. From each active variation: SKU,
 * price (number and currency), every image field, the GTIN and availability
 * fields named in the settings.
 */
final class ProductFacts {

  /**
   * Description length: Merchant Center's limit.
   */
  public const DESCRIPTION_LENGTH = 5000;

  /**
   * The schema.org availability values a text field may hold.
   */
  public const AVAILABILITY = [
    'InStock',
    'OutOfStock',
    'PreOrder',
    'BackOrder',
    'LimitedAvailability',
    'OnlineOnly',
    'InStoreOnly',
    'MadeToOrder',
    'Discontinued',
    'SoldOut',
  ];

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly EntityRepositoryInterface $entityRepository,
    private readonly FileUrlGeneratorInterface $fileUrlGenerator,
  ) {}

  /**
   * The product's facts.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $product
   *   A commerce_product, in the language it is shown in.
   * @param \Drupal\Core\Cache\CacheableMetadata $cacheability
   *   Collects what the facts depend on.
   *
   * @return array
   *   name, description, images, brand, category, and offers: a list of
   *   sku, gtin, price, currency, availability and url.
   */
  public function facts(ContentEntityInterface $product, CacheableMetadata $cacheability): array {
    $settings = $this->configFactory->get('business_schema.settings');
    $cacheability->addCacheableDependency($settings)->addCacheableDependency($product);

    $images = $this->images($product, $cacheability);
    $variations = [];
    if ($product->hasField('variations')) {
      foreach ($product->get('variations')->referencedEntities() as $variation) {
        if (!$variation instanceof ContentEntityInterface) {
          continue;
        }
        $variation = $this->entityRepository->getTranslationFromContext($variation);
        $cacheability->addCacheableDependency($variation);
        // An inactive variation cannot be bought: it is no offer.
        if ($variation->hasField('status') && !$variation->get('status')->isEmpty() && !$variation->get('status')->value) {
          continue;
        }
        $variations[] = $variation;
        $images = array_merge($images, $this->images($variation, $cacheability));
      }
    }

    $several = count($variations) > 1;
    $offers = [];
    foreach ($variations as $variation) {
      $price = $variation->hasField('price') && !$variation->get('price')->isEmpty()
        ? $variation->get('price')->first()->getValue()
        : [];
      $options = ['absolute' => TRUE];
      if ($several) {
        // Commerce's own address for a variation: the product page, ?v=id.
        $options['query'] = ['v' => $variation->id()];
      }
      $url = $product->isNew() ? NULL : $product->toUrl('canonical', $options)->toString(TRUE);
      if ($url !== NULL) {
        $cacheability->addCacheableDependency($url);
      }
      $offers[] = [
        'sku' => $variation->hasField('sku') ? trim((string) $variation->get('sku')->value) : '',
        'gtin' => $this->text($variation, (string) $settings->get('products.gtin_field'), $cacheability),
        'price' => (string) ($price['number'] ?? ''),
        'currency' => (string) ($price['currency_code'] ?? ''),
        'availability' => $this->availability($variation),
        'url' => $url?->getGeneratedUrl() ?? '',
      ];
    }

    return [
      'name' => trim((string) $product->label()),
      'description' => $this->description($product),
      'images' => array_values(array_unique($images)),
      'brand' => $this->text($product, (string) $settings->get('products.brand_field'), $cacheability),
      'category' => $this->text($product, (string) $settings->get('products.category_field'), $cacheability),
      'offers' => $offers,
    ];
  }

  /**
   * The body's summary, else its text, as plain text.
   */
  private function description(ContentEntityInterface $product): string {
    if (!$product->hasField('body') || $product->get('body')->isEmpty()) {
      return '';
    }
    $body = $product->get('body')->first()->getValue();
    foreach (['summary', 'value'] as $key) {
      $text = trim(preg_replace('/\s+/u', ' ', Html::decodeEntities(strip_tags((string) ($body[$key] ?? '')))) ?? '');
      if ($text !== '') {
        return Unicode::truncate($text, self::DESCRIPTION_LENGTH, TRUE, TRUE);
      }
    }
    return '';
  }

  /**
   * Absolute URLs of every image in the entity's image fields.
   *
   * @return string[]
   *   The URLs.
   */
  private function images(ContentEntityInterface $entity, CacheableMetadata $cacheability): array {
    $urls = [];
    foreach ($entity->getFieldDefinitions() as $name => $definition) {
      if ($definition->getType() !== 'image') {
        continue;
      }
      foreach ($entity->get($name) as $item) {
        $file = $item->entity ?? NULL;
        if (!$file instanceof EntityInterface || !method_exists($file, 'getFileUri')) {
          continue;
        }
        $cacheability->addCacheableDependency($file);
        $urls[] = $this->fileUrlGenerator->generateAbsoluteString($file->getFileUri());
      }
    }
    return $urls;
  }

  /**
   * A field's text: its value, or the labels of the entities it references.
   */
  private function text(ContentEntityInterface $entity, string $field, CacheableMetadata $cacheability): string {
    if ($field === '' || !$entity->hasField($field) || $entity->get($field)->isEmpty()) {
      return '';
    }
    $items = $entity->get($field);
    if ($items->getFieldDefinition()->getType() === 'entity_reference') {
      $labels = [];
      foreach ($items->referencedEntities() as $referenced) {
        $referenced = $this->entityRepository->getTranslationFromContext($referenced);
        $cacheability->addCacheableDependency($referenced);
        $labels[] = trim((string) $referenced->label());
      }
      return implode(', ', array_filter($labels));
    }
    return trim((string) ($items->first()->getValue()['value'] ?? ''));
  }

  /**
   * The variation's availability: from the field named in the settings.
   *
   * A boolean says in stock or out of it; a number is the stock; a text
   * field holds a schema.org name. With no such field, or nothing in it, the
   * settings' default (an active variation can be bought: InStock), or ''
   * when the site states none.
   */
  private function availability(ContentEntityInterface $variation): string {
    $settings = $this->configFactory->get('business_schema.settings');
    $default = (string) $settings->get('products.default_availability');
    $field = (string) $settings->get('products.availability_field');
    if ($field === '' || !$variation->hasField($field) || $variation->get($field)->isEmpty()) {
      return $default;
    }
    $items = $variation->get($field);
    $value = $items->first()->getValue()['value'] ?? NULL;
    switch ($items->getFieldDefinition()->getType()) {
      case 'boolean':
        return $value ? 'InStock' : 'OutOfStock';

      case 'integer':
      case 'decimal':
      case 'float':
        return is_numeric($value) && $value > 0 ? 'InStock' : 'OutOfStock';

      default:
        $value = (string) $value;
        return in_array($value, self::AVAILABILITY, TRUE) ? $value : $default;
    }
  }

}
