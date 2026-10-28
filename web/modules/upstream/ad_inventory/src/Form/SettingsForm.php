<?php

declare(strict_types=1);

namespace Drupal\ad_inventory\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * The label, the phone breakpoint and the bridge to the site's consent.
 */
final class SettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'ad_inventory_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['ad_inventory.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('ad_inventory.settings');
    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Label above every space'),
      '#description' => $this->t('Google allows only "Advertisements" or "Sponsored Links" (or their translation) above its units; a site that will run Google units keeps one of those. Translate it through the interface translation.'),
      '#default_value' => $config->get('label'),
      '#required' => TRUE,
    ];
    $form['breakpoint'] = [
      '#type' => 'number',
      '#title' => $this->t('Phone breakpoint (px)'),
      '#description' => $this->t('At and below this width the phone formats apply. Match the theme: the width at which it turns two columns into one.'),
      '#default_value' => $config->get('breakpoint'),
      '#min' => 320,
      '#max' => 1600,
    ];
    $form['consent'] = [
      '#type' => 'details',
      '#title' => $this->t('Consent bridge'),
      '#open' => TRUE,
      '#description' => $this->t("Every space waits for the reader's advertising consent, read in the browser from the site's own consent tool. The page itself never varies by reader."),
    ];
    $consent = (array) $config->get('consent');
    $form['consent']['global'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Global object'),
      '#description' => $this->t("window.&lt;name&gt; holding the reader's choice."),
      '#default_value' => $consent['global'] ?? '',
      '#required' => TRUE,
    ];
    $form['consent']['category'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Advertising property'),
      '#description' => $this->t('The property that is true when advertising is granted.'),
      '#default_value' => $consent['category'] ?? '',
      '#required' => TRUE,
    ];
    $form['consent']['event'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Event'),
      '#description' => $this->t('DOM event dispatched on document when the reader answers; its detail carries the choice.'),
      '#default_value' => $consent['event'] ?? '',
      '#required' => TRUE,
    ];
    $form['consent']['open_attribute'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Reopen attribute'),
      '#description' => $this->t('An attribute that makes a link reopen the cookie settings; the "change my cookies" link carries it.'),
      '#default_value' => $consent['open_attribute'] ?? '',
      '#required' => TRUE,
    ];
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    foreach (['global', 'category', 'event', 'open_attribute'] as $key) {
      if (preg_match('/^[A-Za-z_][A-Za-z0-9_:.-]*$/', (string) $form_state->getValue($key)) !== 1) {
        $form_state->setErrorByName($key, $this->t('Use letters, digits, underscores, colons, dots and hyphens only.'));
      }
    }
    parent::validateForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('ad_inventory.settings')
      ->set('label', trim((string) $form_state->getValue('label')))
      ->set('breakpoint', (int) $form_state->getValue('breakpoint'))
      ->set('consent', [
        'global' => (string) $form_state->getValue('global'),
        'category' => (string) $form_state->getValue('category'),
        'event' => (string) $form_state->getValue('event'),
        'open_attribute' => (string) $form_state->getValue('open_attribute'),
      ])
      ->save();
    parent::submitForm($form, $form_state);
  }

}
