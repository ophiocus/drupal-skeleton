<?php

declare(strict_types=1);

namespace Drupal\ad_inventory\Entity;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\Core\Entity\EntityPublishedInterface;
use Drupal\media\MediaInterface;

/**
 * A creative: one media image per format, a target, alt text and a caption.
 */
interface AdCreativeInterface extends ContentEntityInterface, EntityChangedInterface, EntityPublishedInterface {

  /**
   * Whether the creative may fill slots as a house creative.
   */
  public function isHouse(): bool;

  /**
   * The rendition media for a format, or NULL.
   */
  public function rendition(string $format): ?MediaInterface;

  /**
   * Format ids with a rendition.
   *
   * @return string[]
   *   Format ids.
   */
  public function renditionFormats(): array;

  /**
   * The absolute address a click leads to, or ''.
   */
  public function targetUrl(): string;

  /**
   * The image's alternative text.
   */
  public function alt(): string;

  /**
   * The caption printed under the image, or ''.
   */
  public function caption(): string;

  /**
   * The kind: "plain" or a site-defined kind.
   */
  public function kind(): string;

  /**
   * The advertiser's name: the text field, else the referenced user's name.
   */
  public function advertiserName(): string;

}
