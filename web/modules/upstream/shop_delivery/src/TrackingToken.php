<?php

declare(strict_types=1);

namespace Drupal\shop_delivery;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\Component\Utility\Crypt;
use Drupal\Core\PrivateKey;
use Drupal\Core\Url;

/**
 * The secret in the link a customer follows their order with.
 *
 * Guest checkout has no account to log in to, so the link is the access:
 * an HMAC of the order's id and UUID under the site's private key. It
 * cannot be guessed from the order number, and it never expires (the page
 * only ever shows that one order's delivery).
 */
final class TrackingToken {

  public function __construct(
    private readonly PrivateKey $privateKey,
  ) {}

  /**
   * The token for one order.
   */
  public function token(OrderInterface $order): string {
    return substr(Crypt::hmacBase64('shop_delivery:' . $order->id() . ':' . $order->uuid(), $this->privateKey->get()), 0, 24);
  }

  /**
   * Whether a token is the order's.
   */
  public function valid(OrderInterface $order, string $token): bool {
    return hash_equals($this->token($order), $token);
  }

  /**
   * The customer's tracking page for an order.
   */
  public function url(OrderInterface $order): Url {
    return Url::fromRoute('shop_delivery.tracking', [
      'commerce_order' => $order->id(),
      'token' => $this->token($order),
    ], ['absolute' => TRUE]);
  }

}
