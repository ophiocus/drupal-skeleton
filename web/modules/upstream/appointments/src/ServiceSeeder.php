<?php

declare(strict_types=1);

namespace Drupal\appointments;

use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Converges the bookable services on seed rows.
 *
 * Where site_seed is installed, a seed file's `appointment_services` section
 * reaches this through SiteSeed\AppointmentServicesHandler.
 *
 * Services are located by their key, never by label. Idempotent: a second run
 * on unchanged rows reports zero creates and zero updates. Services that are
 * not in the rows are left alone (staff may have added them).
 */
final class ServiceSeeder {

  /**
   * The fields a seed row may set.
   */
  private const FIELDS = ['label', 'description', 'duration', 'buffer', 'price', 'weight', 'status'];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Converges the services.
   *
   * @param list<array<string, mixed>> $rows
   *   Rows with 'key' and any of label, description, duration, buffer, price,
   *   weight, status.
   * @param bool $write
   *   FALSE counts what would change and saves nothing.
   *
   * @return array{created: int, updated: int, unchanged: int}
   *   What changed (or would).
   */
  public function converge(array $rows, bool $write = TRUE): array {
    $storage = $this->entityTypeManager->getStorage('appointment_service');
    $tally = ['created' => 0, 'updated' => 0, 'unchanged' => 0];
    foreach ($rows as $row) {
      $key = (string) ($row['key'] ?? '');
      if ($key === '') {
        throw new \InvalidArgumentException('A service seed row has no key.');
      }
      $found = $storage->loadByProperties(['key' => $key]);
      /** @var \Drupal\appointments\Entity\AppointmentServiceInterface|null $service */
      $service = $found ? reset($found) : NULL;
      if (!$service) {
        $values = ['key' => $key];
        foreach (self::FIELDS as $field) {
          if (array_key_exists($field, $row)) {
            $values[$field] = $row[$field];
          }
        }
        if ($write) {
          $storage->create($values)->save();
        }
        $tally['created']++;
        continue;
      }
      $dirty = FALSE;
      foreach (self::FIELDS as $field) {
        if (!array_key_exists($field, $row)) {
          continue;
        }
        $have = $service->get($field)->value;
        $want = $row[$field];
        $same = match ($field) {
          'price' => abs((float) $have - (float) $want) < 0.005,
          'status' => (bool) $have === (bool) $want,
          'duration', 'buffer', 'weight' => (int) $have === (int) $want,
          default => (string) $have === (string) $want,
        };
        if (!$same) {
          $service->set($field, $want);
          $dirty = TRUE;
        }
      }
      if ($dirty) {
        if ($write) {
          $service->save();
        }
        $tally['updated']++;
      }
      else {
        $tally['unchanged']++;
      }
    }
    return $tally;
  }

}
