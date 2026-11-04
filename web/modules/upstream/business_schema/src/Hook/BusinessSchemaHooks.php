<?php

declare(strict_types=1);

namespace Drupal\business_schema\Hook;

use Drupal\business_schema\BusinessFacts;
use Drupal\business_schema\JsonLd;
use Drupal\business_schema\ProductFacts;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityPublishedInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Path\PathMatcherInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\Routing\RouteMatchInterface;

/**
 * Prints the JSON-LD; declares the block's template.
 */
final class BusinessSchemaHooks {

  /**
   * The canonical route of a Commerce product.
   */
  public const PRODUCT_ROUTE = 'entity.commerce_product.canonical';

  public function __construct(
    private readonly BusinessFacts $businessFacts,
    private readonly ProductFacts $productFacts,
    private readonly PathMatcherInterface $pathMatcher,
    private readonly RouteMatchInterface $routeMatch,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Implements hook_page_attachments().
   *
   * The front page gets the business (Organization and LocalBusiness), once,
   * as Google asks: on the page that describes the business, not on every
   * page. A product's canonical page gets its Product. Only those pages
   * carry this module's cache tags.
   */
  #[Hook('page_attachments')]
  public function pageAttachments(array &$attachments): void {
    $cacheability = CacheableMetadata::createFromRenderArray($attachments)
      ->addCacheContexts(['url.path.is_front', 'route']);
    $document = NULL;

    if ($this->pathMatcher->isFrontPage()) {
      $document = JsonLd::business($this->businessFacts->facts($cacheability));
    }
    elseif ($this->routeMatch->getRouteName() === self::PRODUCT_ROUTE) {
      $document = $this->product($cacheability);
    }

    if ($document !== NULL) {
      $attachments['#attached']['html_head'][] = [
        [
          '#type' => 'html_tag',
          '#tag' => 'script',
          '#attributes' => ['type' => 'application/ld+json'],
          // Encoded with JSON_HEX_TAG: no value can close the element.
          '#value' => Markup::create(JsonLd::encode($document)),
        ],
        'business_schema',
      ];
    }
    $cacheability->applyTo($attachments);
  }

  /**
   * The product page's document, or NULL.
   */
  private function product(CacheableMetadata $cacheability): ?array {
    $cacheability->addCacheTags(['config:business_schema.settings']);
    if (!$this->configFactory->get('business_schema.settings')->get('products.enabled')) {
      return NULL;
    }
    $product = $this->routeMatch->getParameter('commerce_product');
    if (!$product instanceof ContentEntityInterface) {
      return NULL;
    }
    $cacheability->addCacheableDependency($product);
    if ($product instanceof EntityPublishedInterface && !$product->isPublished()) {
      return NULL;
    }
    $business = $this->businessFacts->facts($cacheability);
    return JsonLd::product($this->productFacts->facts($product, $cacheability), $business);
  }

  /**
   * Implements hook_theme().
   */
  #[Hook('theme')]
  public function theme(): array {
    return [
      'business_details' => [
        'variables' => [
          'name' => '',
          'address' => [],
          'telephone' => NULL,
          'email' => '',
          'hours' => [],
          'special_hours' => [],
          'map_url' => '',
        ],
      ],
    ];
  }

}
