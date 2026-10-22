<?php

declare(strict_types=1);

namespace Drupal\site_seed;

/**
 * A seed that cannot be converged: a missing file, a missing image, a bad row.
 *
 * Thrown rather than returned, so a deploy's seed step fails loudly instead of
 * reporting a half-applied seed as done.
 */
final class SeedException extends \RuntimeException {}
