<?php

declare(strict_types=1);

namespace Drupal\Tests\edge_purge\Unit;

use Drupal\Core\Site\Settings;
use Drupal\edge_purge\RenderFingerprint;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Which files count as render code, and which changes are seen.
 */
#[Group('edge_purge')]
final class RenderFingerprintTest extends UnitTestCase {

  /**
   * A scratch Drupal root.
   */
  private string $drupalRoot;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    new Settings([]);
    $base = sys_get_temp_dir() . '/edge_purge_' . bin2hex(random_bytes(4));
    $this->drupalRoot = $base . '/web';
    $files = [
      'web/themes/custom/t/templates/page.html.twig' => 'page',
      'web/themes/custom/t/css/base.css' => 'css',
      'web/themes/custom/t/README.md' => 'doc',
      'web/modules/custom/m/m.module' => 'module',
      'web/modules/custom/m/src/Block.php' => 'block',
      'web/modules/custom/m/src/Drush/Commands/C.php' => 'drush',
      'web/modules/custom/m/tests/src/Unit/T.php' => 'test',
      'web/modules/custom/m/config/install/m.settings.yml' => 'config',
      'web/modules/custom/m/m.install' => 'seed',
      'web/modules/custom/m/m.routing.yml' => 'routes',
      'web/modules/custom/m/m.libraries.yml' => 'libraries',
      'web/modules/custom/m/src/StackMiddleware/Roll.php' => 'middleware',
      'web/modules/custom/m/src/Cache/Tags.php' => 'tags',
      'web/modules/custom/m/src/Controller/SitemapController.php' => 'sitemap',
      'composer.lock' => 'lock',
    ];
    foreach ($files as $path => $body) {
      @mkdir(dirname($base . '/' . $path), 0777, TRUE);
      file_put_contents($base . '/' . $path, $body);
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    $base = dirname($this->drupalRoot);
    $iterator = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS),
      \RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $file) {
      $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($base);
    parent::tearDown();
  }

  /**
   * Templates, CSS, code and the lock count; docs, Drush, tests, config not.
   */
  public function testCountedFiles(): void {
    $this->assertSame([
      '../composer.lock',
      'modules/custom/m/m.libraries.yml',
      'modules/custom/m/m.module',
      'modules/custom/m/src/Block.php',
      'modules/custom/m/src/Cache/Tags.php',
      'modules/custom/m/src/Controller/SitemapController.php',
      'themes/custom/t/css/base.css',
      'themes/custom/t/templates/page.html.twig',
    ], array_keys(RenderFingerprint::files($this->drupalRoot)));

    // A site adds its own names never counted.
    new Settings(['edge_purge_render_skip' => ['SitemapController.php']]);
    $this->assertArrayNotHasKey('modules/custom/m/src/Controller/SitemapController.php', RenderFingerprint::files($this->drupalRoot));
  }

  /**
   * Narrowing the rules is not a change: a file now left out never purges.
   */
  public function testNarrowedRulesAreNoChange(): void {
    $before = RenderFingerprint::files($this->drupalRoot);
    $before['modules/custom/m/m.install'] = 'hash-from-older-rules';
    $before['modules/custom/m/src/Drush/Commands/C.php'] = 'hash-from-older-rules';
    $this->assertSame([], RenderFingerprint::changed($before, RenderFingerprint::files($this->drupalRoot)));
  }

  /**
   * A change, an addition and a removal are each seen; a doc edit is not.
   */
  public function testChanged(): void {
    $before = RenderFingerprint::files($this->drupalRoot);
    file_put_contents($this->drupalRoot . '/themes/custom/t/README.md', 'new doc');
    $this->assertSame([], RenderFingerprint::changed($before, RenderFingerprint::files($this->drupalRoot)));

    file_put_contents($this->drupalRoot . '/themes/custom/t/css/base.css', 'new css');
    unlink($this->drupalRoot . '/modules/custom/m/m.module');
    file_put_contents($this->drupalRoot . '/modules/custom/m/m.theme', 'new');
    $this->assertSame([
      'modules/custom/m/m.module',
      'modules/custom/m/m.theme',
      'themes/custom/t/css/base.css',
    ], RenderFingerprint::changed($before, RenderFingerprint::files($this->drupalRoot)));
  }

  /**
   * A module moved unchanged from custom to upstream is no change.
   */
  public function testMovedBetweenRootsIsNoChange(): void {
    $before = RenderFingerprint::files($this->drupalRoot);
    $base = $this->drupalRoot . '/modules';
    @mkdir($base . '/upstream', 0777, TRUE);
    rename($base . '/custom/m', $base . '/upstream/m');
    $this->assertArrayHasKey('modules/upstream/m/m.module', RenderFingerprint::files($this->drupalRoot));
    $this->assertSame([], RenderFingerprint::changed($before, RenderFingerprint::files($this->drupalRoot)));

    // Moved AND edited is a change, seen under both names.
    file_put_contents($base . '/upstream/m/m.module', 'edited');
    $this->assertSame([
      'modules/custom/m/m.module',
      'modules/upstream/m/m.module',
    ], RenderFingerprint::changed($before, RenderFingerprint::files($this->drupalRoot)));
  }

}
