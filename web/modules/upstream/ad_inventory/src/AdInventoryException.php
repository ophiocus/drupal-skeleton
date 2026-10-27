<?php

declare(strict_types=1);

namespace Drupal\ad_inventory;

/**
 * A refused change to the ad inventory (a provider conflict, a missing plugin).
 */
final class AdInventoryException extends \RuntimeException {

}
