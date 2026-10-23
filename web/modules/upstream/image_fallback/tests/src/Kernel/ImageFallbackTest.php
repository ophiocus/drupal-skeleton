<?php

declare(strict_types=1);

namespace Drupal\Tests\image_fallback\Kernel;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\file\Entity\File;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * An empty listed field renders its fallback; nothing else changes.
 */
#[Group('image_fallback')]
#[RunTestsInSeparateProcesses]
final class ImageFallbackTest extends KernelTestBase {

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
    'entity_test',
    'image_fallback',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('file');
    $this->installEntitySchema('entity_test');
    $this->installSchema('file', ['file_usage']);
    $this->installConfig(['system', 'image', 'media', 'image_fallback']);
    // A theme whose field template prints the field's classes, as a site's
    // front-end theme does.
    $this->container->get('theme_installer')->install(['olivero']);
    $this->config('system.theme')->set('default', 'olivero')->save();
    // Olivero ships with its logo off; a site's theme shows one.
    $this->config('olivero.settings')->set('logo.use_default', TRUE)->save();

    $display = $this->container->get('entity_display.repository')->getViewDisplay('entity_test', 'entity_test');
    $fields = [
      'field_image' => ['image', [], 'image'],
      'field_other' => ['image', [], 'image'],
      'field_media' => ['entity_reference', ['target_type' => 'media'], 'media_thumbnail'],
    ];
    $weight = 0;
    foreach ($fields as $name => [$type, $storage_settings, $formatter]) {
      FieldStorageConfig::create([
        'entity_type' => 'entity_test',
        'field_name' => $name,
        'type' => $type,
        'settings' => $storage_settings,
      ])->save();
      FieldConfig::create([
        'entity_type' => 'entity_test',
        'bundle' => 'entity_test',
        'field_name' => $name,
        'label' => $name,
      ])->save();
      $display->setComponent($name, [
        'type' => $formatter,
        'label' => 'hidden',
        'weight' => $weight++,
        'settings' => ['image_style' => '', 'image_link' => ''],
      ]);
    }
    $display->save();

