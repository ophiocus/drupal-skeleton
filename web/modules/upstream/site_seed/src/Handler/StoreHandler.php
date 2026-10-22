<?php

declare(strict_types=1);

namespace Drupal\site_seed\Handler;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\site_seed\SeedContext;

/**
 * Section `stores:`, Commerce stores matched by name (or a former name).
 *
 * The default store is born under the uuid config/sync names in
 * commerce_store.settings, so a fresh build and its config agree and no
 * config import points the default at a store that does not exist.
 */
final class StoreHandler implements SeedHandlerInterface {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function section(): string {
    return 'stores';
  }

  /**
   * {@inheritdoc}
   */
  public function weight(): int {
    return 0;
  }

  /**
   * {@inheritdoc}
   */
  public function applies(): bool {
    return $this->entityTypeManager->hasDefinition('commerce_store');
  }

  /**
   * {@inheritdoc}
   */
  public function converge(array $rows, SeedContext $context): void {
    $storage = $this->entityTypeManager->getStorage('commerce_store');
    $by_name = [];
    foreach ($storage->loadMultiple() as $store) {
      if ($store instanceof ContentEntityInterface) {
        $by_name[(string) $store->label()] = $store;
      }
    }

    foreach ($rows as $row) {
      $store = SeedContext::match($by_name, $row);
      if (!$store) {
        $values = [
          'type' => $row['type'] ?? 'online',
          'uid' => 1,
          'name' => $row['name'],
          'mail' => $row['mail'] ?? '',
          'default_currency' => $row['currency'] ?? 'COP',
          'timezone' => $row['timezone'] ?? 'America/Bogota',
          'address' => $row['address'] ?? ['country_code' => 'CO'],
          'billing_countries' => $row['billing_countries'] ?? ['CO'],
        ];
        $configured = $this->configFactory->get('commerce_store.settings')->get('default_store');
        if (!empty($row['default']) && is_string($configured) && $configured !== ''
          && !$storage->loadByProperties(['uuid' => $configured])) {
          $values['uuid'] = $configured;
        }
        $store = $storage->create($values);
        assert($store instanceof ContentEntityInterface);
        $context->save($store);
        $by_name[$row['name']] = $store;
        $context->count('stores');
      }
      else {
        $dirty = FALSE;
        if ((string) $store->label() !== $row['name']) {
          unset($by_name[(string) $store->label()]);
          $store->set('name', $row['name']);
          $by_name[$row['name']] = $store;
          $dirty = TRUE;
        }
        if (isset($row['mail']) && (string) $store->get('mail')->value !== $row['mail']) {
          $store->set('mail', $row['mail']);
          $dirty = TRUE;
        }
        // Only the keys the seed names are compared, so a field the seed
        // leaves out (a postal code, say) is not erased by a re-seed.
        if (isset($row['address'])) {
          $have = $store->get('address')->first()?->toArray() ?? [];
          $want = array_merge($have, $row['address']);
          if (array_diff_assoc(array_map('strval', $want), array_map('strval', $have))) {
            $store->set('address', $want);
            $dirty = TRUE;
          }
        }
        if ($dirty) {
          $context->save($store);
          $context->count('updated');
        }
      }

      if (!empty($row['default'])) {
        $settings = $this->configFactory->getEditable('commerce_store.settings');
        if ($settings->get('default_store') !== $store->uuid() && $context->writes()) {
          $settings->set('default_store', $store->uuid())->save();
        }
      }
    }
    $context->stores = $by_name;
  }

}
