<?php

declare(strict_types=1);

namespace Drupal\Tests\appointments\Functional;

use Drupal\appointments\Entity\AppointmentInterface;
use Drupal\Core\Test\AssertMailTrait;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\Tests\BrowserTestBase;
use Drupal\user\RoleInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * A client books, opens, downloads and cancels; staff see the list.
 */
#[Group('appointments')]
final class BookingFlowTest extends BrowserTestBase {

  use AssertMailTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['field', 'appointments'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // Open around the clock so the test finds free times whenever it runs.
    $hours = [];
    foreach (range(1, 7) as $day) {
      $hours[] = ['day' => $day, 'start' => '00:00', 'end' => '24:00'];
    }
    $this->config('appointments.settings')
      ->set('timezone', 'UTC')
      ->set('hours', $hours)
      ->set('lead_time', 60)
      ->set('horizon', 3)
      ->set('cancel_cutoff', 30)
      ->save();
    $this->config('system.site')->set('mail', 'clinic@example.com')->save();
    $this->container->get('entity_type.manager')->getStorage('appointment_service')->create([
      'key' => 'consult',
      'label' => 'Consult',
      'description' => 'A general check-up.',
      'duration' => 30,
      'status' => TRUE,
    ])->save();

    // A field the site adds, as a pet's name would be.
    FieldStorageConfig::create([
      'field_name' => 'field_pet',
      'entity_type' => 'appointment',
      'type' => 'string',
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_pet',
      'entity_type' => 'appointment',
      'bundle' => 'appointment',
      'label' => 'Pet',
      'required' => TRUE,
    ])->save();
    $this->container->get('entity_display.repository')->getFormDisplay('appointment', 'appointment')
      ->setComponent('field_pet', ['type' => 'string_textfield', 'weight' => 5])
      ->save();

    user_role_grant_permissions(RoleInterface::ANONYMOUS_ID, ['book appointments']);
  }

  /**
   * The whole client path, then the staff list.
   */
  public function testBookAndCancel(): void {
    $assert = $this->assertSession();

    $this->drupalGet('/book');
    $assert->statusCodeEquals(200);
    $assert->pageTextContains('Consult');
    $assert->pageTextContains('A general check-up.');
    $this->clickLink('See free times');
    $assert->statusCodeEquals(200);

    $slot = $this->getSession()->getPage()->find('css', 'a.appointments-slot');
    $this->assertNotNull($slot, 'The slot picker offers at least one time.');
    $slot->click();
    $assert->fieldExists('field_pet[0][value]');
    $assert->fieldNotExists('status');
    $this->submitForm([
      'name[0][value]' => 'Ana',
      'email[0][value]' => 'ana@example.com',
      'phone[0][value]' => '3001234567',
      'field_pet[0][value]' => 'Luna',
    ], 'Confirm the appointment');

    $assert->pageTextContains('Your appointment is booked.');
    $assert->pageTextContains('Luna');
    $assert->addressMatches('#^/appointment/[0-9a-f]{48}$#');
    $manage = $this->getUrl();

    $mails = $this->getMails();
    $this->assertCount(2, $mails);
    $this->assertSame('ana@example.com', $mails[0]['to']);
    $this->assertStringContainsString('Pet: Luna', $mails[1]['body']);

    $this->clickLink('Add to my calendar');
    $assert->responseHeaderContains('Content-Type', 'text/calendar');
    $assert->responseContains('BEGIN:VEVENT');

    $this->drupalGet($manage);
    $this->clickLink('Cancel the appointment');
    $this->submitForm([], 'Cancel the appointment');
    $assert->pageTextContains('Your appointment is cancelled.');
    $assert->linkNotExists('Cancel the appointment');

    $appointments = $this->container->get('entity_type.manager')->getStorage('appointment')->loadByProperties(['name' => 'Ana']);
    $appointment = reset($appointments);
    $this->assertInstanceOf(AppointmentInterface::class, $appointment);
    $this->assertSame(AppointmentInterface::CANCELLED, $appointment->getStatus());

    // Unknown tokens are not found; anonymous visitors cannot see the list.
    $this->drupalGet('/appointment/' . str_repeat('a', 48));
    $assert->statusCodeEquals(404);
    $this->drupalGet('/admin/appointments');
    $assert->statusCodeEquals(403);

    $this->drupalLogin($this->drupalCreateUser(['view appointments']));
    $this->drupalGet('/admin/appointments');
    $assert->statusCodeEquals(200);
    $assert->pageTextContains('Ana · Luna');
    $assert->pageTextContains('Cancelled');
  }

  /**
   * A time taken while the client fills the form is refused, not doubled.
   */
  public function testTakenWhileFilling(): void {
    $this->drupalGet('/book/1');
    $slot = $this->getSession()->getPage()->find('css', 'a.appointments-slot');
    $this->assertNotNull($slot);
    $slot->click();

    preg_match('#/book/1/(\d+)$#', parse_url($this->getUrl(), PHP_URL_PATH), $m);
    $storage = $this->container->get('entity_type.manager')->getStorage('appointment');
    $other = $storage->create([
      'service' => 1,
      'start' => (int) $m[1],
      'name' => 'Bea',
      'email' => 'bea@example.com',
      'phone' => '1',
      'field_pet' => 'Max',
    ]);
    $this->container->get('appointments.booking')->book($other);

    $this->submitForm([
      'name[0][value]' => 'Ana',
      'email[0][value]' => 'ana@example.com',
      'phone[0][value]' => '3001234567',
      'field_pet[0][value]' => 'Luna',
    ], 'Confirm the appointment');
    // The form's own route re-checks the time before the submission runs.
    $this->assertSession()->pageTextContains('That time is no longer free.');
    $this->assertCount(1, $storage->loadByProperties(['start' => (int) $m[1]]));
  }

}
