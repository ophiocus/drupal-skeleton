<?php

declare(strict_types=1);

namespace Drupal\ad_inventory\Attribute;

use Drupal\Component\Plugin\Attribute\Plugin;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Defines a mail provider plugin: an email slot as a sending tool needs it.
 *
 * Plugins live in a module's src/Plugin/AdMailProvider and implement
 * \Drupal\ad_inventory\Plugin\AdMailProvider\AdMailProviderPluginInterface.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class AdMailProvider extends Plugin {

  /**
   * Constructs an AdMailProvider attribute.
   *
   * @param string $id
   *   The plugin id.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup $label
   *   The name editors read.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup|null $description
   *   Where the snippet goes.
   * @param class-string|null $deriver
   *   The deriver class.
   */
  public function __construct(
    public readonly string $id,
    public readonly TranslatableMarkup $label,
    public readonly ?TranslatableMarkup $description = NULL,
    public readonly ?string $deriver = NULL,
  ) {}

}
