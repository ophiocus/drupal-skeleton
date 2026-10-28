<?php

declare(strict_types=1);

namespace Drupal\ad_inventory\Attribute;

use Drupal\Component\Plugin\Attribute\Plugin;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Declares an ad provider plugin.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class AdProvider extends Plugin {

  /**
   * Constructs the attribute.
   *
   * @param string $id
   *   Plugin id.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup $label
   *   Human name.
   * @param string[] $labels
   *   The only labels the network allows above its units; empty means the
   *   site's label is fine.
   * @param string[] $excludes
   *   Formats this provider serves that other providers forbid on the same
   *   site: 'popunder', 'push', 'vignette', 'interstitial', 'exit', 'slide_in'.
   * @param string[] $forbids
   *   Formats this provider refuses to share a site with.
   * @param bool $houseBehind
   *   Whether a house banner may show behind an unfilled unit.
   * @param int $perPage
   *   The most units of this provider on one page; 0 means no limit.
   * @param string|null $permission
   *   A permission required to configure it.
   */
  public function __construct(
    public readonly string $id,
    public readonly TranslatableMarkup $label,
    public readonly array $labels = [],
    public readonly array $excludes = [],
    public readonly array $forbids = [],
    public readonly bool $houseBehind = TRUE,
    public readonly int $perPage = 0,
    public readonly ?string $permission = NULL,
  ) {}

}
