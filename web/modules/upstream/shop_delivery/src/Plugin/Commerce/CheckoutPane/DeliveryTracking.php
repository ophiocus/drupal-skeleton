<?php

declare(strict_types=1);

namespace Drupal\shop_delivery\Plugin\Commerce\CheckoutPane;

use Drupal\commerce_checkout\Attribute\CommerceCheckoutPane;
use Drupal\commerce_checkout\Plugin\Commerce\CheckoutFlow\CheckoutFlowInterface;
use Drupal\commerce_checkout\Plugin\Commerce\CheckoutPane\CheckoutPaneBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\shop_delivery\Deliveries;
use Drupal\shop_delivery\TrackingToken;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The link to follow the delivery, on the checkout's last page.
 *
 * Guest checkout leaves the customer no account page, so this link is how
 * they come back to see where their order is.
 */
#[CommerceCheckoutPane(
  id: 'shop_delivery_tracking',
  label: new TranslatableMarkup('Delivery tracking link'),
  default_step: 'complete',
)]
final class DeliveryTracking extends CheckoutPaneBase {

  /**
   * The deliveries.
   */
  protected Deliveries $deliveries;

  /**
   * The tracking links.
   */
  protected TrackingToken $tokens;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition, ?CheckoutFlowInterface $checkout_flow = NULL) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition, $checkout_flow);
    $instance->deliveries = $container->get('shop_delivery.deliveries');
    $instance->tokens = $container->get('shop_delivery.tracking_token');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function isVisible() {
    return $this->deliveries->ofOrder($this->order) !== [];
  }

  /**
   * {@inheritdoc}
   */
  public function buildPaneForm(array $pane_form, FormStateInterface $form_state, array &$complete_form) {
    $pane_form['link'] = [
      '#type' => 'inline_template',
      '#template' => '<p class="delivery-track__cta">Sigue tu domicilio aquí: <a href="{{ url }}">{{ url }}</a></p><p>Guarda este enlace: muestra en qué va tu pedido.</p>',
      '#context' => ['url' => $this->tokens->url($this->order)->toString()],
    ];
    return $pane_form;
  }

}
