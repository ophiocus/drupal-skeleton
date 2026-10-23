<?php

declare(strict_types=1);

namespace Drupal\appointments;

/**
 * The requested start time is not free (taken, closed, or out of range).
 */
final class SlotUnavailableException extends \RuntimeException {}
