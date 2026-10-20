<?php

declare(strict_types=1);

namespace Drupal\ga4_report;

/**
 * A refresh could not get its data from a source; the message says why.
 *
 * Messages are written for the person reading the report page or the Drush
 * output. They never carry a credential or a token.
 */
final class ReportException extends \RuntimeException {}
