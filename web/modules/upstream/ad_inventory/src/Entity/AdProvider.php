<?php

declare(strict_types=1);

namespace Drupal\ad_inventory\Entity;

use Drupal\ad_inventory\AdInventoryException;
use Drupal\ad_inventory\Access\AdProviderAccessControlHandler;
use Drupal\ad_inventory\Form\AdProviderForm;
use Drupal\ad_inventory\ListBuilder\AdProviderListBuilder;
use Drupal\ad_inventory\Plugin\AdProvider\AdProviderPluginInterface;
use Drupal\ad_inventory\ProviderPolicy;
use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\Core\Entity\Attribute\ConfigEntityType;
use Drupal\Core\Entity\EntityDeleteForm;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\Routing\AdminHtmlRouteProvider;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * A network provider instance, in fill order.
 *
 * Saving an enabled provider is refused when it uses a format another
 * enabled provider forbids site-wide, or forbids one another uses (a
 * pop-under beside AdSense).
 */
#[ConfigEntityType(
  id: 'ad_provider',
  label: new TranslatableMarkup('Ad network provider'),
  label_collection: new TranslatableMarkup('Ad network providers'),
  label_singular: new TranslatableMarkup('ad network provider'),
  label_plural: new TranslatableMarkup('ad network providers'),
  config_prefix: 'provider',
  entity_keys: [
    'id' => 'id',
    'label' => 'label',
    'weight' => 'weight',
    'status' => 'status',
  ],
  handlers: [
    'access' => AdProviderAccessControlHandler::class,
    'list_builder' => AdProviderListBuilder::class,
    'form' => [
      'add' => AdProviderForm::class,
      'edit' => AdProviderForm::class,
      'delete' => EntityDeleteForm::class,
    ],
    'route_provider' => [
      'html' => AdminHtmlRouteProvider::class,
    ],
  ],
  links: [
    'collection' => '/admin/structure/ad-inventory/providers',
    'add-form' => '/admin/structure/ad-inventory/providers/add',
    'edit-form' => '/admin/structure/ad-inventory/providers/{ad_provider}',
    'delete-form' => '/admin/structure/ad-inventory/providers/{ad_provider}/delete',
  ],
  admin_permission: 'administer ad inventory',
  label_count: [
    'singular' => '@count ad network provider',
    'plural' => '@count ad network providers',
  ],
  config_export: [
    'id',
    'label',
    'plugin',
    'weight',
    'settings',
  ],
)]
class AdProvider extends ConfigEntityBase implements AdProviderInterface {

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
   * The plugin id.
   *
   * @var string
   */
  protected $plugin = '';

  /**
   * The place in fill order.
   *
   * @var int
   */
  protected $weight = 0;

  /**
   * The plugin settings.
   *
   * @var array
   */
  protected $settings = [];

  /**
   * {@inheritdoc}
   */
  public function pluginId(): string {
    return (string) $this->plugin;
  }

  /**
   * {@inheritdoc}
   */
  public function weight(): int {
    return (int) $this->weight;
  }

  /**
   * {@inheritdoc}
   */
  public function settings(): array {
    return (array) $this->settings;
  }

  /**
   * {@inheritdoc}
   */
  public function plugin(): ?AdProviderPluginInterface {
    $manager = \Drupal::service('plugin.manager.ad_provider');
    if (!$manager->hasDefinition($this->pluginId())) {
      return NULL;
    }
    $plugin = $manager->createInstance($this->pluginId(), [
      'provider_id' => (string) $this->id(),
      'settings' => $this->settings(),
    ]);
    return $plugin instanceof AdProviderPluginInterface ? $plugin : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function isRestricted(): bool {
    $manager = \Drupal::service('plugin.manager.ad_provider');
    if (!$manager->hasDefinition($this->pluginId())) {
      return TRUE;
    }
    return (bool) ($manager->getDefinition($this->pluginId())['restricted'] ?? FALSE);
  }

  /**
   * {@inheritdoc}
   *
   * @throws \Drupal\ad_inventory\AdInventoryException
   *   When the provider's plugin is missing, or its formats exclude another
   *   enabled provider's (or the other way round).
   */
  public function preSave(EntityStorageInterface $storage): void {
    parent::preSave($storage);
    $plugin = $this->plugin();
    if ($plugin === NULL) {
      throw new AdInventoryException(sprintf('The ad provider plugin "%s" does not exist.', $this->pluginId()));
    }
    if (!$this->status()) {
      return;
    }
    $described = [];
    foreach ($storage->loadMultiple() as $other) {
      if ($other instanceof AdProviderInterface && $other->id() !== $this->id() && $other->status() && ($other_plugin = $other->plugin())) {
        $described[] = ProviderPolicy::describe((string) $other->id(), (string) $other->label(), $other_plugin);
      }
    }
    $described[] = ProviderPolicy::describe((string) $this->id(), (string) $this->label(), $plugin);
    $conflicts = ProviderPolicy::conflicts($described, (string) $this->id());
    if ($conflicts) {
      throw new AdInventoryException(implode(' ', $conflicts));
    }
  }

}
