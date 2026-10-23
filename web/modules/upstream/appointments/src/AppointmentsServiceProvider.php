<?php

declare(strict_types=1);

namespace Drupal\appointments;

use Drupal\appointments\SiteSeed\AppointmentServicesHandler;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderInterface;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Adds the site_seed handler for `appointment_services` where site_seed is on.
 *
 * A services.yml entry cannot be conditional, and the handler implements
 * site_seed's interface, so it is registered here instead.
 */
final class AppointmentsServiceProvider implements ServiceProviderInterface {

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    $modules = $container->getParameter('container.modules');
    if (!is_array($modules) || !isset($modules['site_seed'])) {
      return;
    }
    $container->register('appointments.site_seed_handler', AppointmentServicesHandler::class)
      ->addArgument(new Reference('appointments.seeder'))
      ->addArgument(new Reference('entity_type.manager'))
      ->addTag('site_seed.handler');
  }

}
