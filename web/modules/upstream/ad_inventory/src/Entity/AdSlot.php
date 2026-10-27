<?php

declare(strict_types=1);

namespace Drupal\ad_inventory\Entity;

use Drupal\ad_inventory\Form\AdSlotForm;
use Drupal\ad_inventory\ListBuilder\AdSlotListBuilder;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\Core\Entity\Attribute\ConfigEntityType;
use Drupal\Core\Entity\EntityDeleteForm;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\Routing\AdminHtmlRouteProvider;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * An ad slot.
 *
 * Placed on pages through its block (one block per web slot) or printed by a
 * template as `#type => 'ad_slot'`. Email slots are served as an image and a
 * click address (`/ad/e/<slot>.png`, `/ad/e/<slot>/go`).
 */
#[ConfigEntityType(
  id: 'ad_slot',
  label: new TranslatableMarkup('Ad slot'),
  label_collection: new TranslatableMarkup('Ad slots'),
  label_singular: new TranslatableMarkup('ad slot'),
  label_plural: new TranslatableMarkup('ad slots'),
  config_prefix: 'slot',
  entity_keys: [
    'id' => 'id',
    'label' => 'label',
  ],
  handlers: [
    'list_builder' => AdSlotListBuilder::class,
    'form' => [
      'add' => AdSlotForm::class,
      'edit' => AdSlotForm::class,
      'delete' => EntityDeleteForm::class,
    ],
    'route_provider' => [
      'html' => AdminHtmlRouteProvider::class,
    ],
  ],
  links: [
    'collection' => '/admin/structure/ad-inventory/slots',
    'add-form' => '/admin/structure/ad-inventory/slots/add',
    'edit-form' => '/admin/structure/ad-inventory/slots/{ad_slot}',
    'delete-form' => '/admin/structure/ad-inventory/slots/{ad_slot}/delete',
  ],
  admin_permission: 'administer ad inventory',
  label_count: [
    'singular' => '@count ad slot',
    'plural' => '@count ad slots',
  ],
  config_export: [
    'id',
    'label',
    'channel',
    'desktop_format',
    'phone_format',
    'alternative_formats',
    'sources',
    'booking_kinds',
    'group',
    'repeatable',
    'units',
  ],
)]
class AdSlot extends ConfigEntityBase implements AdSlotInterface {

  /**
   * The machine name.
   *
   * @var string
   */
  protected $id;

  /**
   * The name editors read.
   *
   * @var string
   */
  protected $label;

  /**
   * The channel: "web" or "email".
   *
   * @var string
   */
  protected $channel = 'web';

  /**
   * The desktop format id.
   *
   * @var string
   */
  protected $desktop_format = '';

  /**
   * The phone format id.
   *
   * @var string
   */
  protected $phone_format = '';

  /**
   * Alternative format ids.
   *
   * @var string[]
   */
  protected $alternative_formats = [];

  /**
   * Allowed fill sources.
   *
   * @var string[]
   */
  protected $sources = ['booking', 'network', 'house'];

  /**
   * Allowed booking kinds.
   *
   * @var string[]
   */
  protected $booking_kinds = [];

  /**
   * The slot group.
   *
   * @var string
   */
  protected $group = '';

  /**
   * Whether the slot repeats.
   *
   * @var bool
   */
  protected $repeatable = FALSE;

  /**
   * Network units per provider.
   *
   * @var array
   */
  protected $units = [];

  /**
   * {@inheritdoc}
   */
  public function channel(): string {
    return $this->channel === 'email' ? 'email' : 'web';
  }

  /**
   * {@inheritdoc}
   */
  public function desktopFormat(): string {
    return (string) $this->desktop_format;
  }

  /**
   * {@inheritdoc}
   */
  public function phoneFormat(): string {
    if ($this->channel() === 'email' || (string) $this->phone_format === '') {
      return $this->desktopFormat();
    }
    return (string) $this->phone_format;
  }

  /**
   * {@inheritdoc}
   */
  public function alternativeFormats(): array {
    return array_values(array_map('strval', (array) $this->alternative_formats));
  }

  /**
   * {@inheritdoc}
   */
  public function sources(): array {
    $sources = array_values(array_intersect(self::SOURCES, (array) $this->sources));
    if ($this->channel() === 'email') {
      // No network in email: AdSense, for one, forbids ads in mail.
      $sources = array_values(array_diff($sources, ['network']));
    }
    return $sources;
  }

  /**
   * {@inheritdoc}
   */
  public function allows(string $source): bool {
    return in_array($source, $this->sources(), TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function bookingKinds(): array {
    return array_values(array_map('strval', (array) $this->booking_kinds));
  }

  /**
   * {@inheritdoc}
   */
  public function group(): string {
    return (string) $this->group;
  }

  /**
   * {@inheritdoc}
   */
  public function isRepeatable(): bool {
    return $this->channel() === 'web' && (bool) $this->repeatable;
  }

  /**
   * {@inheritdoc}
   */
  public function units(): array {
    $units = [];
    foreach ((array) $this->units as $provider => $fields) {
      $units[(string) $provider] = array_map('strval', (array) $fields);
    }
    return $units;
  }

  /**
   * {@inheritdoc}
   */
  public static function fillTag(string $slot_id): string {
    return 'ad_slot:' . $slot_id;
  }

  /**
   * {@inheritdoc}
   */
  public function calculateDependencies() {
    parent::calculateDependencies();
    $formats = array_unique(array_filter(array_merge([$this->desktopFormat(), $this->phoneFormat()], $this->alternativeFormats())));
    foreach (AdFormat::loadMultiple($formats) as $format) {
      $this->addDependency('config', $format->getConfigDependencyName());
    }
    foreach (AdProvider::loadMultiple(array_keys($this->units())) as $provider) {
      $this->addDependency('config', $provider->getConfigDependencyName());
    }
    return $this;
  }

  /**
   * {@inheritdoc}
   *
   * A deleted provider takes only its unit with it, and a deleted alternative
   * format only leaves the list; losing the desktop or phone format still
   * deletes the slot.
   */
  public function onDependencyRemoval(array $dependencies) {
    $changed = parent::onDependencyRemoval($dependencies);
    foreach ($dependencies['config'] ?? [] as $dependency) {
      if ($dependency instanceof AdProviderInterface && isset($this->units[$dependency->id()])) {
        unset($this->units[$dependency->id()]);
        $changed = TRUE;
      }
      if ($dependency instanceof AdFormatInterface && in_array($dependency->id(), $this->alternativeFormats(), TRUE)
        && !in_array($dependency->id(), [$this->desktopFormat(), $this->phoneFormat()], TRUE)) {
        $this->alternative_formats = array_values(array_diff($this->alternativeFormats(), [$dependency->id()]));
        $changed = TRUE;
      }
    }
    return $changed;
  }

  /**
   * {@inheritdoc}
   */
  public function postSave(EntityStorageInterface $storage, $update = TRUE): void {
    parent::postSave($storage, $update);
    Cache::invalidateTags([self::fillTag((string) $this->id())]);
    // One block per web slot.
    \Drupal::service('plugin.manager.block')->clearCachedDefinitions();
  }

  /**
   * {@inheritdoc}
   */
  public static function postDelete(EntityStorageInterface $storage, array $entities): void {
    parent::postDelete($storage, $entities);
    \Drupal::service('plugin.manager.block')->clearCachedDefinitions();
  }

}
