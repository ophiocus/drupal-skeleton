<?php

declare(strict_types=1);

namespace Drupal\appointments\SiteSeed;

use Drupal\appointments\ServiceSeeder;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\site_seed\Handler\SeedHandlerInterface;
use Drupal\site_seed\SeedContext;
use Drupal\site_seed\SeedException;

/**
 * The `appointment_services` section of a site's seed file.
 *
 * Registered by AppointmentsServiceProvider only where site_seed is installed,
 * so appointments keeps working on a site without it.
 */
final class AppointmentServicesHandler implements SeedHandlerInterface {

  public function __construct(
    private readonly ServiceSeeder $seeder,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function section(): string {
    return 'appointment_services';
  }

  /**
   * {@inheritdoc}
   */
  public function weight(): int {
    return 100;
  }

  /**
   * {@inheritdoc}
   */
  public function applies(): bool {
    return $this->entityTypeManager->hasDefinition('appointment_service');
  }

  /**
   * {@inheritdoc}
   */
  public function converge(array $rows, SeedContext $context): void {
    try {
      $tally = $this->seeder->converge(array_values($rows), $context->writes());
    }
    catch (\InvalidArgumentException $e) {
      throw new SeedException($e->getMessage(), 0, $e);
    }
    $context->count('services', $tally['created']);
    $context->count('updated', $tally['updated']);
    $context->count('unchanged', $tally['unchanged']);
  }

}
