<?php

declare(strict_types=1);

namespace Drupal\ad_inventory\Entity;

use Drupal\ad_inventory\Plugin\AdProviderInterface;
use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\Core\Entity\Attribute\ConfigEntityType;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * One configured ad provider: a plugin instance with its settings.
 *
 * A site keeps an ordered list of these. A provider in draft fills spaces
 * only for staff who preview drafts; an approved one fills them for every
 * reader who granted advertising consent.
 */
#[ConfigEntityType(
  id: 'ad_provider',
  label: new TranslatableMarkup('Ad provider'),
  label_collection: new TranslatableMarkup('Ad providers'),
  config_prefix: 'provider',
  entity_keys: [
    'id' => 'id',
    'label' => 'label',
    'weight' => 'weight',
  ],
  handlers: [
    'list_builder' => 'Drupal\ad_inventory\ListBuilder\AdProviderListBuilder',
    'form' => [
      'default' => 'Drupal\ad_inventory\Form\AdProviderForm',
      'delete' => 'Drupal\Core\Entity\EntityDeleteForm',
    ],
    'route_provider' => [
      'html' => 'Drupal\Core\Entity\Routing\AdminHtmlRouteProvider',
    ],
  ],
  links: [
    'collection' => '/admin/structure/ad-inventory/providers',
    'add-form' => '/admin/structure/ad-inventory/providers/add',
    'edit-form' => '/admin/structure/ad-inventory/providers/{ad_provider}',
    'delete-form' => '/admin/structure/ad-inventory/providers/{ad_provider}/delete',
  ],
  admin_permission: 'administer ad inventory',
  config_export: ['id', 'label', 'plugin', 'settings', 'slots', 'state', 'weight'],
)]
final class AdProvider extends ConfigEntityBase {

  /**
   * Fills spaces only for staff who preview drafts.
   */
  public const DRAFT = 'draft';

  /**
   * Fills spaces for readers.
   */
  public const APPROVED = 'approved';

  /**
   * Machine name.
   */
  protected string $id;

  /**
   * Human name.
   */
  protected string $label;

  /**
   * Plugin id.
   */
  protected string $plugin = '';

  /**
   * Plugin settings.
   *
   * @var array<string, mixed>
   */
  protected array $settings = [];

  /**
   * Space ids this provider may fill.
   *
   * Empty means every space that accepts providers.
   *
   * @var string[]
   */
  protected array $slots = [];

  /**
   * The state: 'draft' or 'approved'.
   */
  protected string $state = self::DRAFT;

  /**
   * Order among providers: the first that fills a space wins.
   */
  protected int $weight = 0;

  /**
   * Plugin id.
   */
  public function pluginId(): string {
    return $this->plugin;
  }

  /**
   * Plugin settings.
   *
   * @return array<string, mixed>
   *   Settings.
   */
  public function settings(): array {
    return $this->settings;
  }

  /**
   * Whether this provider may fill a space.
   */
  public function covers(string $slot_id): bool {
    return $this->slots === [] || in_array($slot_id, $this->slots, TRUE);
  }

  /**
   * The state: 'draft' or 'approved'.
   */
  public function state(): string {
    return $this->state;
  }

  /**
   * Whether readers see it.
   */
  public function isApproved(): bool {
    return $this->status() && $this->state === self::APPROVED;
  }

  /**
   * Weight.
   */
  public function getWeight(): int {
    return $this->weight;
  }

  /**
   * The plugin instance, configured with this provider's settings.
   */
  public function instance(): AdProviderInterface {
    /** @var \Drupal\ad_inventory\Plugin\AdProviderPluginManager $manager */
    $manager = \Drupal::service('plugin.manager.ad_inventory.provider');
    /** @var \Drupal\ad_inventory\Plugin\AdProviderInterface $plugin */
    $plugin = $manager->createInstance($this->plugin, $this->settings + ['provider_id' => $this->id()]);
    return $plugin;
  }

}
