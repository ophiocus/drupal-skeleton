<?php

declare(strict_types=1);

namespace Drupal\ga4_report\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\ga4_report\Refresher;
use Drupal\ga4_report\ReportException;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The "Refresh now" button on the report page.
 */
final class RefreshForm extends FormBase {

  public function __construct(
    protected Refresher $refresher,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('ga4_report.refresher'));
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'ga4_report_refresh';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['refresh'] = [
      '#type' => 'submit',
      '#value' => $this->t('Refresh now'),
      '#disabled' => !$this->refresher->isConfigured(),
      '#button_type' => 'primary',
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    try {
      foreach ($this->refresher->refresh() as $outcome) {
        if ($outcome['ok']) {
          $this->messenger()->addStatus($outcome['message']);
        }
        else {
          $this->messenger()->addError($outcome['message']);
        }
      }
    }
    catch (ReportException $e) {
      $this->messenger()->addError($e->getMessage());
    }
  }

}
