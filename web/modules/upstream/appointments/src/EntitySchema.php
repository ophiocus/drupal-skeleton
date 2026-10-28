<?php

declare(strict_types=1);

namespace Drupal\appointments;

use Drupal\Core\Entity\EntityDefinitionUpdateManagerInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityLastInstalledSchemaRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * The entity-definition steps a site installed on an older version needs.
 *
 * The fleet ships no update hooks (PROTOCOL D25), so a change to how an
 * entity type is stored reaches a deployed site as this one-time step, run
 * by hand once after the deploy that brings the code (`drush
 * appointments:entity-updates`). Each step checks what the database has, so
 * running it again, or on a fresh install, does nothing.
 *
 * - `appointment_room` installed (rooms, 2026-10).
 * - `appointment_service` translatable (2026-10): its data table, langcode
 *   and default_langcode; the existing services are copied over in the
 *   site's default language, nothing lost.
 */
final class EntitySchema {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityFieldManagerInterface $entityFieldManager,
    private readonly EntityDefinitionUpdateManagerInterface $updateManager,
    private readonly EntityLastInstalledSchemaRepositoryInterface $lastInstalled,
  ) {}

  /**
   * What the database still lacks, as sentences; empty when up to date.
   *
   * @return list<string>
   *   Pending steps.
   */
  public function pending(): array {
    $pending = [];
    if ($this->lastInstalled->getLastInstalledDefinition('appointment_room') === NULL) {
      $pending[] = 'install the appointment_room entity type';
    }
    $service = $this->lastInstalled->getLastInstalledDefinition('appointment_service');
    if ($service !== NULL && !$service->isTranslatable()) {
      $pending[] = 'make appointment_service translatable (data table, langcode)';
    }
    return $pending;
  }

  /**
   * Applies the pending steps.
   *
   * @return list<string>
   *   What was done; empty when nothing was pending.
   */
  public function apply(): array {
    $done = [];
    if ($this->lastInstalled->getLastInstalledDefinition('appointment_room') === NULL) {
      $this->updateManager->installEntityType($this->entityTypeManager->getDefinition('appointment_room'));
      $done[] = 'installed the appointment_room entity type';
    }
    $service = $this->lastInstalled->getLastInstalledDefinition('appointment_service');
    if ($service !== NULL && !$service->isTranslatable()) {
      $this->entityTypeManager->clearCachedDefinitions();
      $this->entityFieldManager->clearCachedFieldDefinitions();
      $type = $this->entityTypeManager->getDefinition('appointment_service');
      $fields = $this->entityFieldManager->getFieldStorageDefinitions('appointment_service');
      // Copies the services in batches (entity_update_batch_size) until done.
      $sandbox = [];
      do {
        $this->updateManager->updateFieldableEntityType($type, $fields, $sandbox);
      } while (($sandbox['#finished'] ?? 1) < 1);
      $done[] = 'made appointment_service translatable';
    }
    return $done;
  }

}
