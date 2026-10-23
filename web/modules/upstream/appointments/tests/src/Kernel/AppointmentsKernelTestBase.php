<?php

declare(strict_types=1);

namespace Drupal\Tests\appointments\Kernel;

use Drupal\appointments\Entity\AppointmentInterface;
use Drupal\appointments\Entity\AppointmentServiceInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\KernelTests\KernelTestBase;

/**
 * A calendar in America/Bogota (UTC-5, no DST), open Monday 08:00-12:00.
 *
 * The clock is frozen on Monday 2026-10-26 06:00 Bogota unless a test moves
 * it.
 */
abstract class AppointmentsKernelTestBase extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'options', 'appointments'];

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
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('appointment_service');
    $this->installEntitySchema('appointment');
    $this->installConfig(['system', 'appointments']);
    // The mails carry absolute links to the client's pages.
    $this->container->get('router.builder')->rebuild();
    $this->config('system.site')->set('mail', 'clinic@example.com')->set('name', 'Clinic')->save();
    $this->config('system.mail')->set('interface.default', 'test_mail_collector')->save();
    $this->config('appointments.settings')
      ->set('timezone', 'America/Bogota')
      ->set('slot_step', 30)
      ->set('lead_time', 120)
      ->set('horizon', 30)
      ->set('capacity', 1)
      ->set('hold', 30)
      ->set('cancel_cutoff', 120)
      ->set('hours', [['day' => 1, 'start' => '08:00', 'end' => '12:00']])
      ->set('closures', ['2026-11-02'])
      ->save();
    $this->at('2026-10-26 06:00');
  }

  /**
   * Sets the clock to a Bogota wall-clock time.
   */
  protected function at(string $when): void {
    FrozenTime::$now = $this->ts($when);
  }

  /**
   * A Bogota wall-clock time as a timestamp.
   */
  protected function ts(string $when): int {
    return (new \DateTimeImmutable($when, new \DateTimeZone('America/Bogota')))->getTimestamp();
  }

  /**
   * Creates a service.
   */
  protected function service(int $duration = 30, int $buffer = 0, string $price = '0', string $key = 'consult'): AppointmentServiceInterface {
    $service = $this->container->get('entity_type.manager')->getStorage('appointment_service')->create([
      'key' => $key,
      'label' => ucfirst($key),
      'duration' => $duration,
      'buffer' => $buffer,
      'price' => $price,
      'status' => TRUE,
    ]);
    $service->save();
    assert($service instanceof AppointmentServiceInterface);
    return $service;
  }

  /**
   * An unsaved appointment for a service and start.
   */
  protected function draft(AppointmentServiceInterface $service, int $start, string $mail = 'client@example.com'): AppointmentInterface {
    $appointment = $this->container->get('entity_type.manager')->getStorage('appointment')->create([
      'service' => $service->id(),
      'start' => $start,
      'name' => 'Ana',
      'email' => $mail,
      'phone' => '3001234567',
    ]);
    assert($appointment instanceof AppointmentInterface);
    return $appointment;
  }

  /**
   * Saves an appointment directly, bypassing the booking rules.
   */
  protected function existing(AppointmentServiceInterface $service, int $start, string $status = AppointmentInterface::CONFIRMED, ?int $hold_expires = NULL): AppointmentInterface {
    $appointment = $this->draft($service, $start);
    $end = $start + $service->getDuration() * 60;
    $appointment->set('end', $end);
    $appointment->set('busy_until', $end + $service->getBuffer() * 60);
    $appointment->setStatus($status);
    $appointment->set('hold_expires', $hold_expires);
    $appointment->save();
    return $appointment;
  }

  /**
   * The free starts of a service on a date, as Bogota HH:MM.
   *
   * @return list<string>
   *   Times.
   */
  protected function times(AppointmentServiceInterface $service, string $date, ?int $ignore = NULL): array {
    $tz = new \DateTimeZone('America/Bogota');
    return array_map(
      static fn (int $t): string => (new \DateTimeImmutable('@' . $t))->setTimezone($tz)->format('H:i'),
      $this->container->get('appointments.calendar')->slots($service, $date, $ignore),
    );
  }

  /**
   * The mails collected so far.
   *
   * @return list<array<string, mixed>>
   *   Messages.
   */
  protected function mails(): array {
    return $this->container->get('state')->get('system.test_mail_collector', []);
  }

}
