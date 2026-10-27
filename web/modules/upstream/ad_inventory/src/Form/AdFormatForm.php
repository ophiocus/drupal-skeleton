<?php

declare(strict_types=1);

namespace Drupal\ad_inventory\Form;

use Drupal\ad_inventory\Entity\AdFormat;
use Drupal\ad_inventory\Entity\AdFormatInterface;
use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Form\FormStateInterface;

/**
 * Adds or edits an ad format.
 */
final class AdFormatForm extends EntityForm {

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state) {
    $form = parent::form($form, $form_state);
    $format = $this->entity;
    assert($format instanceof AdFormatInterface);
    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Name'),
      '#default_value' => $format->label(),
      '#required' => TRUE,
    ];
    $form['id'] = [
      '#type' => 'machine_name',
      '#default_value' => $format->id(),
      '#machine_name' => ['exists' => [AdFormat::class, 'load']],
      '#disabled' => !$format->isNew(),
    ];
    $form['width'] = [
      '#type' => 'number',
      '#title' => $this->t('Width (px)'),
      '#min' => 1,
      '#required' => TRUE,
      '#default_value' => $format->width() ?: NULL,
    ];
    $form['height'] = [
      '#type' => 'number',
      '#title' => $this->t('Height (px)'),
      '#min' => 1,
      '#required' => TRUE,
      '#default_value' => $format->height() ?: NULL,
    ];
    $lines = [];
    foreach ($format->networkSizes() as $row) {
      $lines[] = sprintf('%d %dx%d', $row['min_width'], $row['width'], $row['height']);
    }
    $form['network_sizes'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Network unit size per viewport width'),
      '#description' => $this->t('One per line: the viewport width it applies from, then the size, e.g. <code>0 468x60</code> and <code>728 728x90</code>. Empty: no network unit fits this format (email).'),
      '#default_value' => implode("\n", $lines),
      '#rows' => 3,
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);
    if (self::parseSizes((string) $form_state->getValue('network_sizes')) === NULL) {
      $form_state->setErrorByName('network_sizes', $this->t('Write each line as "MIN WIDTHxHEIGHT".'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function buildEntity(array $form, FormStateInterface $form_state) {
    $format = parent::buildEntity($form, $form_state);
    $format->set('width', (int) $form_state->getValue('width'));
    $format->set('height', (int) $form_state->getValue('height'));
    $format->set('network_sizes', self::parseSizes((string) $form_state->getValue('network_sizes')) ?? []);
    return $format;
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state) {
    $status = parent::save($form, $form_state);
    $this->messenger()->addStatus($this->t('Saved the format %label and its image styles.', ['%label' => $this->entity->label()]));
    $form_state->setRedirectUrl($this->entity->toUrl('collection'));
    return $status;
  }

  /**
   * Network sizes from "MIN WxH" lines, or NULL when a line is malformed.
   */
  public static function parseSizes(string $text): ?array {
    $rows = [];
    foreach (preg_split('/\R/', $text) ?: [] as $line) {
      $line = trim($line);
      if ($line === '') {
        continue;
      }
      if (!preg_match('/^(\d+)\s+(\d+)\s*[x×]\s*(\d+)$/u', $line, $m)) {
        return NULL;
      }
      $rows[] = ['min_width' => (int) $m[1], 'width' => (int) $m[2], 'height' => (int) $m[3]];
    }
    return $rows;
  }

}
