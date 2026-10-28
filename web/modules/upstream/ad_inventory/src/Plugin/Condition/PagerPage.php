<?php

declare(strict_types=1);

namespace Drupal\ad_inventory\Plugin\Condition;

use Drupal\Core\Condition\Attribute\Condition;
use Drupal\Core\Condition\ConditionPluginBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Page 1 of a paged list, or its later pages (from the ?page parameter).
 *
 * Lets page 1 and the later pages of one list carry different spaces, sold
 * apart. The page parameter is part of the URL, so this varies by URL only.
 */
#[Condition(
  id: 'ad_inventory_pager_page',
  label: new TranslatableMarkup('Page of a paged list'),
)]
final class PagerPage extends ConditionPluginBase implements ContainerFactoryPluginInterface {

  /**
   * The request stack.
   */
  protected RequestStack $requestStack;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = new static($configuration, $plugin_id, $plugin_definition);
    $instance->requestStack = $container->get('request_stack');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return ['which' => ''] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form['which'] = [
      '#type' => 'radios',
      '#title' => $this->t('Pages'),
      '#options' => [
        '' => $this->t('Any page'),
        'first' => $this->t('Page 1 only'),
        'later' => $this->t('Later pages only'),
      ],
      '#default_value' => $this->configuration['which'],
    ];
    return parent::buildConfigurationForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state): void {
    $this->configuration['which'] = (string) $form_state->getValue('which');
    parent::submitConfigurationForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function evaluate(): bool {
    $which = (string) $this->configuration['which'];
    if ($which === '') {
      return TRUE;
    }
    $page = (int) ($this->requestStack->getCurrentRequest()?->query->get('page') ?? 0);
    return $which === 'first' ? $page < 1 : $page >= 1;
  }

  /**
   * {@inheritdoc}
   */
  public function summary(): TranslatableMarkup {
    return match ((string) $this->configuration['which']) {
      'first' => $this->t('Page 1 only'),
      'later' => $this->t('Later pages only'),
      default => $this->t('Any page'),
    };
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts(): array {
    return array_merge(parent::getCacheContexts(), ['url.query_args:page']);
  }

}
