<?php

declare(strict_types=1);

namespace Drupal\ad_inventory\Controller;

use Drupal\ad_inventory\StatusChecker;
use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The status page: every closed gate, by name.
 */
final class StatusController extends ControllerBase {

  public function __construct(
    private readonly StatusChecker $status,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('ad_inventory.status'));
  }

  /**
   * Builds the page.
   */
  public function page(): array {
    $rows = [];
    foreach ($this->status->gates() as $gate) {
      $rows[] = [
        'class' => ['color-' . ($gate['severity'] === 'ok' ? 'success' : $gate['severity'])],
        'data' => [$gate['severity'], $gate['gate'], $gate['message']],
      ];
    }
    return [
      'gates' => [
        '#type' => 'table',
        '#header' => [$this->t('Severity'), $this->t('Gate'), $this->t('What it means')],
        '#rows' => $rows,
        '#empty' => $this->t('Nothing to report.'),
      ],
      'drush' => [
        '#markup' => '<p>' . $this->t('The same list: <code>drush ad-inventory:status</code> (exits 1 while any gate is an error).') . '</p>',
      ],
      // Gates depend on the environment and on files; never cached.
      '#cache' => ['max-age' => 0],
    ];
  }

}
