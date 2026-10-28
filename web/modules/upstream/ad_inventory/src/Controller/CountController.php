<?php

declare(strict_types=1);

namespace Drupal\ad_inventory\Controller;

use Drupal\ad_inventory\AdCounter;
use Drupal\ad_inventory\Entity\AdCreative;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Routing\LocalRedirectResponse;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The viewability beacon and the counted click.
 *
 * Neither starts a session, sets a cookie or stores anything about the
 * reader: they add one to a daily total and answer. The pages themselves
 * stay cached; only these small requests reach the server.
 */
final class CountController extends ControllerBase {

  /**
   * Pairs accepted in one beacon.
   */
  private const MAX_PAIRS = 24;

  public function __construct(
    private readonly AdCounter $counter,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('ad_inventory.counter'));
  }

  /**
   * POST /ad/v with {"v": [[space, creative, source], …]}: spaces seen.
   *
   * A space counts as seen when at least half of it was on screen for one
   * second, after the reader's consent; the browser sends every space it saw
   * once, when the page is left.
   */
  public function beacon(Request $request): Response {
    $payload = json_decode((string) $request->getContent(), TRUE);
    $pairs = is_array($payload) && is_array($payload['v'] ?? NULL) ? array_slice($payload['v'], 0, self::MAX_PAIRS) : [];
    $seen = [];
    foreach ($pairs as $pair) {
      if (!is_array($pair) || count($pair) !== 3) {
        continue;
      }
      [$slot, $creative, $source] = [(string) $pair[0], (int) $pair[1], (string) $pair[2]];
      $key = $slot . '|' . $creative . '|' . $source;
      // Creative 0 stands for a provider's unit; bookings and house banners
      // always name their creative.
      $provider = str_starts_with($source, 'provider:');
      if (isset($seen[$key]) || ($creative === 0) !== $provider || !$this->known($slot, $creative)) {
        continue;
      }
      $seen[$key] = TRUE;
      $this->counter->view($slot, $creative, $source);
    }
    $response = new Response('', 204);
    $response->headers->set('Cache-Control', 'no-store');
    return $response;
  }

  /**
   * Counts a click on /ad/c/{ad_creative}?s=space&f=source, then follows it.
   */
  public function click(AdCreative $ad_creative, Request $request): Response {
    $slot = (string) $request->query->get('s', '');
    $source = (string) $request->query->get('f', 'booking');
    if ($this->known($slot, (int) $ad_creative->id())) {
      $this->counter->click($slot, (int) $ad_creative->id(), $source);
    }
    $response = self::follow($ad_creative->target());
    $response->headers->set('Cache-Control', 'no-store');
    $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
    return $response;
  }

  /**
   * Whether a space exists and, for a creative, the creative exists.
   */
  private function known(string $slot, int $creative): bool {
    if ($slot === '' || $this->entityTypeManager()->getStorage('ad_slot')->load($slot) === NULL) {
      return FALSE;
    }
    return $creative === 0 || $this->entityTypeManager()->getStorage('ad_creative')->load($creative) !== NULL;
  }

  /**
   * A 302 to a creative's target, or a 404 when the target is not safe.
   */
  public static function follow(string $target): Response {
    $target = self::safeTarget($target);
    if ($target === NULL) {
      return new Response('', 404);
    }
    return str_starts_with($target, '/') ? new LocalRedirectResponse($target, 302) : new TrustedRedirectResponse($target, 302);
  }

  /**
   * An https URL or a site path, else NULL.
   */
  public static function safeTarget(string $target): ?string {
    $target = trim($target);
    if (str_starts_with($target, '/') && !str_starts_with($target, '//')) {
      return $target;
    }
    $scheme = parse_url($target, PHP_URL_SCHEME);
    $host = parse_url($target, PHP_URL_HOST);
    return ($scheme === 'https' || $scheme === 'http') && is_string($host) && $host !== '' ? $target : NULL;
  }

}
