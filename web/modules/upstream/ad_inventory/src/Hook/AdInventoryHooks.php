<?php

declare(strict_types=1);

namespace Drupal\ad_inventory\Hook;

use Drupal\ad_inventory\Inventory;
use Drupal\ad_inventory\Overrides;
use Drupal\ad_inventory\Scheduler;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Cache\RefinableCacheableDependencyInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Session\AccountInterface;

/**
 * Hook implementations for ad_inventory.
 */
final class AdInventoryHooks {

  public function __construct(
    private readonly Inventory $inventory,
    private readonly Scheduler $scheduler,
  ) {}

  /**
   * Implements hook_theme().
   */
  #[Hook('theme')]
  public function theme(): array {
    return [
      'ad_slot' => [
        'variables' => [
          'slot' => '',
          'label' => '',
          'blocked_text' => '',
          'blocked_link_text' => '',
          'blocked_link_url' => '',
          'entries' => [],
          'attributes' => [],
        ],
      ],
      'ad_creative' => [
        'variables' => [
          'href' => '',
          'alt' => '',
          'desktop' => [],
          'phone' => NULL,
        ],
      ],
    ];
  }

  /**
   * Implements hook_cron().
   *
   * Once a day: invalidates the slots whose bookings started or ended, and
   * re-validates running bookings. Prunes old placement records.
   */
  #[Hook('cron')]
  public function cron(): void {
    $this->scheduler->run();
  }

  /**
   * Implements hook_entity_field_access().
   *
   * Per-page slot controls are edited only with "use ad inventory
   * overrides"; a site grants it to staff, or also to a page's holder.
   */
  #[Hook('entity_field_access')]
  public function fieldAccess(string $operation, FieldDefinitionInterface $field_definition, AccountInterface $account, ?FieldItemListInterface $items = NULL): AccessResultInterface {
    if ($field_definition->getType() !== Overrides::FIELD_TYPE || $operation !== 'edit') {
      return AccessResult::neutral();
    }
    return AccessResult::forbiddenIf(!$account->hasPermission('use ad inventory overrides'))->cachePerPermissions();
  }

  /**
   * Implements hook_page_attachments().
   *
   * A provider's site verification tag, the same for every reader.
   */
  #[Hook('page_attachments')]
  public function pageAttachments(array &$attachments): void {
    $attachments['#cache']['tags'][] = 'config:ad_provider_list';
    foreach ($this->inventory->providers() as $id => $provider) {
      $tag = $provider->plugin()?->verificationTag();
      if ($tag !== NULL) {
        $attachments['#attached']['html_head'][] = [$tag, 'ad_inventory_verify_' . $id];
      }
    }
  }

  /**
   * Implements hook_adstxt().
   *
   * The ads.txt lines every enabled provider declares, after what editors
   * wrote in the ads.txt admin page.
   */
  #[Hook('adstxt')]
  public function adsTxt(RefinableCacheableDependencyInterface $metadata): array {
    $metadata->addCacheTags(['config:ad_provider_list']);
    $lines = [];
    foreach ($this->inventory->providers() as $provider) {
      foreach ($provider->plugin()?->adsTxtLines() ?? [] as $line) {
        $lines[] = $line;
      }
    }
    return array_values(array_unique($lines));
  }

}
