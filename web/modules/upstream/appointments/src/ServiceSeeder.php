<?php

declare(strict_types=1);

namespace Drupal\appointments;

use Drupal\appointments\Entity\AppointmentServiceInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;

/**
 * Converges the bookable services on seed rows.
 *
 * Where site_seed is installed, a seed file's `appointment_services` section
 * reaches this through SiteSeed\AppointmentServicesHandler.
 *
 * Services are located by their key, never by label. Idempotent: a second run
 * on unchanged rows reports zero creates and zero updates. Services that are
 * not in the rows are left alone (staff may have added them).
 *
 * A row's label and description are the service's default language (the
 * site's). `translations` gives them in other languages, by langcode:
 *
 * @code
 * - key: consulta
 *   label: 'Consulta general'
 *   translations:
 *     en: { label: 'General consultation', description: '…' }
 * @endcode
 *
 * A translation missing is added and one that differs is updated; the
 * translations a row does not name are left alone.
 */
final class ServiceSeeder {

  /**
   * The fields a seed row may set.
   */
  private const FIELDS = ['label', 'description', 'duration', 'buffer', 'price', 'weight', 'status'];

  /**
   * The fields a translation may set.
   */
  private const TRANSLATED = ['label', 'description'];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LanguageManagerInterface $languageManager,
  ) {}

  /**
   * Converges the services.
   *
   * @param list<array<string, mixed>> $rows
   *   Rows with 'key' and any of label, description, duration, buffer, price,
   *   weight, status, translations.
   * @param bool $write
   *   FALSE counts what would change and saves nothing.
   *
   * @return array{created: int, updated: int, unchanged: int}
   *   What changed (or would). A service whose translations changed counts
   *   as updated.
   */
  public function converge(array $rows, bool $write = TRUE): array {
    $storage = $this->entityTypeManager->getStorage('appointment_service');
    $tally = ['created' => 0, 'updated' => 0, 'unchanged' => 0];
    foreach ($rows as $row) {
      $key = (string) ($row['key'] ?? '');
      if ($key === '') {
        throw new \InvalidArgumentException('A service seed row has no key.');
      }
      $translations = $row['translations'] ?? [];
      if (!is_array($translations)) {
        throw new \InvalidArgumentException("Service $key: translations must be a map of langcode to fields.");
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
        $service = $storage->create($values);
        assert($service instanceof AppointmentServiceInterface);
        $this->translate($service, $translations);
        if ($write) {
          $service->save();
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
      $dirty = $this->translate($service, $translations) || $dirty;
      if ($dirty) {
        if ($write) {
          $service->save();
        }
        else {
          // A check changed the loaded copy only: drop it, or a run after
          // it in the same process would compare against the changes.
          $storage->resetCache([$service->id()]);
        }
        $tally['updated']++;
      }
      else {
        $tally['unchanged']++;
      }
    }
    return $tally;
  }

  /**
   * Converges a service's translations; TRUE when one was added or changed.
   *
   * @param \Drupal\appointments\Entity\AppointmentServiceInterface $service
   *   The service, in its default language.
   * @param array<string, mixed> $translations
   *   Fields by langcode.
   */
  private function translate(AppointmentServiceInterface $service, array $translations): bool {
    $key = $service->getKey();
    $dirty = FALSE;
    foreach ($translations as $langcode => $fields) {
      $langcode = (string) $langcode;
      if (!is_array($fields)) {
        throw new \InvalidArgumentException("Service $key: the $langcode translation must be a map of fields.");
      }
      if ($this->languageManager->getLanguage($langcode) === NULL) {
        throw new \InvalidArgumentException("Service $key: language $langcode is not installed on this site.");
      }
      if ($langcode === $service->getUntranslated()->language()->getId()) {
        throw new \InvalidArgumentException("Service $key: $langcode is the service's own language; set label and description on the row itself.");
      }
      $values = array_intersect_key($fields, array_flip(self::TRANSLATED));
      if ($unknown = array_diff(array_keys($fields), self::TRANSLATED)) {
        throw new \InvalidArgumentException("Service $key: a translation sets only label and description, not " . implode(', ', $unknown) . '.');
      }
      if (!$service->hasTranslation($langcode)) {
        // A translation needs a name; without one it starts from the
        // default language's.
        $service->addTranslation($langcode, $values + ['label' => $service->getUntranslated()->label()]);
        $dirty = TRUE;
        continue;
      }
      $translation = $service->getTranslation($langcode);
      foreach ($values as $field => $want) {
        if ((string) $translation->get($field)->value !== (string) $want) {
          $translation->set($field, $want);
          $dirty = TRUE;
        }
      }
    }
    return $dirty;
  }

}
