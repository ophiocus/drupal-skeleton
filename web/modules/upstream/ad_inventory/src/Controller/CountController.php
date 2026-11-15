<?php

declare(strict_types=1);

namespace Drupal\ad_inventory\Controller;

use Drupal\ad_inventory\AdCounter;
use Drupal\ad_inventory\Entity\AdCreative;
use Drupal\ad_inventory\Rotation;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Routing\LocalRedirectResponse;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
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

  /**
   * Spaces asked about in one pick.
   */
  private const MAX_SPACES = 24;

  public function __construct(
    private readonly AdCounter $counter,
    private readonly Rotation $rotation,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('ad_inventory.counter'), $container->get('ad_inventory.rotation'));
  }

  /**
   * POST /ad/pick with {"p": [[space, [booking, …]], …]}: what goes next.
   *
   * Answers {"space": [booking, creative], …} for the spaces that have a
   * placement in force: the one with the fewest views this week, then its
   * creative with the fewest. The browser shows that one, or picks among
   * the page's own when the answer is missing.
   */
  public function pick(Request $request): Response {
    $payload = json_decode((string) $request->getContent(), TRUE);
    $spaces = is_array($payload) && is_array($payload['p'] ?? NULL) ? array_slice($payload['p'], 0, self::MAX_SPACES) : [];
    $answer = [];
    foreach ($spaces as $space) {
      if (!is_array($space) || count($space) !== 2 || !is_string($space[0]) || !is_array($space[1])) {
        continue;
      }
      $pick = $this->rotation->pick($space[0], $space[1]);
      if ($pick !== NULL) {
        $answer[$space[0]] = $pick;
      }
    }
    $response = new JsonResponse((object) $answer);
    $response->headers->set('Cache-Control', 'no-store');
    return $response;
  }

  /**
   * POST /ad/v with {"v": [[space, creative, source], …]}: spaces seen.
   *
   * A space counts as seen when at least half of it was on screen for one
   * second, after the reader's consent; the browser sends every space it saw
   * once, when the page is left.
   */
  public function beacon(Request $request): Response {
    $response = new Response('', 204);
    $response->headers->set('Cache-Control', 'no-store');
    if (Rotation::isRobot((string) $request->headers->get('User-Agent', ''))) {
      return $response;
    }
    $payload = json_decode((string) $request->getContent(), TRUE);
    $pairs = is_array($payload) && is_array($payload['v'] ?? NULL) ? array_slice($payload['v'], 0, self::MAX_PAIRS) : [];
    $seen = [];
    foreach ($pairs as $pair) {
      if (!is_array($pair) || count($pair) !== 3) {
        continue;
      }
      [$slot, $creative, $source] = [(string) $pair[0], (int) $pair[1], (string) $pair[2]];
      $key = $slot . '|' . $creative . '|' . $source;
      // Only a pair the space could have shown counts: the booking holds the
      // space and the creative and is in force, the house banner houses
      // there, the provider covers it.
      if (isset($seen[$key]) || !$this->rotation->countable($slot, $creative, $source)) {
        continue;
      }
      $seen[$key] = TRUE;
      $this->counter->view($slot, $creative, $source);
    }
    return $response;
  }

  /**
   * Counts a click on /ad/c/{ad_creative}?s=space&f=source, then follows it.
   *
   * Robots and pairs the space could not have shown are followed but not
   * counted.
   */
  public function click(AdCreative $ad_creative, Request $request): Response {
    $slot = (string) $request->query->get('s', '');
    $source = (string) $request->query->get('f', 'booking');
    $robot = Rotation::isRobot((string) $request->headers->get('User-Agent', ''));
    if (!$robot && $this->rotation->countable($slot, (int) $ad_creative->id(), $source)) {
      $this->counter->click($slot, (int) $ad_creative->id(), $source);
    }
    $response = self::follow($ad_creative->target());
    $response->headers->set('Cache-Control', 'no-store');
    $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
    $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
    return $response;
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
