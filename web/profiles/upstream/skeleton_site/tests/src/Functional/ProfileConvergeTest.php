<?php

declare(strict_types=1);

namespace Drupal\Tests\skeleton_site\Functional;

use Drupal\Core\Config\ConfigImporterException;
use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * A deployed site moves from standard to skeleton_site without an update hook.
 *
 * Config import refuses a profile change, so
 * scripts/upstream/profile-converge.php makes the switch before it;
 * afterwards config/sync imports cleanly.
 */
#[Group('skeleton_site')]
#[RunTestsInSeparateProcesses]
class ProfileConvergeTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected $profile = 'standard';

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Runs the converge script as `drush php:script` would.
   *
   * @param string[] $extra
   *   The script's arguments.
   *
   * @return string
   *   What it printed.
   */
  protected function converge(array $extra = []): string {
    ob_start();
    try {
      (static function (array $extra): void {
        include DRUPAL_ROOT . '/../scripts/upstream/profile-converge.php';
      })($extra);
    }
    finally {
      $output = ob_get_clean();
    }
    // The script rebuilt the container: drop every service held before it.
    $this->resetAll();
    $this->configImporter = NULL;
    return $output;
  }

  /**
   * Standard to skeleton_site, then a config import with nothing to change.
   */
  public function testStandardToSkeletonSite(): void {
    $this->assertSame('standard', \Drupal::installProfile());
    $this->syncNamesSkeletonSite();
    $sync = $this->container->get('config.storage.sync');

    // Core refuses it through config import.
    $importer = $this->configImporter();
    try {
      $importer->import();
      $this->fail('Config import changed the install profile.');
    }
    catch (ConfigImporterException) {
      $this->assertContains('Cannot change the install profile from <em class="placeholder">standard</em> to <em class="placeholder">skeleton_site</em> once Drupal is installed.', array_map('strval', $importer->getErrors()));
    }

    // --check says what it would do and does nothing.
    $this->assertSame("profile: standard -> skeleton_site would be converged; --check changes nothing\n", $this->converge(['--check']));
    $this->assertSame('standard', \Drupal::installProfile());

    $this->assertSame("profile: standard -> skeleton_site\n", $this->converge());
    $this->assertSame('skeleton_site', \Drupal::installProfile());
    $modules = \Drupal::config('core.extension')->get('module');
    $this->assertArrayNotHasKey('standard', $modules);
    $this->assertSame(1000, $modules['skeleton_site']);
    $this->assertSame('skeleton_site', array_key_last($modules));

    // What standard installed stays installed; only the profile changed.
    foreach (['node', 'block', 'ckeditor5', 'views', 'olivero', 'claro'] as $extension_name) {
      $this->assertTrue(\Drupal::moduleHandler()->moduleExists($extension_name) || \Drupal::service('theme_handler')->themeExists($extension_name), "$extension_name is still installed.");
    }

    // Now the sync imports, and the active config is the sync.
    $this->configImporter()->import();
    $this->assertSame([], array_filter($this->configImporter()->getStorageComparer()->getChangelist()));
    $this->assertEquals($sync->read('core.extension'), $this->container->get('config.storage')->read('core.extension'));

    // A second run is a no-op, and the site still serves pages.
    $this->assertSame("profile: skeleton_site, as config/sync names it; nothing to do\n", $this->converge());
    $this->drupalGet('<front>');
    $this->assertSession()->statusCodeEquals(200);
  }

  /**
   * Writes the site's config/sync after its change.
   *
   * The new profile at the weight the installer gives a profile, and no
   * standard; everything else as the site has it.
   */
  protected function syncNamesSkeletonSite(): void {
    $sync = $this->container->get('config.storage.sync');
    $this->copyConfig($this->container->get('config.storage'), $sync);
    $extension = $sync->read('core.extension');
    unset($extension['module']['standard']);
    $extension['module']['skeleton_site'] = 1000;
    $extension['profile'] = 'skeleton_site';
    $sync->write('core.extension', $extension);
  }

  /**
   * It refuses, changing nothing, when the switch would lose or break anything.
   */
  public function testRefusals(): void {
    // Config that depends on the profile it leaves would be deleted with it.
    $role = $this->container->get('entity_type.manager')->getStorage('user_role')->load('content_editor');
    $role->set('dependencies', ['enforced' => ['module' => ['standard']]])->save();
    $this->syncNamesSkeletonSite();
    try {
      $this->converge();
      $this->fail('The converge uninstalled a profile that config depends on.');
    }
    catch (\RuntimeException $e) {
      $this->assertStringStartsWith("profile: config depends on 'standard' and would be deleted with it: ", $e->getMessage());
      $this->assertStringContainsString('user.role.content_editor', $e->getMessage());
    }
    $this->assertSame('standard', \Drupal::installProfile());
    $this->assertNotNull(\Drupal::entityTypeManager()->getStorage('user_role')->load('content_editor'));

    // A module the database has installed and the codebase lacks: the switch
    // rebuilds the container, which would fail halfway.
    $role->set('dependencies', [])->save();
    $extension = \Drupal::configFactory()->getEditable('core.extension');
    $extension->set('module.code_not_deployed', 0)->save();
    try {
      $this->converge();
      $this->fail('The converge ran on a codebase that lacks an installed module.');
    }
    catch (\RuntimeException $e) {
      $this->assertSame('profile: modules installed on this site are not in this codebase: code_not_deployed. Deploy the code they need first.', $e->getMessage());
    }
    \Drupal::configFactory()->getEditable('core.extension')->clear('module.code_not_deployed')->save();

    // A profile that ships config would install it over the site's own
    // (tests/profiles/skeleton_site_ships_config).
    $sync = $this->container->get('config.storage.sync');
    $extension = $sync->read('core.extension');
    unset($extension['module']['skeleton_site']);
    $extension['module']['skeleton_site_ships_config'] = 1000;
    $extension['profile'] = 'skeleton_site_ships_config';
    $sync->write('core.extension', $extension);
    try {
      $this->converge();
      $this->fail('The converge installed a profile that ships config.');
    }
    catch (\RuntimeException $e) {
      $this->assertSame("profile: 'skeleton_site_ships_config' ships config in config/install, which would collide with this site's config.", $e->getMessage());
    }

    $this->assertSame('standard', \Drupal::installProfile());
    $this->assertArrayHasKey('standard', \Drupal::config('core.extension')->get('module'));
  }

  /**
   * A run that stopped after naming the new profile is finished by the next.
   */
  public function testFinishesHalfDoneSwitch(): void {
    $this->syncNamesSkeletonSite();
    // What a run that died after its first two steps leaves behind.
    $this->container->get('module_installer')->uninstall(['standard'], FALSE);
    \Drupal::configFactory()->getEditable('core.extension')->set('profile', 'skeleton_site')->save();
    $this->resetAll();

    $this->assertSame("profile: skeleton_site (named, not installed) -> skeleton_site\n", $this->converge());
    $this->assertSame(1000, \Drupal::config('core.extension')->get('module.skeleton_site'));
    $this->configImporter()->import();
    $this->assertSame([], array_filter($this->configImporter()->getStorageComparer()->getChangelist()));
  }

}
