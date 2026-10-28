<?php

declare(strict_types=1);

namespace Drupal\Tests\appointments\Kernel;

use Drupal\appointments\Entity\AppointmentInterface;
use Drupal\appointments\Entity\AppointmentService;
use Drupal\appointments\Entity\AppointmentServiceInterface;
use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\language\Entity\ConfigurableLanguage;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The one-time step of a site that installed appointments before 2026-10-28.
 *
 * Such a site stores services in one table with no language, and may not
 * have recorded the room entity type. The step must bring both up to the
 * code without losing a service or an appointment's link to it.
 */
#[Group('appointments')]
#[RunTestsInSeparateProcesses]
final class EntitySchemaTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'field', 'options', 'language', 'appointments'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');

    // Services as the module stored them until now: one table, no language.
    $current = $this->container->get('entity_type.manager')->getDefinition('appointment_service');
    assert($current instanceof ContentEntityTypeInterface);
    $old = clone $current;
    $keys = $old->getKeys();
    unset($keys['langcode']);
    $old->set('entity_keys', $keys);
    $old->set('translatable', FALSE);
    $old->set('data_table', NULL);
    $fields = AppointmentService::baseFieldDefinitions($old);
    foreach ($fields as $field) {
      $field->setTranslatable(FALSE);
    }
    $this->assertArrayNotHasKey('langcode', $fields);
    $this->container->get('entity.definition_update_manager')->installFieldableEntityType($old, $fields);
    $this->installEntitySchema('appointment');
    $this->installConfig(['system', 'language', 'appointments']);

    $rows = [
      [
        'id' => 1,
        'uuid' => 'a6c5a1a4-1b7e-4c38-9a55-0f6f3b1d2c01',
        'key' => 'consulta',
        'label' => 'Consulta general',
        'description' => 'Revisión completa.',
        'duration' => 30,
        'buffer' => 10,
        'price' => '60000.00',
        'weight' => 0,
        'status' => 1,
        'changed' => 1761400000,
      ],
      [
        'id' => 2,
        'uuid' => 'a6c5a1a4-1b7e-4c38-9a55-0f6f3b1d2c02',
        'key' => 'vacuna',
        'label' => 'Vacunación',
        'description' => NULL,
        'duration' => 15,
        'buffer' => 0,
        'price' => '0.00',
        'weight' => 1,
        'status' => 0,
        'changed' => 1761400000,
      ],
    ];
    foreach ($rows as $row) {
      $this->container->get('database')->insert('appointment_service')->fields($row)->execute();
    }
  }

  /**
   * Pending, applied once, then nothing more to do; every service kept.
   */
  public function testServicesBecomeTranslatable(): void {
    $schema = $this->container->get('appointments.entity_schema');
    $this->assertContains('make appointment_service translatable (data table, langcode)', $schema->pending());

    $done = $schema->apply();
    $this->assertContains('made appointment_service translatable', $done);
    $this->assertSame([], $schema->pending());
    $this->assertSame([], $schema->apply(), 'A second run does nothing.');
    // Nothing of this module left for the status report. (A kernel test
    // never records config entity types, so other modules' show up there.)
    $changes = $this->container->get('entity.definition_update_manager')->getChangeList();
    $ours = array_flip(['appointment', 'appointment_service', 'appointment_room']);
    $this->assertSame([], array_intersect_key($changes, $ours));
    $this->assertTrue($this->container->get('database')->schema()->tableExists('appointment_service_field_data'));

    // Every service is there, in the site's language, with all its values.
    $storage = $this->container->get('entity_type.manager')->getStorage('appointment_service');
    $storage->resetCache();
    $consulta = $storage->load(1);
    $this->assertInstanceOf(AppointmentServiceInterface::class, $consulta);
    $this->assertSame('Consulta general', $consulta->label());
    $this->assertSame('Revisión completa.', $consulta->get('description')->value);
    $this->assertSame('consulta', $consulta->getKey());
    $this->assertSame(30, $consulta->getDuration());
    $this->assertSame(10, $consulta->getBuffer());
    $this->assertSame(60000.0, (float) $consulta->getPrice());
    $this->assertSame('en', $consulta->language()->getId());
    $this->assertTrue($consulta->isDefaultTranslation());
    $this->assertFalse($storage->load(2)->isPublished());
    $this->assertCount(1, $storage->loadByProperties(['key' => 'vacuna']));

    // Appointments still point at their service.
    $appointment = $this->container->get('entity_type.manager')->getStorage('appointment')->create([
      'service' => 1,
      'start' => 1761580800,
      'end' => 1761582600,
      'name' => 'Ana',
      'email' => 'ana@example.com',
      'phone' => '1',
    ]);
    $appointment->save();
    assert($appointment instanceof AppointmentInterface);
    $this->assertSame('Consulta general', $appointment->getService()?->label());

    // And a service now takes a translation (loaded again: an entity knows
    // the languages that existed when it was loaded).
    ConfigurableLanguage::createFromLangcode('es')->save();
    $storage->resetCache();
    $storage->load(1)->addTranslation('es', ['label' => 'Consulta (es)'])->save();
    $storage->resetCache();
    $this->assertSame('Consulta (es)', $storage->load(1)->getTranslation('es')->label());
    $this->assertSame('Consulta general', $storage->load(1)->label());
  }

}