    $this->fields([['field' => 'entity_test.entity_test.field_image']]);
  }

  /**
   * Saves the default fallback, merged over the current one.
   */
  private function default(array $values): void {
    $config = $this->config('image_fallback.settings');
    $config->set('default', $values + $config->get('default'))->save();
  }

  /**
   * Saves the listed fields; a missing source, path or alt is empty.
   */
  private function fields(array $fields): void {
    $fields = array_map(static fn (array $field): array => $field + ['source' => '', 'path' => '', 'alt' => ''], $fields);
    $this->config('image_fallback.settings')->set('fields', $fields)->save();
  }

  /**
   * Renders a build; returns its HTML and its cache tags.
   *
   * @return array{0: string, 1: string[]}
   *   The markup and the cache tags of the render array.
   */
  private function renderBuild(array $build): array {
    $html = (string) $this->container->get('renderer')->renderInIsolation($build);
    $this->setRawContent($html);
    return [$html, CacheableMetadata::createFromRenderArray($build)->getCacheTags()];
  }

  /**
   * Renders an entity in its default view mode.
   *
   * @return array{0: string, 1: string[]}
   *   The markup and the cache tags of the render array.
   */
  private function renderEntity(EntityTest $entity): array {
    return $this->renderBuild($this->container->get('entity_type.manager')->getViewBuilder('entity_test')->view($entity));
  }

  /**
   * An entity with every field empty.
   */
  private function emptyEntity(): EntityTest {
    $entity = EntityTest::create(['name' => 'empty']);
    $entity->save();
    return $entity;
  }

  /**
   * The default of defaults: the default theme's logo, in the field's wrapper.
   */
  public function testLogo(): void {
    [, $tags] = $this->renderEntity($this->emptyEntity());
    $this->assertCount(1, $this->cssSelect('.field--name-field-image.field--type-image.image-fallback'));
    $img = $this->cssSelect('.image-fallback img');
    $this->assertCount(1, $img);
    $this->assertStringEndsWith('/core/themes/olivero/logo.svg', (string) $img[0]['src']);
    $this->assertSame('', (string) $img[0]['alt']);
    $this->assertContains('config:image_fallback.settings', $tags);
    $this->assertContains('config:olivero.settings', $tags);
    $this->assertContains('config:system.theme.global', $tags);

    // A logo of the site's own, through the theme's settings.
    $this->config('olivero.settings')
      ->set('logo.use_default', FALSE)
      ->set('logo.path', 'core/misc/druplicon.png')
      ->save();
    $this->default(['alt' => 'Example shop']);
    $this->renderEntity($this->emptyEntity());
    $img = $this->cssSelect('.image-fallback img');
    $this->assertStringEndsWith('/core/misc/druplicon.png', (string) $img[0]['src']);
    $this->assertSame('Example shop', (string) $img[0]['alt']);

    // A theme that shows no logo (Olivero's own setting) has none to lend.
    $this->config('olivero.settings')->set('logo.use_default', FALSE)->set('logo.path', '')->save();
    [, $tags] = $this->renderEntity($this->emptyEntity());
    $this->assertCount(0, $this->cssSelect('.image-fallback'));
    $this->assertContains('config:olivero.settings', $tags);
  }

  /**
   * The path source: a file relative to the Drupal root.
   *
   * A path that does not exist shows nothing.
   */
  public function testPath(): void {
    $this->default(['source' => 'path', 'path' => 'core/misc/druplicon.png']);
    [, $tags] = $this->renderEntity($this->emptyEntity());
    $img = $this->cssSelect('.field--name-field-image.image-fallback img');
    $this->assertCount(1, $img);
    $this->assertStringEndsWith('/core/misc/druplicon.png', (string) $img[0]['src']);
    $this->assertContains('config:image_fallback.settings', $tags);
    $this->assertNotContains('config:olivero.settings', $tags);

    $this->default(['path' => 'core/misc/no-such-file.png']);
    [, $tags] = $this->renderEntity($this->emptyEntity());
    $this->assertCount(0, $this->cssSelect('.image-fallback'));
    $this->assertContains('config:image_fallback.settings', $tags);
  }

  /**
   * The none source renders nothing.
   *
   * The entity still carries the tag, so choosing a source later reaches it.
   */
  public function testNone(): void {
    $this->default(['source' => 'none']);
    [, $tags] = $this->renderEntity($this->emptyEntity());
    $this->assertCount(0, $this->cssSelect('.image-fallback'));
    $this->assertCount(0, $this->cssSelect('img'));
    $this->assertContains('config:image_fallback.settings', $tags);
  }

  /**
   * Each field may name its own source, or take the default.
   *
   * A media reference field works as an image field does.
   */
  public function testPerField(): void {
    $this->fields([
      ['field' => 'entity_test.entity_test.field_image'],
      [
        'field' => 'entity_test.entity_test.field_media',
        'source' => 'path',
        'path' => 'core/misc/druplicon.png',
        'alt' => 'No photo yet',
      ],
    ]);
    $this->renderEntity($this->emptyEntity());
    $image = $this->cssSelect('.field--name-field-image.image-fallback img');
    $this->assertStringEndsWith('/core/themes/olivero/logo.svg', (string) $image[0]['src']);
    $media = $this->cssSelect('.field--name-field-media.field--type-entity-reference.image-fallback img');
    $this->assertCount(1, $media);
    $this->assertStringEndsWith('/core/misc/druplicon.png', (string) $media[0]['src']);
    $this->assertSame('No photo yet', (string) $media[0]['alt']);

    // A field that turns its fallback off, while the default stays the logo.
    $this->fields([['field' => 'entity_test.entity_test.field_image', 'source' => 'none']]);
    $this->renderEntity($this->emptyEntity());
    $this->assertCount(0, $this->cssSelect('.image-fallback'));
  }

  /**
   * A field rendered on its own gets the fallback too.
   *
   * That is how a Views field and a Layout Builder field block render.
   */
  public function testSingleField(): void {
    $build = $this->emptyEntity()->get('field_image')->view(['type' => 'image', 'label' => 'hidden']);
    [, $tags] = $this->renderBuild($build);
    $this->assertCount(1, $this->cssSelect('.field--name-field-image.image-fallback img'));
    $this->assertContains('config:image_fallback.settings', $tags);
  }

  /**
   * A listed field with an image keeps it, and the entity gets no tag.
   */
  public function testNonEmptyFieldLeftAlone(): void {
    \Drupal::service('file_system')->copy('core/tests/fixtures/files/image-1.png', 'public://image-1.png');
    $file = File::create(['uri' => 'public://image-1.png']);
    $file->save();
    $entity = EntityTest::create([
      'name' => 'with image',
      'field_image' => ['target_id' => $file->id(), 'alt' => 'A real photo'],
    ]);
    $entity->save();

    [, $tags] = $this->renderEntity($entity);
    $this->assertCount(0, $this->cssSelect('.image-fallback'));
    $img = $this->cssSelect('.field--name-field-image img');
    $this->assertCount(1, $img);
    $this->assertStringContainsString('image-1.png', (string) $img[0]['src']);
    $this->assertSame('A real photo', (string) $img[0]['alt']);
    $this->assertNotContains('config:image_fallback.settings', $tags);
  }

  /**
   * An empty field that is not listed stays empty.
   */
  public function testUnlistedFieldLeftAlone(): void {
    $this->renderEntity($this->emptyEntity());
    $this->assertCount(1, $this->cssSelect('.image-fallback'));
    $this->assertCount(0, $this->cssSelect('.field--name-field-other'));
    $this->assertCount(0, $this->cssSelect('.field--name-field-media'));

    // No field listed at all (the module's default): nothing changes and no
    // entity gets the tag.
    $this->fields([]);
    [, $tags] = $this->renderEntity($this->emptyEntity());
    $this->assertCount(0, $this->cssSelect('.image-fallback'));
    $this->assertNotContains('config:image_fallback.settings', $tags);
  }

  /**
   * Listing a field invalidates its entity type's renders; other saves don't.
   */
  public function testListingFieldInvalidatesRenders(): void {
    $checksum = $this->container->get('cache_tags.invalidator.checksum');
    $before = $checksum->getCurrentChecksum(['entity_test_view']);
    $this->default(['alt' => 'Changed']);
    $this->fields([['field' => 'entity_test.entity_test.field_image', 'alt' => 'Changed too']]);
    $this->assertSame($before, $checksum->getCurrentChecksum(['entity_test_view']));
    $this->fields([
      ['field' => 'entity_test.entity_test.field_image'],
      ['field' => 'entity_test.entity_test.field_other'],
    ]);
    $this->assertGreaterThan($before, $checksum->getCurrentChecksum(['entity_test_view']));
  }

}
