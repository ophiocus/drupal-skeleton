<?php

declare(strict_types=1);

namespace Drupal\shop_delivery\Controller;

use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\shop_delivery\AddressSearch;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * The address search behind "Buscar mi dirección en el mapa".
 *
 * EXPERIMENTAL, like the maper_nominatim module it asks. One search per
 * click, never as the customer types (Nominatim's usage policy forbids
 * autocomplete), through maper_nominatim's client: its off switch, its
 * spacing between requests, its identifying User-Agent and its kept
 * answers all apply. A customer may search a few times an hour; the
 * customer always sees the pin on the map and can correct it.
 */
final class LocateController implements ContainerInjectionInterface {

  use AutowireTrait;

  /**
   * Searches one visitor may make per window.
   */
  private const FLOOD_LIMIT = 10;

  /**
   * The flood window, in seconds.
   */
  private const FLOOD_WINDOW = 3600;

  public function __construct(
    private readonly FloodInterface $flood,
    #[Autowire(service: 'logger.channel.shop_delivery')]
    private readonly LoggerChannelInterface $logger,
    #[Autowire(service: 'shop_delivery.address_search')]
    private readonly AddressSearch $search,
  ) {}

  /**
   * Looks up one address.
   */
  public function locate(Request $request): JsonResponse {
    $headers = ['Cache-Control' => 'no-store'];
    if (!$this->search->available()) {
      return new JsonResponse(['error' => 'La búsqueda de direcciones no está disponible. Usa tu ubicación actual o pega un enlace.'], 503, $headers);
    }
    $body = json_decode((string) $request->getContent(), TRUE);
    $query = trim(mb_substr((string) (is_array($body) ? ($body['q'] ?? '') : ''), 0, 200));
    if (mb_strlen($query) < 6) {
      return new JsonResponse(['error' => 'Escribe primero la dirección y la ciudad.'], 400, $headers);
    }
    if (!$this->flood->isAllowed('shop_delivery.locate', self::FLOOD_LIMIT, self::FLOOD_WINDOW)) {
      return new JsonResponse(['error' => 'Ya buscaste varias veces. Usa tu ubicación actual o pega un enlace de Google Maps.'], 429, $headers);
    }
    $this->flood->register('shop_delivery.locate', self::FLOOD_WINDOW);

    try {
      $hits = $this->search->search($query);
    }
    catch (\RuntimeException $e) {
      $this->logger->notice('Address search refused: @why', ['@why' => $e->getMessage()]);
      return new JsonResponse(['error' => 'La búsqueda no respondió. Usa tu ubicación actual o pega un enlace.'], 503, $headers);
    }
    return new JsonResponse(['hits' => $hits], 200, $headers);
  }

}
