<?php

declare(strict_types=1);

namespace Drupal\ad_inventory\Plugin\Condition;

use Drupal\Core\Condition\Attribute\Condition;
use Drupal\Core\Condition\ConditionPluginBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The page is one of some routes.
 *
 * For spaces on pages a path pattern cannot tell apart (a tour at
 * /eventos/{act} and a show at /eventos/{act}/{date}).
 */
#[Condition(
  id: 'ad_inventory_route',
  label: new TranslatableMarkup('Route'),
)]
final class RouteName extends ConditionPluginBase implements ContainerFactoryPluginInterface {

  /**
   * The current route.
   */
  protected RouteMatchInterface $routeMatch;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = new static($configuration, $plugin_id, $plugin_definition);
    $instance->routeMatch = $container->get('current_route_match');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return ['routes' => []] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form['routes'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Route names'),
      '#description' => $this->t('One per line. Empty: every page.'),
      '#default_value' => implode("\n", (array) $this->configuration['routes']),
      '#rows' => 3,
    ];
    return parent::buildConfigurationForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state): void {
    $lines = preg_split('/\R/', (string) $form_state->getValue('routes')) ?: [];
    $this->configuration['routes'] = array_values(array_filter(array_map('trim', $lines)));
    parent::submitConfigurationForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function evaluate(): bool {
    $routes = (array) $this->configuration['routes'];
    return $routes === [] || in_array((string) $this->routeMatch->getRouteName(), $routes, TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function summary(): TranslatableMarkup {
    return $this->t('Routes: @routes', ['@routes' => implode(', ', (array) $this->configuration['routes']) ?: '*']);
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts(): array {
    return array_merge(parent::getCacheContexts(), ['route.name']);
  }

}
