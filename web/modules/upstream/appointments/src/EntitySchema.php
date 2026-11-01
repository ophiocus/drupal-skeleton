<?php

declare(strict_types=1);

namespace Drupal\appointments;

use Drupal\Core\Database\Connection;
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
 * - content_translation's own fields on services in line with that shape.
 *   content_translation picks them from the last installed definition: a
 *   deploy that imports "translate services" before this step installs the
 *   ones for the old shape (its own changed time, no translation status),
 *   and every translation then reads as unpublished, so pages fall back to
 *   the default language. They are installed and removed as the
 *   translatable shape needs, and any translation left without a status is
 *   published.
 */
final class EntitySchema {

  /**
   * The module whose fields on services this keeps in line.
   */
  private const TRANSLATION_PROVIDER = 'content_translation';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityFieldManagerInterface $entityFieldManager,
    private readonly EntityDefinitionUpdateManagerInterface $updateManager,
    private readonly EntityLastInstalledSchemaRepositoryInterface $lastInstalled,
    private readonly Connection $database,
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
      // content_translation's fields are judged against the new shape, so
      // they can only be read once services are translatable.
      $pending[] = 'make appointment_service translatable (data table, langcode), then align content_translation\'s fields';
      return $pending;
    }
    return array_merge($pending, $this->translationFieldSteps(FALSE));
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
      $this->resetDefinitions();
      $type = $this->entityTypeManager->getDefinition('appointment_service');
      $fields = $this->entityFieldManager->getFieldStorageDefinitions('appointment_service');
      // Copies the services in batches (entity_update_batch_size) until done.
      $sandbox = [];
      do {
        $this->updateManager->updateFieldableEntityType($type, $fields, $sandbox);
      } while (($sandbox['#finished'] ?? 1) < 1);
      $done[] = 'made appointment_service translatable';
    }
    return array_merge($done, $this->translationFieldSteps(TRUE));
  }

  /**
   * Aligns content_translation's fields on services with the definitions.
   *
   * @param bool $write
   *   FALSE describes what is pending and changes nothing.
   *
   * @return list<string>
   *   What was (or would be) done.
   */
  private function translationFieldSteps(bool $write): array {
    // The translation handler reads the last installed definitions once:
    // rebuilt here, it answers for the shape the database has now.
    $this->resetDefinitions();
    $live = $this->entityFieldManager->getFieldStorageDefinitions('appointment_service');
    $installed = $this->lastInstalled->getLastInstalledFieldStorageDefinitions('appointment_service');
    $ours = static fn ($definition): bool => $definition->getProvider() === self::TRANSLATION_PROVIDER;
    $missing = array_filter(array_diff_key($live, $installed), $ours);
    $stale = array_filter(array_diff_key($installed, $live), $ours);

    $steps = [];
    foreach ($missing as $name => $definition) {
      if ($write) {
        $this->updateManager->installFieldStorageDefinition($name, 'appointment_service', self::TRANSLATION_PROVIDER, $definition);
      }
      $steps[] = ($write ? 'installed ' : 'install ') . "content_translation's $name on appointment_service";
    }
    foreach ($stale as $name => $definition) {
      if ($write) {
        $this->updateManager->uninstallFieldStorageDefinition($definition);
      }
      $steps[] = ($write ? 'removed ' : 'remove ') . "content_translation's $name from appointment_service";
    }

    // content_translation installs its status column with every row
    // published; a row left empty all the same (by a repair made by hand)
    // would read as unpublished: those translations were published.
    $table = 'appointment_service_field_data';
    $column = 'content_translation_status';
    $schema = $this->database->schema();
    if ($schema->tableExists($table) && $schema->fieldExists($table, $column)) {
      $count = $write
        ? (int) $this->database->update($table)->fields([$column => 1])->isNull($column)->execute()
        : (int) $this->database->select($table)->isNull($column)->countQuery()->execute()->fetchField();
      if ($count > 0) {
        $steps[] = ($write ? 'published ' : 'publish ') . "$count service translations that had no translation status";
      }
    }
    if ($write && $steps) {
      $this->resetDefinitions();
    }
    return $steps;
  }

  /**
   * Forgets cached definitions and handlers.
   */
  private function resetDefinitions(): void {
    $this->entityTypeManager->clearCachedDefinitions();
    $this->entityFieldManager->clearCachedFieldDefinitions();
  }

}
