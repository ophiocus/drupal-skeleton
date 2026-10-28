<?php

declare(strict_types=1);

namespace Drupal\Tests\appointments\Kernel;

use Drupal\language\Entity\ConfigurableLanguage;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Services of a Spanish site with English as its second language.
 */
#[Group('appointments')]
#[RunTestsInSeparateProcesses]
final class TranslatedServicesTest extends AppointmentsKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'field', 'options', 'language', 'appointments'];

  /**
   * The seed rows: Spanish on the row, English in its translations.
   *
   * @var list<array<string, mixed>>
   */
  private const ROWS = [
    [
      'key' => 'manicure',
      'label' => 'Manicure semipermanente',
      'duration' => 45,
      'weight' => 1,
      'status' => TRUE,
      'translations' => ['en' => ['label' => 'Gel manicure']],
    ],
    [
      'key' => 'corte-dama',
      'label' => 'Corte de dama',
      'description' => 'Asesoría, corte y peinado.',
      'duration' => 60,
      'weight' => 0,
      'status' => TRUE,
      'translations' => ['en' => ['label' => "Women's haircut", 'description' => 'Advice, cut and styling.']],
    ],
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['language']);
    ConfigurableLanguage::createFromLangcode('es')->save();
    $this->config('system.site')->set('default_langcode', 'es')->save();
    // Pages in the site's default language, whichever it is: the test
    // switches it to read the English pages.
    $this->config('language.types')
      ->set('negotiation.language_interface.enabled', ['language-selected' => 0])
      ->set('negotiation.language_interface.method_weights', ['language-selected' => 0])
      ->save();
    $this->config('language.negotiation')->set('selected_langcode', 'site_default')->save();
    $this->language('es');
  }

  /**
   * Makes a language the site's default, and so the pages' language.
   */
  private function language(string $langcode): void {
    $this->container->get('language.default')->set(ConfigurableLanguage::load($langcode));
    $this->container->get('language_manager')->reset();
  }

  /**
   * Seeded in Spanish, translated to English, idempotent.
   */
  public function testSeedTranslations(): void {
    $seeder = $this->container->get('appointments.seeder');
    $this->assertSame(['created' => 2, 'updated' => 0, 'unchanged' => 0], $seeder->converge(self::ROWS));
    $this->assertSame(['created' => 0, 'updated' => 0, 'unchanged' => 2], $seeder->converge(self::ROWS), 'A second run changes nothing.');

    $storage = $this->container->get('entity_type.manager')->getStorage('appointment_service');
    $corte = current($storage->loadByProperties(['key' => 'corte-dama']));
    $this->assertSame('es', $corte->language()->getId());
    $this->assertSame('Corte de dama', $corte->label());
    $this->assertSame("Women's haircut", $corte->getTranslation('en')->label());
    $this->assertSame('Advice, cut and styling.', $corte->getTranslation('en')->get('description')->value);
    // Shared values are the same in both languages.
    $this->assertSame(60, $corte->getTranslation('en')->getDuration());

    // A changed English name is one update; a check run writes nothing.
    $rows = self::ROWS;
    $rows[0]['translations']['en']['label'] = 'Semi-permanent manicure';
    $this->assertSame(['created' => 0, 'updated' => 1, 'unchanged' => 1], $seeder->converge($rows, FALSE));
    $this->assertSame(['created' => 0, 'updated' => 1, 'unchanged' => 1], $seeder->converge($rows));
    $storage->resetCache();
    $this->assertSame('Semi-permanent manicure', current($storage->loadByProperties(['key' => 'manicure']))->getTranslation('en')->label());

    // The rows in Spanish only, as on a site with one language, keep working.
    $plain = [['key' => 'lavado', 'label' => 'Lavado', 'duration' => 30, 'status' => TRUE]];
    $this->assertSame(['created' => 1, 'updated' => 0, 'unchanged' => 0], $seeder->converge($plain));
  }

  /**
   * A translation the site cannot hold is refused, not half-written.
   */
  public function testBadTranslationsAreRefused(): void {
    $seeder = $this->container->get('appointments.seeder');
    $cases = [
      'fr' => [['key' => 'a', 'label' => 'A', 'translations' => ['fr' => ['label' => 'A']]]],
      'es' => [['key' => 'a', 'label' => 'A', 'translations' => ['es' => ['label' => 'A']]]],
      'price' => [['key' => 'a', 'label' => 'A', 'translations' => ['en' => ['label' => 'A', 'price' => 1]]]],
    ];
    foreach ($cases as $case => $rows) {
      try {
        $seeder->converge($rows);
        $this->fail("$case: accepted.");
      }
      catch (\InvalidArgumentException $e) {
        $this->assertStringContainsString('Service a:', $e->getMessage(), $case);
      }
    }
    $this->assertSame([], $this->container->get('entity_type.manager')->getStorage('appointment_service')->loadByProperties(['key' => 'a']));
  }

  /**
   * One entry per service, lightest first, in the content language.
   */
  public function testServiceListIsLanguageAware(): void {
    $this->container->get('appointments.seeder')->converge(self::ROWS);
    $calendar = $this->container->get('appointments.calendar');

    $services = $calendar->services();
    $this->assertSame(['corte-dama', 'manicure'], array_keys($services));
    $this->assertSame('Corte de dama', $services['corte-dama']->label());

    // English pages: the same two services, in English.
    $this->language('en');
    $services = $calendar->services();
    $this->assertSame(['corte-dama', 'manicure'], array_keys($services));
    $this->assertSame("Women's haircut", $services['corte-dama']->label());
    $this->assertSame('Gel manicure', $services['manicure']->label());

    // Unbookable services are left out of the picker, kept for the admin.
    current($this->container->get('entity_type.manager')->getStorage('appointment_service')->loadByProperties(['key' => 'manicure']))->setUnpublished()->save();
    $this->assertSame(['corte-dama'], array_keys($calendar->services()));
    $this->assertSame(['corte-dama', 'manicure'], array_keys($calendar->services(FALSE)));
  }

}
