<?php

declare(strict_types=1);

namespace Drupal\ad_inventory\Form;

use Drupal\ad_inventory\Entity\AdCreative;
use Drupal\ad_inventory\Entity\AdFormat;
use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\file\FileInterface;
use Drupal\media\MediaInterface;
use Drupal\media\MediaTypeInterface;

/**
 * Uploads a creative: one image per format, each at twice the format's size.
 *
 * Each upload becomes a media image. A creative can be approved only when
 * every format it will be booked in has a rendition; the booking form checks
 * the spaces' formats, and house banners are checked against their spaces.
 */
final class AdCreativeForm extends EntityForm {

  /**
   * Largest upload per rendition, in bytes.
   */
  private const MAX_BYTES = 100 * 1024;

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);
    $form['#attached']['library'][] = 'ad_inventory/admin';
    /** @var \Drupal\ad_inventory\Entity\AdCreative $creative */
    $creative = $this->entity;
    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Name'),
      '#default_value' => $creative->label(),
      '#required' => TRUE,
    ];
    $form['advertiser'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Advertiser'),
      '#default_value' => $creative->get('advertiser')->value,
    ];
    $form['kind'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Kind'),
      '#description' => $this->t('"plain", or a kind the site defines (for example "show" for a single show).'),
      '#default_value' => $creative->kind(),
      '#required' => TRUE,
    ];
    $form['target_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Link'),
      '#description' => $this->t('An https:// address, or a path on this site starting with /.'),
      '#default_value' => $creative->target(),
      '#maxlength' => 2048,
      '#required' => TRUE,
    ];
    $form['alt'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Alternative text'),
      '#description' => $this->t('What the banner says, in words.'),
      '#default_value' => $creative->get('alt')->value,
      '#required' => TRUE,
    ];
    $form['caption'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Caption'),
      '#description' => $this->t("A short line under the banner, for example the registration code a law asks a show's promotion to print."),
      '#default_value' => $creative->get('caption')->value,
    ];
    $form['promoted_type'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Promoted entity type'),
      '#description' => $this->t('For a creative that promotes one page of this site (for example "node").'),
      '#default_value' => $creative->get('promoted_type')->value,
    ];
    $form['promoted_id'] = [
      '#type' => 'number',
      '#title' => $this->t('Promoted entity id'),
      '#default_value' => $creative->get('promoted_id')->value,
      '#min' => 1,
    ];
    $form['house'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('House banner: fills spaces no booking holds'),
      '#default_value' => $creative->isHouse(),
    ];
    $slots = [];
    foreach ($this->entityTypeManager->getStorage('ad_slot')->loadMultiple() as $id => $slot) {
      $slots[$id] = $slot->label();
    }
    $form['house_slots'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('House spaces'),
      '#description' => $this->t('None checked: every space.'),
      '#options' => $slots,
      '#default_value' => $creative->houseSlots(),
      '#states' => ['visible' => [':input[name="house"]' => ['checked' => TRUE]]],
    ];
    $form['renditions'] = [
      '#type' => 'details',
      '#title' => $this->t('Renditions'),
      '#open' => TRUE,
      '#tree' => TRUE,
      '#description' => $this->t('One image per format, at twice its size (for 728×90 upload 1456×180), PNG, JPEG or WebP, at most 100 KB. A phone rendition is designed for phones, not the desktop image shrunk.'),
      '#attributes' => ['class' => ['ad-inventory-renditions']],
    ];
    foreach ($this->formats() as $id => $format) {
      $media = $creative->rendition($id);
      $current = $media instanceof MediaInterface ? $this->file($media) : NULL;
      $form['renditions'][$id] = [
        '#type' => 'managed_file',
        '#title' => $format->label() . ' ' . $format->size(),
        '#upload_location' => 'public://ad-inventory/',
        '#upload_validators' => [
          'FileExtension' => ['extensions' => 'png jpg jpeg webp'],
          'FileSizeLimit' => ['fileLimit' => self::MAX_BYTES],
        ],
        '#default_value' => $current !== NULL ? [$current->id()] : [],
        '#description' => $this->t('Upload @w×@h.', ['@w' => $format->width() * 2, '@h' => $format->height() * 2]),
      ];
    }
    $form['state'] = [
      '#type' => 'radios',
      '#title' => $this->t('State'),
      '#options' => [
        AdCreative::DRAFT => $this->t('Draft: only staff who preview drafts see it'),
        AdCreative::APPROVED => $this->t('Approved'),
      ],
      '#default_value' => $creative->get('state')->value ?: AdCreative::DRAFT,
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);
    $target = trim((string) $form_state->getValue('target_url'));
    if (!str_starts_with($target, '/') && parse_url($target, PHP_URL_SCHEME) !== 'https' && parse_url($target, PHP_URL_SCHEME) !== 'http') {
      $form_state->setErrorByName('target_url', $this->t('Use an https:// address or a path starting with /.'));
    }
    $files = $this->entityTypeManager->getStorage('file');
    foreach ($this->formats() as $id => $format) {
      $fid = $form_state->getValue(['renditions', $id, 0]);
      if (!$fid) {
        continue;
      }
      $file = $files->load($fid);
      $size = $file instanceof FileInterface ? @getimagesize($file->getFileUri()) : FALSE;
      if ($size === FALSE || !$format->fits((int) $size[0], (int) $size[1])) {
        $form_state->setErrorByName("renditions][$id", $this->t('@format needs @w×@h (the format at twice its size, same proportion within 1%).', [
          '@format' => $format->label(),
          '@w' => $format->width() * 2,
          '@h' => $format->height() * 2,
        ]));
      }
    }
    if ($form_state->getValue('state') === AdCreative::APPROVED && $form_state->getValue('house')) {
      $needed = [];
      $slots = array_filter((array) $form_state->getValue('house_slots'));
      foreach ($this->entityTypeManager->getStorage('ad_slot')->loadMultiple($slots ?: NULL) as $slot) {
        /** @var \Drupal\ad_inventory\Entity\AdSlot $slot */
        $needed = array_merge($needed, $slot->formats());
      }
      foreach (array_unique($needed) as $id) {
        if (!$form_state->getValue(['renditions', $id, 0])) {
          $form_state->setErrorByName("renditions][$id", $this->t('A house banner for these spaces needs the @id rendition before it can be approved.', ['@id' => $id]));
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function copyFormValuesToEntity(EntityInterface $entity, array $form, FormStateInterface $form_state): void {
    assert($entity instanceof AdCreative);
    foreach (['label', 'advertiser', 'kind', 'target_url', 'alt', 'caption', 'promoted_type', 'state'] as $field) {
      $entity->set($field, trim((string) $form_state->getValue($field)));
    }
    $entity->set('promoted_id', $form_state->getValue('promoted_id') ?: NULL);
    $entity->set('house', (bool) $form_state->getValue('house'));
    $entity->setHouseSlots(array_values(array_filter((array) $form_state->getValue('house_slots'))));
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    /** @var \Drupal\ad_inventory\Entity\AdCreative $creative */
    $creative = $this->entity;
    $files = $this->entityTypeManager->getStorage('file');
    $media_storage = $this->entityTypeManager->getStorage('media');
    $type = $this->entityTypeManager->getStorage('media_type')->load('image');
    $source_field = $type instanceof MediaTypeInterface ? $type->getSource()->getSourceFieldDefinition($type)?->getName() : NULL;
    foreach ($this->formats() as $id => $format) {
      $fid = $form_state->getValue(['renditions', $id, 0]);
      $current = $creative->rendition($id);
      if (!$fid) {
        continue;
      }
      if ($current instanceof MediaInterface && (int) ($this->file($current)?->id()) === (int) $fid) {
        continue;
      }
      $file = $files->load($fid);
      if (!$file instanceof FileInterface || $source_field === NULL) {
        continue;
      }
      $file->setPermanent();
      $file->save();
      $media = $media_storage->create([
        'bundle' => 'image',
        'name' => $creative->label() . ' — ' . $format->label(),
        $source_field => ['target_id' => $file->id(), 'alt' => $creative->get('alt')->value],
        'status' => TRUE,
      ]);
      $media->save();
      $creative->setRendition($id, (int) $media->id());
    }
    $result = parent::save($form, $form_state);
    $this->messenger()->addStatus($this->t('Saved the creative %label.', ['%label' => $creative->label()]));
    $form_state->setRedirectUrl($creative->toUrl('collection'));
    return $result;
  }

  /**
   * Formats by id, in their order.
   *
   * @return array<string, \Drupal\ad_inventory\Entity\AdFormat>
   *   Formats.
   */
  private function formats(): array {
    $formats = array_filter($this->entityTypeManager->getStorage('ad_format')->loadMultiple(), static fn ($f) => $f instanceof AdFormat);
    uasort($formats, static fn (AdFormat $a, AdFormat $b) => $a->get('weight') <=> $b->get('weight'));
    return $formats;
  }

  /**
   * A media image's file.
   */
  private function file(MediaInterface $media): ?FileInterface {
    $field = $media->getSource()->getConfiguration()['source_field'] ?? '';
    $file = $field !== '' ? $media->get($field)->entity : NULL;
    return $file instanceof FileInterface ? $file : NULL;
  }

}
