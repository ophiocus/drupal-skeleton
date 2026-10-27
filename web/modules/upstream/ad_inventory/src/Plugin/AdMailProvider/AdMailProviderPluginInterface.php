<?php

declare(strict_types=1);

namespace Drupal\ad_inventory\Plugin\AdMailProvider;

use Drupal\Component\Plugin\PluginInspectionInterface;

/**
 * Turns an email slot into what one sending tool needs.
 *
 * The module sends no mail. A plugin gives the snippet, merge tag or
 * template block that places the slot's two public addresses (the image and
 * the click) in that tool's templates. A plugin that pushes a block through
 * the tool's API does so from its own code, on its own button or command.
 */
interface AdMailProviderPluginInterface extends PluginInspectionInterface {

  /**
   * The snippet for one email slot.
   *
   * @param array{slot: string, label: string, image: string, go: string, width: int, height: int, alt: string} $slot
   *   The slot: id, the label printed above it, the absolute image and click
   *   addresses, the fixed size, and the fixed alternative text.
   *
   * @return array{code: string, help: string}
   *   What to paste, and one line on where it goes.
   */
  public function snippet(array $slot): array;

}
