<?php

declare(strict_types=1);

namespace Drupal\Tests\ad_inventory\Kernel;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\ad_inventory\Traits\AdInventoryTestTrait;

/**
 * The ad inventory installed, days in UTC, the clock on 2026-10-26 noon.
 */
abstract class AdInventoryKernelTestBase extends KernelTestBase {

  use AdInventoryTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'file',
    'image',
    'media',
    'options',
    'datetime',
    'block',
    'adstxt',
    'ad_inventory',
    'ad_inventory_test',
  ];

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    $container->register('datetime.time', FrozenTime::class);
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    // Set before Drupal starts and never rewound past it, so cache items
    // written during the install are not read as stale.
    $this->at('2026-10-26 12:00');
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('file');
    $this->installEntitySchema('media');
    $this->installEntitySchema('ad_creative');
    $this->installEntitySchema('ad_booking');
    $this->installSchema('file', ['file_usage']);
    $this->installSchema('ad_inventory', ['ad_inventory_count']);
    $this->installConfig(['system', 'field', 'file', 'image', 'media', 'adstxt', 'ad_inventory']);
    $this->config('ad_inventory.settings')->set('timezone', 'UTC')->save();
  }

  /**
   * Sets the clock to a UTC wall-clock time.
   */
  protected function at(string $when): void {
    FrozenTime::$now = (new \DateTimeImmutable($when, new \DateTimeZone('UTC')))->getTimestamp();
  }

}
