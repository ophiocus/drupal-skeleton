<?php

declare(strict_types=1);

namespace Drupal\site_seed\Handler;

use Drupal\Component\Gettext\PoItem;
use Drupal\Component\Gettext\PoStreamReader;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\locale\StringStorageInterface;
use Drupal\site_seed\SeedContext;
use Drupal\site_seed\SeedException;

/**
 * Section `translations:`, interface strings from .po files.
 *
 * Each row: file (relative to the Drupal root), langcode. A string missing or
 * different in the database is written; a string an editor customised in the
 * admin is never overwritten.
 */
final class TranslationHandler implements SeedHandlerInterface {

  public function __construct(
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly ?StringStorageInterface $localeStorage = NULL,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function section(): string {
    return 'translations';
  }

  /**
   * {@inheritdoc}
   */
  public function weight(): int {
    return 60;
  }

  /**
   * {@inheritdoc}
   */
  public function applies(): bool {
    return $this->localeStorage !== NULL && $this->moduleHandler->moduleExists('locale');
  }

  /**
   * {@inheritdoc}
   */
  public function converge(array $rows, SeedContext $context): void {
    foreach ($rows as $row) {
      $path = DRUPAL_ROOT . '/' . $row['file'];
      if (!is_file($path)) {
        throw new SeedException('translation file missing: ' . $row['file']);
      }
      $context->count('translations', $this->file($path, (string) $row['langcode'], $context->writes()));
    }
  }

  /**
   * Converges one .po file; returns how many strings it wrote (or would).
   */
  private function file(string $path, string $langcode, bool $write): int {
    $reader = new PoStreamReader();
    $reader->setLangcode($langcode);
    $reader->setURI($path);
    $reader->open();
    $lids = [];
    while ($item = $reader->readItem()) {
      $source = $item->isPlural() ? implode(PoItem::DELIMITER, $item->getSource()) : $item->getSource();
      $translation = $item->isPlural() ? implode(PoItem::DELIMITER, $item->getTranslation()) : $item->getTranslation();
      if ($source === '' || $translation === '') {
        continue;
      }
      $context = (string) $item->getContext();
      $target = $this->localeStorage->findTranslation([
        'source' => $source,
        'context' => $context,
        'language' => $langcode,
      ]);
      if ($target && (!empty($target->customized) || (string) ($target->translation ?? '') === $translation)) {
        continue;
      }
      if (!$write) {
        $lids[] = 0;
        continue;
      }
      if (!$target) {
        $lid = $this->localeStorage->createString(['source' => $source, 'context' => $context])->save()->lid;
        $target = $this->localeStorage->createTranslation(['lid' => $lid, 'language' => $langcode]);
      }
      // A source found without a translation in this language is a new
      // translation row; one found with it is an update of that row.
      $target->language = $langcode;
      $target->setString($translation);
      // LOCALE_NOT_CUSTOMIZED.
      $target->customized = 0;
      $target->save();
      $lids[] = (int) $target->lid;
    }
    $reader->close();
    if ($write && $lids) {
      $this->moduleHandler->loadInclude('locale', 'module');
      if (function_exists('_locale_refresh_translations')) {
        _locale_refresh_translations([$langcode], $lids);
      }
    }
    return count($lids);
  }

}
