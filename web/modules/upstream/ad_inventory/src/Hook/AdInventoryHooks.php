<?php

declare(strict_types=1);

namespace Drupal\ad_inventory\Hook;

use Drupal\ad_inventory\Boundaries;
use Drupal\ad_inventory\Entity\AdProvider;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Hook\Attribute\Hook;

/**
 * Hook implementations for ad_inventory.
 */
final class AdInventoryHooks {

  public function __construct(
    private readonly Boundaries $boundaries,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Implements hook_theme().
   */
  #[Hook('theme')]
  public function theme(): array {
    return [
      'ad_inventory_slot' => [
        'variables' => [
          'slot_id' => '',
          'source' => '',
          'creative_id' => 0,
          'devices' => 'both',
          'draft' => FALSE,
          'desktop' => [],
          'phone' => [],
          'label' => '',
          'blocked_reason' => '',
          'blocked_link' => '',
          'consent_attribute' => '',
          'candidates' => [],
        ],
      ],
      'ad_inventory_picture' => [
        'variables' => [
          'href' => '',
          'alt' => '',
          'desktop' => [],
          'phone' => [],
          'media' => '',
        ],
      ],
      'ad_inventory_email' => [
        'variables' => [
          'label' => '',
          'image' => '',
          'link' => '',
          'width' => 600,
          'height' => 200,
          'alt' => '',
        ],
      ],
    ];
  }

  /**
   * Implements hook_cron().
   *
   * Once per site day: renews pages whose bookings start or end, and stops
   * bookings the site no longer allows.
   */
  #[Hook('cron')]
  public function cron(): void {
    $this->boundaries->run();
  }

  /**
   * Implements hook_adstxt() (contrib adstxt).
   *
   * Adds the seller lines of approved providers to /ads.txt. adstxt answers
   * 404 while the file would be empty, which is what buyers expect from a
   * site with no seller: a file with zero records reads as "nobody may sell".
   */
  #[Hook('adstxt')]
  public function adstxt(CacheableMetadata $cacheable_metadata): array {
    $cacheable_metadata->addCacheTags(['config:ad_provider_list']);
    $lines = [];
    foreach ($this->entityTypeManager->getStorage('ad_provider')->loadByProperties(['status' => TRUE]) as $provider) {
      if ($provider instanceof AdProvider && $provider->isApproved()) {
        foreach ($provider->instance()->adsTxtLines() as $line) {
          if (preg_match('/^[a-z0-9.-]+\.[a-z]{2,}, *[A-Za-z0-9_-]+, *(DIRECT|RESELLER)(, *[A-Za-z0-9]+)?$/', trim($line)) === 1) {
            $lines[] = trim($line);
          }
        }
      }
    }
    return $lines === [] ? [] : [implode("\n", array_unique($lines))];
  }

  /**
   * Implements hook_page_attachments().
   *
   * Site-ownership tags of approved providers go in every page head. They
   * are the same for every reader.
   */
  #[Hook('page_attachments')]
  public function pageAttachments(array &$attachments): void {
    $providers = $this->entityTypeManager->getStorage('ad_provider')->loadByProperties(['status' => TRUE]);
    $attachments['#cache']['tags'][] = 'config:ad_provider_list';
    foreach ($providers as $provider) {
      if (!$provider instanceof AdProvider || !$provider->isApproved()) {
        continue;
      }
      $tag = $provider->instance()->verificationTag();
      if (preg_match('/^<meta name="([a-z0-9_.:-]+)" content="([A-Za-z0-9_.:\/-]+)">$/', $tag, $m) === 1) {
        $attachments['#attached']['html_head'][] = [
          ['#tag' => 'meta', '#attributes' => ['name' => $m[1], 'content' => $m[2]]],
          'ad_inventory_verify_' . $provider->id(),
        ];
      }
    }
  }

}
