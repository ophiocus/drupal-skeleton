<?php

declare(strict_types=1);

namespace Drupal\ad_inventory\Attribute;

use Drupal\Component\Plugin\Attribute\Plugin;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Defines an ad network provider plugin.
 *
 * Plugins live in a module's src/Plugin/AdProvider and implement
 * \Drupal\ad_inventory\Plugin\AdProvider\AdProviderPluginInterface.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class AdProvider extends Plugin {

  /**
   * Constructs an AdProvider attribute.
   *
   * @param string $id
   *   The plugin id.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup $label
   *   The name editors read.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup|null $description
   *   What the provider prints.
   * @param bool $restricted
   *   Whether editing an instance needs "administer pasted ad snippets"
   *   (the plugin prints site-supplied code on public pages).
   * @param class-string|null $deriver
   *   The deriver class.
   */
  public function __construct(
    public readonly string $id,
    public readonly TranslatableMarkup $label,
    public readonly ?TranslatableMarkup $description = NULL,
    public readonly bool $restricted = FALSE,
    public readonly ?string $deriver = NULL,
  ) {}

}
