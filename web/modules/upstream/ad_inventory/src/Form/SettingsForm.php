<?php

declare(strict_types=1);

namespace Drupal\ad_inventory\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * The label, the breakpoint, the consent bridge and the blocked banner.
 */
final class SettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'ad_inventory_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['ad_inventory.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('ad_inventory.settings');
    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Label above every slot'),
      '#description' => $this->t('One word for the whole site, whoever fills the box. A site running Google AdSense must use one of Google\'s: "Advertisements" or "Sponsored Links" ("Anuncios" or "Enlaces patrocinados").'),
      '#default_value' => $config->get('label'),
      '#required' => TRUE,
    ];
    $form['breakpoint'] = [
      '#type' => 'number',
      '#title' => $this->t('Breakpoint (px)'),
      '#description' => $this->t('From this viewport width the desktop format applies; under it the phone format. 0 uses the desktop format everywhere.'),
      '#min' => 0,
      '#default_value' => $config->get('breakpoint'),
    ];
    $form['timezone'] = [
      '#type' => 'select',
      '#title' => $this->t('Time zone of booking days'),
      '#options' => array_combine(\DateTimeZone::listIdentifiers(), \DateTimeZone::listIdentifiers()),
      '#empty_option' => $this->t("- The site's default time zone -"),
      '#default_value' => $config->get('timezone'),
    ];
    $form['consent'] = [
      '#type' => 'details',
      '#title' => $this->t('Consent bridge'),
      '#open' => TRUE,
      '#tree' => TRUE,
      '#description' => $this->t("The module owns no consent banner and no cookie. It reads the site's own signal in the browser: a creative, network script or beacon is requested only once <code>window.&lt;object&gt;.&lt;property&gt;</code> is <code>true</code>, checked at load and again on the event."),
    ];
    $form['consent']['global'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Object on window'),
      '#default_value' => $config->get('consent.global'),
    ];
    $form['consent']['property'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Property that holds the advertising grant'),
      '#default_value' => $config->get('consent.property'),
    ];
    $form['consent']['event'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Event on document when the reader chooses'),
      '#default_value' => $config->get('consent.event'),
    ];
    $form['blocked'] = [
      '#type' => 'details',
      '#title' => $this->t('Blocked banner'),
      '#open' => TRUE,
      '#tree' => TRUE,
      '#description' => $this->t('What every slot shows until the reader allows advertising, in the same box: fixed text and one link that reopens the consent settings. No image.'),
    ];
    $form['blocked']['text'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Text'),
      '#default_value' => $config->get('blocked.text'),
      '#maxlength' => 255,
    ];
    $form['blocked']['link_text'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Link text'),
      '#default_value' => $config->get('blocked.link_text'),
    ];
    $form['blocked']['link_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Link address'),
      '#description' => $this->t("Where the site's consent settings open (an anchor its banner listens for, or a page)."),
      '#default_value' => $config->get('blocked.link_url'),
    ];
    $kinds = [];
    foreach ((array) $config->get('kinds') as $kind) {
      $kinds[] = $kind['id'] . '|' . $kind['label'];
    }
    $form['kinds'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Creative kinds besides "plain"'),
      '#description' => $this->t("One per line, <code>id|Label</code> (e.g. <code>event|Promoted event</code>). A slot may take only some kinds; a validation subscriber may check a kind's rules."),
      '#default_value' => implode("\n", $kinds),
      '#rows' => 3,
    ];
    $form['email_alt'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Alternative text of email images'),
      '#description' => $this->t('Fixed in the snippet, since the creative changes after the mail is sent. Empty uses the label.'),
      '#default_value' => $config->get('email_alt'),
    ];
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);
    foreach (['global', 'property'] as $key) {
      $value = trim((string) $form_state->getValue(['consent', $key]));
      if ($value !== '' && !preg_match('/^[A-Za-z_$][A-Za-z0-9_$]*$/', $value)) {
        $form_state->setErrorByName('consent][' . $key, $this->t('Use a JavaScript identifier.'));
      }
    }
    foreach (self::parseKinds((string) $form_state->getValue('kinds')) as $kind) {
      if (!preg_match('/^[a-z0-9_]+$/', $kind['id']) || $kind['id'] === 'plain') {
        $form_state->setErrorByName('kinds', $this->t('Kind ids are lowercase letters, digits and underscores, and "plain" is taken.'));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('ad_inventory.settings')
      ->set('label', trim((string) $form_state->getValue('label')))
      ->set('breakpoint', max(0, (int) $form_state->getValue('breakpoint')))
      ->set('timezone', (string) $form_state->getValue('timezone'))
      ->set('consent', [
        'global' => trim((string) $form_state->getValue(['consent', 'global'])),
        'property' => trim((string) $form_state->getValue(['consent', 'property'])),
        'event' => trim((string) $form_state->getValue(['consent', 'event'])),
      ])
      ->set('blocked', [
        'text' => trim((string) $form_state->getValue(['blocked', 'text'])),
        'link_text' => trim((string) $form_state->getValue(['blocked', 'link_text'])),
        'link_url' => trim((string) $form_state->getValue(['blocked', 'link_url'])),
      ])
      ->set('kinds', self::parseKinds((string) $form_state->getValue('kinds')))
      ->set('email_alt', trim((string) $form_state->getValue('email_alt')))
      ->save();
    parent::submitForm($form, $form_state);
  }

  /**
   * Kinds from "id|Label" lines.
   *
   * @return array<int, array{id: string, label: string}>
   *   Kinds.
   */
  public static function parseKinds(string $text): array {
    $kinds = [];
    foreach (preg_split('/\R/', $text) ?: [] as $line) {
      $line = trim($line);
      if ($line === '') {
        continue;
      }
      [$id, $label] = array_pad(array_map('trim', explode('|', $line, 2)), 2, '');
      $kinds[] = ['id' => $id, 'label' => $label !== '' ? $label : $id];
    }
    return $kinds;
  }

}
