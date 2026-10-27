<?php

declare(strict_types=1);

namespace Drupal\ad_inventory;

use Drupal\ad_inventory\Entity\AdCreativeInterface;
use Drupal\ad_inventory\Entity\AdSlot;
use Drupal\ad_inventory\Entity\AdSlotInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\CacheableResponse;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\Core\Url;
use Drupal\image\ImageStyleInterface;
use Symfony\Component\Mime\MimeTypeGuesserInterface;

/**
 * Email slots: one image and one click address per slot, the same for all.
 *
 * `/ad/e/<slot>.png` answers with the creative in force on the day it is
 * fetched (booking, else house; never a network: AdSense forbids ads in
 * mail), through the format's 2x image style. It is a cacheable response
 * tagged with the slot's fill tag, so the page cache and the edge keep it
 * until a booking of the slot starts, ends or changes (edge_purge purges the
 * edge by that tag). `/ad/e/<slot>/go` redirects to that creative's target
 * and counts the click; it is never cached.
 */
final class EmailServer {

  /**
   * How long a browser or mail proxy may keep the image, in seconds.
   */
  public const MAX_AGE = 3600;

  public function __construct(
    private readonly Inventory $inventory,
    private readonly Calendar $calendar,
    private readonly Counter $counter,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly MimeTypeGuesserInterface $mimeTypeGuesser,
  ) {}

  /**
   * The creative an email slot shows today, or NULL.
   */
  public function pick(AdSlotInterface $slot, CacheableMetadata $cacheability): ?AdCreativeInterface {
    $cacheability->addCacheableDependency($slot);
    $cacheability->addCacheTags([AdSlot::fillTag((string) $slot->id())]);
    $cacheability->addCacheTags($this->entityTypeManager->getDefinition('ad_creative')->getListCacheTags());
    $formats = [$slot->desktopFormat()];
    if ($slot->allows('booking')) {
      foreach ($this->inventory->inForce($slot, $this->calendar->today()) as $booking) {
        $cacheability->addCacheableDependency($booking);
        $creative = $booking->creative();
        if ($creative !== NULL && $this->inventory->fits($creative, $formats)) {
          $cacheability->addCacheableDependency($creative);
          return $creative;
        }
      }
    }
    if ($slot->allows('house')) {
      foreach ($this->inventory->houseCreatives() as $creative) {
        if ($this->inventory->fits($creative, $formats)) {
          $cacheability->addCacheableDependency($creative);
          return $creative;
        }
      }
    }
    return NULL;
  }

  /**
   * The slot's image response.
   */
  public function image(AdSlotInterface $slot): CacheableResponse {
    $cacheability = new CacheableMetadata();
    $creative = $this->pick($slot, $cacheability);
    $format = $this->inventory->format($slot->desktopFormat());
    $body = NULL;
    $type = 'image/png';
    if ($creative !== NULL && $format !== NULL && ($media = $creative->rendition((string) $format->id()))) {
      $cacheability->addCacheableDependency($media);
      $uri = Inventory::mediaUri($media);
      $style = $this->entityTypeManager->getStorage('image_style')->load($format->styleName(2));
      if ($uri !== NULL && $style instanceof ImageStyleInterface) {
        $cacheability->addCacheableDependency($style);
        $derivative = $style->buildUri($uri);
        if (!is_file($derivative)) {
          $style->createDerivative($uri, $derivative);
        }
        if (is_file($derivative)) {
          $body = (string) file_get_contents($derivative);
          $type = (string) ($this->mimeTypeGuesser->guessMimeType($derivative) ?: 'image/png');
        }
      }
    }
    if ($body === NULL) {
      $width = $format ? $format->width() * 2 : 1;
      $height = $format ? $format->height() * 2 : 1;
      $body = Png::solid($width, $height);
    }
    $response = new CacheableResponse($body, 200, [
      'Content-Type' => $type,
      'Cache-Control' => 'public, max-age=' . self::MAX_AGE,
    ]);
    $response->addCacheableDependency($cacheability);
    return $response;
  }

  /**
   * The slot's click: counted, then to today's creative's target.
   */
  public function go(AdSlotInterface $slot): TrustedRedirectResponse {
    $creative = $this->pick($slot, new CacheableMetadata());
    if ($creative !== NULL && preg_match('#^https?://#i', $creative->targetUrl())) {
      $this->counter->click((string) $slot->id(), (string) $creative->id());
      $target = $creative->targetUrl();
    }
    else {
      $target = Url::fromRoute('<front>')->setAbsolute()->toString(TRUE)->getGeneratedUrl();
    }
    $response = new TrustedRedirectResponse($target, 302);
    $response->headers->set('Cache-Control', 'no-store');
    $response->getCacheableMetadata()->setCacheMaxAge(0);
    return $response;
  }

}
