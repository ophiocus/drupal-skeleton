<?php

declare(strict_types=1);

namespace Drupal\ad_inventory\Entity;

use Drupal\Core\Config\Entity\ConfigEntityInterface;

/**
 * A size a slot reserves: width, height, and the network size per breakpoint.
 */
interface AdFormatInterface extends ConfigEntityInterface {

  /**
   * The width in CSS pixels.
   */
  public function width(): int;

  /**
   * The height in CSS pixels.
   */
  public function height(): int;

  /**
   * The fixed size a network unit takes, per viewport width.
   *
   * @return array<int, array{min_width: int, width: int, height: int}>
   *   Rows sorted by min_width; each applies from its min_width upwards. An
   *   empty list means no network unit fits this format (email).
   */
  public function networkSizes(): array;

  /**
   * The image style that renders this format at a pixel density.
   *
   * @param int $density
   *   The pixel density, 1 or 2.
   */
  public function styleName(int $density): string;

  /**
   * Makes sure both image styles exist and match the size.
   */
  public function ensureStyles(): void;

}
