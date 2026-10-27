<?php

declare(strict_types=1);

namespace Drupal\ad_inventory\Plugin\AdMailProvider;

use Drupal\ad_inventory\Attribute\AdMailProvider;
use Drupal\Component\Utility\Html;
use Drupal\Core\Plugin\PluginBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * A plain HTML block (label, then a linked image) for any sending tool.
 */
#[AdMailProvider(
  id: 'generic',
  label: new TranslatableMarkup('Generic HTML'),
  description: new TranslatableMarkup('Paste into any template that accepts HTML.'),
)]
final class GenericHtml extends PluginBase implements AdMailProviderPluginInterface {

  /**
   * {@inheritdoc}
   */
  public function snippet(array $slot): array {
    $code = sprintf(
      '<p style="margin:0 0 4px;font:12px/1.4 sans-serif;color:#666666;">%s</p>' . "\n"
      . '<a href="%s" rel="sponsored noopener"><img src="%s" width="%d" height="%d" alt="%s" style="display:block;border:0;max-width:100%%;height:auto;"></a>',
      Html::escape($slot['label']),
      Html::escape($slot['go']),
      Html::escape($slot['image']),
      $slot['width'],
      $slot['height'],
      Html::escape($slot['alt']),
    );
    return [
      'code' => $code,
      'help' => (string) new TranslatableMarkup('Paste where the banner goes. The image shows the creative in force on the day the mail is opened.'),
    ];
  }

}
