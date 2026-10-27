<?php

declare(strict_types=1);

namespace Drupal\ad_inventory\Controller;

use Drupal\ad_inventory\BeaconPayload;
use Drupal\ad_inventory\Counter;
use Drupal\ad_inventory\Entity\AdCreativeInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The beacon and the click redirect. Neither starts a session.
 */
final class CountController implements ContainerInjectionInterface {

  /**
   * The largest beacon body read, in bytes.
   */
  public const MAX_BODY = 8192;

  public function __construct(
    private readonly Counter $counter,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('ad_inventory.counter'));
  }

  /**
   * POST /ad/v: counts a page's viewed slots and unfilled units.
   */
  public function beacon(Request $request): Response {
    $body = (string) $request->getContent();
    if (strlen($body) <= self::MAX_BODY) {
      $this->counter->beacon(BeaconPayload::parse($body));
    }
    return new Response('', 204, ['Cache-Control' => 'no-store']);
  }

  /**
   * GET /ad/c/{creative}: counts a click, then redirects to the target.
   */
  public function click(AdCreativeInterface $ad_creative, Request $request): TrustedRedirectResponse {
    $target = $ad_creative->targetUrl();
    if (!$ad_creative->isPublished() || !preg_match('#^https?://#i', $target)) {
      throw new NotFoundHttpException();
    }
    $slot = (string) $request->query->get('slot', '');
    $this->counter->click(preg_match('/^[a-z0-9_]{1,64}$/', $slot) ? $slot : '', (string) $ad_creative->id());
    $response = new TrustedRedirectResponse($target, 302);
    $response->headers->set('Cache-Control', 'no-store');
    $response->getCacheableMetadata()->setCacheMaxAge(0);
    return $response;
  }

}
