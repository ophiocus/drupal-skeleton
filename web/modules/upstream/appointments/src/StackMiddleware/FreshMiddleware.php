<?php

declare(strict_types=1);

namespace Drupal\appointments\StackMiddleware;

use Drupal\appointments\Freshness;
use Drupal\Component\Datetime\TimeInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Re-ages a booking page served again from Drupal's page cache.
 *
 * The page cache stores a response with its headers and sends it back as it
 * was: a page stored with max-age=600 would still say 600 five minutes
 * later, and the CDN would keep it five minutes past its deadline. Wrapped
 * around the page cache, this sets the seconds left again from the deadline
 * the page carries, and drops the internal deadline header from what leaves.
 */
final class FreshMiddleware implements HttpKernelInterface {

  public function __construct(
    private readonly HttpKernelInterface $httpKernel,
    private readonly TimeInterface $time,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = TRUE): Response {
    $response = $this->httpKernel->handle($request, $type, $catch);
    if ($type === self::MAIN_REQUEST) {
      Freshness::apply($response, $this->time->getCurrentTime());
    }
    $response->headers->remove(Freshness::HEADER);
    return $response;
  }

}
