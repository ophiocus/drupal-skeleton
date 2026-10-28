<?php

declare(strict_types=1);

namespace Drupal\Tests\appointments\Functional;

use Drupal\appointments\Entity\AppointmentRoom;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\Tests\BrowserTestBase;
use Drupal\user\RoleInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * A Spanish site with English under /en: the picker in each language.
 *
 * Services are translated as content (their data table), rooms as config
 * (language overrides, edited through config_translation).
 */
#[Group('appointments')]
#[RunTestsInSeparateProcesses]
final class BilingualPickerTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['appointments', 'language', 'content_translation', 'config_translation', 'page_cache'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    ConfigurableLanguage::createFromLangcode('es')->save();
    $this->config('system.site')->set('default_langcode', 'es')->save();
    $this->config('language.negotiation')->set('url.prefixes', ['es' => '', 'en' => 'en'])->save();
    $this->resetAll();

    $hours = [];
    foreach (range(1, 7) as $day) {
      $hours[] = ['day' => $day, 'start' => '00:00', 'end' => '24:00'];
    }
    $this->config('appointments.settings')
      ->set('timezone', 'UTC')
      ->set('hours', $hours)
      ->set('lead_time', 60)
      ->set('horizon', 3)
      ->save();

    $storage = $this->container->get('entity_type.manager')->getStorage('appointment_service');
    $services = [
      ['corte-dama', 'Corte de dama', "Women's haircut", 'Asesoría, corte y peinado.', 'Advice, cut and styling.'],
      ['manicure', 'Manicure semipermanente', 'Gel manicure', '', ''],
    ];
    foreach ($services as $weight => [$key, $es, $en, $es_text, $en_text]) {
      $service = $storage->create([
        'key' => $key,
        'label' => $es,
        'description' => $es_text,
        'duration' => 30,
        'weight' => $weight,
        'status' => TRUE,
      ]);
      $service->addTranslation('en', ['label' => $en, 'description' => $en_text]);
      $service->save();
    }
    $this->container->get('content_translation.manager')->setEnabled('appointment_service', 'appointment_service', TRUE);

    $rooms = [
      'silla_1' => ['Silla de corte 1', 'Haircut chair 1'],
      'silla_2' => ['Silla de corte 2', 'Haircut chair 2'],
    ];
    foreach ($rooms as $id => [$es, $en]) {
      AppointmentRoom::create(['id' => $id, 'label' => $es, 'services' => ['corte-dama']])->save();
      $this->container->get('language_manager')->getLanguageConfigOverride('en', "appointments.room.$id")->set('label', $en)->save();
    }
    user_role_grant_permissions(RoleInterface::ANONYMOUS_ID, ['book appointments']);
  }

  /**
   * Each language sees the services and rooms in its own words, once each.
   */
  public function testPickerInTheContentLanguage(): void {
    $assert = $this->assertSession();

    $this->drupalGet('/book');
    $assert->statusCodeEquals(200);
    $assert->optionExists('service', 'corte-dama');
    $this->assertSame('Corte de dama', trim($assert->optionExists('service', 'corte-dama')->getText()));
    $assert->pageTextContains('Asesoría, corte y peinado.');
    $assert->linkExists('Silla de corte 2');
    $assert->pageTextNotContains("Women's haircut");

    $this->drupalGet('/en/book');
    $assert->statusCodeEquals(200);
    $this->assertSame("Women's haircut", trim($assert->optionExists('service', 'corte-dama')->getText()));
    $this->assertSame('Gel manicure', trim($assert->optionExists('service', 'manicure')->getText()));
    // One option per service, whatever its translations.
    $this->assertCount(2, $this->getSession()->getPage()->findAll('css', 'select[name="service"] option'));
    $assert->pageTextContains('Advice, cut and styling.');
    $assert->pageTextNotContains('Corte de dama');
    $assert->linkExists('Haircut chair 2');
    $assert->linkNotExists('Silla de corte 2');

    // The rest of the booking stays in English.
    $slot = $this->getSession()->getPage()->find('css', 'a.appointments-slot');
    $this->assertNotNull($slot);
    $this->assertStringStartsWith('/en/book/', (string) parse_url((string) $slot->getAttribute('href'), PHP_URL_PATH));
    $slot->click();
    $assert->pageTextContains("Book: Women's haircut");
  }

  /**
   * Staff translate services as content and rooms as configuration.
   */
  public function testTranslationScreens(): void {
    $assert = $this->assertSession();
    $this->drupalLogin($this->drupalCreateUser([
      'administer appointments',
      'translate any entity',
      'create content translations',
      'update content translations',
      'translate configuration',
    ]));

    // The service list: one row per service, in the page's language.
    $this->drupalGet('/en/admin/appointments/services');
    $assert->statusCodeEquals(200);
    $assert->pageTextContains("Women's haircut");
    $assert->pageTextNotContains('Corte de dama');
    $this->assertCount(2, $this->getSession()->getPage()->findAll('css', 'table tbody tr'));
    $assert->linkByHrefExists('/en/admin/appointments/services/1/translations');

    $this->drupalGet('/admin/appointments/services/1/translations');
    $assert->statusCodeEquals(200);
    $assert->pageTextContains('English');

    $this->drupalGet('/admin/appointments/rooms/silla_1/translate');
    $assert->statusCodeEquals(200);
    $assert->pageTextContains('English');
  }

}
