<?php

declare(strict_types=1);

namespace Drupal\ad_inventory\Controller;

use Drupal\ad_inventory\AdCounter;
use Drupal\ad_inventory\AdResolver;
use Drupal\ad_inventory\Entity\AdSlot;
use Drupal\ad_inventory\Scope\AdScope;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\CacheableResponse;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\media\MediaInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A mail template's banner: one image URL and one link URL per space.
 *
 * The template that a sending tool keeps points at these two addresses
 * forever. The image is whichever creative is in force on the day the mail
 * is opened, the same for every reader, so the edge can cache it; it is
 * renewed when a booking of the space starts, ends or changes. Clicks are
 * counted; opens are not (the edge answers them, and mail clients fetch
 * images ahead of time).
 */
final class EmailSlotController extends ControllerBase {

  /**
   * A 1×1 transparent PNG for a space with nothing in force.
   */
  private const PIXEL = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

  public function __construct(
    private readonly AdResolver $resolver,
    private readonly AdCounter $counter,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('ad_inventory.resolver'), $container->get('ad_inventory.counter'));
  }

  /**
   * Answers /ad/e/{ad_slot}/banner.png with the banner in force.
   */
  public function image(AdSlot $ad_slot): Response {
    $this->email($ad_slot);
    $cacheability = new CacheableMetadata();
    $fill = $this->resolver->resolve($ad_slot, $this->scope(), new AnonymousUserSession(), $cacheability);
    $body = base64_decode(self::PIXEL);
    $type = 'image/png';
    $media = $fill?->creative?->rendition($ad_slot->desktopFormat());
    if ($media instanceof MediaInterface) {
      $field = $media->getSource()->getConfiguration()['source_field'] ?? '';
      $file = $field !== '' ? $media->get($field)->entity : NULL;
      if ($file !== NULL && is_readable($file->getFileUri())) {
        $body = (string) file_get_contents($file->getFileUri());
        $type = (string) ($file->getMimeType() ?: 'image/png');
        $cacheability->addCacheableDependency($media)->addCacheableDependency($file);
      }
    }
    $cacheability->setCacheMaxAge(600);
    $response = new CacheableResponse($body, 200, ['Content-Type' => $type]);
    $response->setPublic();
    $response->setMaxAge(600);
    $response->addCacheableDependency($cacheability);
    return $response;
  }

  /**
   * Counts a click on /ad/e/{ad_slot}/go and follows the banner in force.
   */
  public function go(AdSlot $ad_slot): Response {
    $this->email($ad_slot);
    $fill = $this->resolver->resolve($ad_slot, $this->scope(), new AnonymousUserSession(), new CacheableMetadata());
    if ($fill?->creative === NULL) {
      $response = CountController::follow('/');
    }
    else {
      $this->counter->click((string) $ad_slot->id(), (int) $fill->creative->id(), 'email');
      $response = CountController::follow($fill->creative->target());
    }
    $response->headers->set('Cache-Control', 'no-store');
    return $response;
  }

  /**
   * Only email spaces answer here.
   */
  private function email(AdSlot $slot): void {
    if (!$slot->status() || $slot->channel() !== AdSlot::EMAIL) {
      throw new NotFoundHttpException();
    }
  }

  /**
   * Mail has no page: no scope keys.
   */
  private function scope(): AdScope {
    return new AdScope(NULL, [], new CacheableMetadata());
  }

}
