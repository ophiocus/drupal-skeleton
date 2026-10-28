<?php

declare(strict_types=1);

namespace Drupal\Tests\skeleton_site\Functional;

use Drupal\Component\Serialization\Yaml;
use Drupal\FunctionalTests\Installer\InstallerConfigDirectoryTestBase;
use Drupal\language\Entity\ConfigurableLanguage;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * A Spanish site with English keeps English when installed from its config.
 *
 * The fixture is a site's config/sync: default language es, English as a
 * second language, an English override of system.site and the English
 * strings locale keeps for config written in Spanish. On core's `standard`
 * the installer deletes English and every override with it (drupal.org
 * #3545306); skeleton_site carries `keep_english: true`.
 */
#[Group('skeleton_site')]
#[RunTestsInSeparateProcesses]
class KeepEnglishInstallTest extends InstallerConfigDirectoryTestBase {

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected $profile = 'skeleton_site';

  /**
   * {@inheritdoc}
   */
  protected $existingSyncDirectory = TRUE;

  /**
   * {@inheritdoc}
   */
  protected function getConfigLocation(): string {
    return __DIR__ . '/../../fixtures/sync';
  }

  /**
   * {@inheritdoc}
   */
  protected function prepareEnvironment(): void {
    parent::prepareEnvironment();
    // The base class writes a stand-in profile of the same name into the test
    // site, which would win over the real one. The real one is under test.
    $stand_in = $this->siteDirectory . '/profiles/' . $this->profile;
    unlink("$stand_in/{$this->profile}.info.yml");
    rmdir($stand_in);
    rmdir($this->siteDirectory . '/profiles');

    // A local Spanish .po, so the installer has the language without
    // downloading it.
    mkdir($this->publicFilesDirectory . '/translations', 0777, TRUE);
    file_put_contents($this->publicFilesDirectory . '/translations/drupal-' . \Drupal::VERSION . '.es.po', <<<PO
msgid ""
msgstr ""

msgid "Anonymous"
msgstr "Anónimo"

PO);
  }

  /**
   * {@inheritdoc}
   */
  protected function setUpRequirementsProblem(): void {
    // The gate's web server runs without OPcache (test-gate.yml), which the
    // installer reports as a warning and waits on.
    if ($this->getSession()->getPage()->findLink('continue anyway')) {
      $this->continueOnExpectedWarnings(['PHP OPcode caching']);
    }
  }

  /**
   * English survives the install, with every English override of the sync.
   */
  public function testConfigSync(): void {
    // Nothing to import afterwards, in the default collection and in English.
    parent::testConfigSync();
    $comparer = $this->configImporter()->getStorageComparer();
    $this->assertSame([], array_filter($comparer->getChangelist(NULL, 'language.en')));

    $this->assertSame('skeleton_site', \Drupal::installProfile());
    $this->assertSame('es', \Drupal::languageManager()->getDefaultLanguage()->getId());
    $this->assertNotNull(ConfigurableLanguage::load('en'), 'English was kept.');
    $this->assertSame('Sitio de prueba', \Drupal::config('system.site')->getOriginal('name', FALSE));
    $this->assertSame('Test site', \Drupal::languageManager()->getLanguageConfigOverride('en', 'system.site')->get('name'));

    $sync = $this->getConfigLocation() . '/language/en';
    $expected = array_map(fn($file) => basename($file, '.yml'), glob("$sync/*.yml"));
    $active = \Drupal::service('config.storage')->createCollection('language.en')->listAll();
    $this->assertNotEmpty($expected);
    $this->assertEqualsCanonicalizing($expected, $active, 'Every English override of the sync is installed.');
    $this->assertSame(
      Yaml::decode(file_get_contents("$sync/system.site.yml")),
      \Drupal::service('config.storage')->createCollection('language.en')->read('system.site'),
    );
  }

}
