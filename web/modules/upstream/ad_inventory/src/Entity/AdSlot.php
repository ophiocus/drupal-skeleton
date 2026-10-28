<?php

declare(strict_types=1);

namespace Drupal\ad_inventory\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\Core\Entity\Attribute\ConfigEntityType;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * A banner space: one reserved place, sold and counted on its own.
 *
 * A space has a format on desktop and one on phones; every booking of it
 * carries a rendition for both. Where it appears is decided by block
 * placement (a block per space) or by a template that prints the ad_slot
 * element, so the same space can sit in one place on desktop and another on
 * phones.
 */
#[ConfigEntityType(
  id: 'ad_slot',
  label: new TranslatableMarkup('Banner space'),
  label_collection: new TranslatableMarkup('Banner spaces'),
  config_prefix: 'slot',
  entity_keys: [
    'id' => 'id',
    'label' => 'label',
    'weight' => 'weight',
  ],
  handlers: [
    'list_builder' => 'Drupal\ad_inventory\ListBuilder\AdSlotListBuilder',
    'form' => [
      'default' => 'Drupal\ad_inventory\Form\AdSlotForm',
      'delete' => 'Drupal\Core\Entity\EntityDeleteForm',
    ],
    'route_provider' => [
      'html' => 'Drupal\Core\Entity\Routing\AdminHtmlRouteProvider',
    ],
  ],
  links: [
    'collection' => '/admin/structure/ad-inventory/spaces',
    'add-form' => '/admin/structure/ad-inventory/spaces/add',
    'edit-form' => '/admin/structure/ad-inventory/spaces/{ad_slot}',
    'delete-form' => '/admin/structure/ad-inventory/spaces/{ad_slot}/delete',
  ],
  admin_permission: 'administer ad inventory',
  config_export: [
    'id',
    'label',
    'description',
    'channel',
    'desktop_format',
    'phone_format',
    'alternatives',
    'fill',
    'kinds',
    'weight',
  ],
)]
final class AdSlot extends ConfigEntityBase {

  /**
   * A space on web pages.
   */
  public const WEB = 'web';

  /**
   * A space in mail templates, served as an image from the edge.
   */
  public const EMAIL = 'email';

  /**
   * Machine name.
   */
  protected string $id;

  /**
   * Human name.
   */
  protected string $label;

  /**
   * Where the space sits, in a sentence for staff and the catalogue.
   */
  protected string $description = '';

  /**
   * The channel: 'web' or 'email'.
   */
  protected string $channel = self::WEB;

  /**
   * Format id on wide screens.
   */
  protected string $desktop_format = '';

  /**
   * Format id on phones.
   */
  protected string $phone_format = '';

  /**
   * Other format ids staff may choose for this space on one page.
   *
   * @var string[]
   */
  protected array $alternatives = [];

  /**
   * What may fill the space: 'booking', 'provider', 'house'.
   *
   * @var string[]
   */
  protected array $fill = ['booking', 'provider', 'house'];

  /**
   * Creative kinds a booking of this space may carry; empty means any.
   *
   * @var string[]
   */
  protected array $kinds = [];

  /**
   * Sort order in lists.
   */
  protected int $weight = 0;

  /**
   * The cache tag every page carrying this space holds.
   */
  public static function tag(string $id): string {
    return 'ad_inventory_slot:' . $id;
  }

  /**
   * Where the space sits.
   */
  public function description(): string {
    return $this->description;
  }

  /**
   * The channel: 'web' or 'email'.
   */
  public function channel(): string {
    return $this->channel;
  }

  /**
   * Format id on wide screens.
   */
  public function desktopFormat(): string {
    return $this->desktop_format;
  }

  /**
   * Format id on phones (the desktop one for email spaces).
   */
  public function phoneFormat(): string {
    return $this->phone_format !== '' ? $this->phone_format : $this->desktop_format;
  }

  /**
   * Format ids a booking of this space must carry renditions for.
   *
   * @return string[]
   *   Unique format ids.
   */
  public function formats(): array {
    return array_values(array_unique(array_filter([$this->desktop_format, $this->phoneFormat()])));
  }

  /**
   * Alternative format ids.
   *
   * @return string[]
   *   Format ids.
   */
  public function alternatives(): array {
    return $this->alternatives;
  }

  /**
   * Whether a fill source may fill this space.
   */
  public function accepts(string $source): bool {
    return in_array($source, $this->fill, TRUE);
  }

  /**
   * Whether a creative kind may be booked here.
   */
  public function allowsKind(string $kind): bool {
    return $this->kinds === [] || in_array($kind, $this->kinds, TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTagsToInvalidate(): array {
    return array_merge(parent::getCacheTagsToInvalidate(), [self::tag((string) $this->id())]);
  }

}
