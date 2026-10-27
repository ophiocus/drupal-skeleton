<?php

declare(strict_types=1);

namespace Drupal\Tests\ad_inventory\Traits;

use Drupal\ad_inventory\Entity\AdBooking;
use Drupal\ad_inventory\Entity\AdBookingInterface;
use Drupal\ad_inventory\Entity\AdCreative;
use Drupal\ad_inventory\Entity\AdCreativeInterface;
use Drupal\ad_inventory\Entity\AdSlot;
use Drupal\ad_inventory\Entity\AdSlotInterface;
use Drupal\ad_inventory\Png;
use Drupal\file\Entity\File;
use Drupal\media\Entity\Media;
use Drupal\media\MediaInterface;
use Drupal\media\MediaTypeInterface;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;

/**
 * Builds slots, renditions, creatives and bookings for tests.
 */
trait AdInventoryTestTrait {

  use MediaTypeCreationTrait;

  /**
   * The image media type.
   */
  protected ?MediaTypeInterface $imageType = NULL;

  /**
   * An image media item of a size.
   */
  protected function rendition(int $width, int $height): MediaInterface {
    if ($this->imageType === NULL) {
      $this->imageType = $this->createMediaType('image', ['id' => 'image', 'label' => 'Image']);
    }
    $uri = sprintf('public://ad-%dx%d-%s.png', $width, $height, bin2hex(random_bytes(4)));
    file_put_contents($uri, Png::solid($width, $height, [200, 30, 30, 255]));
    $file = File::create(['uri' => $uri, 'status' => 1]);
    $file->save();
    $field = $this->imageType->getSource()->getSourceFieldDefinition($this->imageType)->getName();
    $media = Media::create([
      'bundle' => 'image',
      'name' => basename($uri),
      $field => ['target_id' => $file->id(), 'alt' => 'Banner'],
    ]);
    $media->save();
    return $media;
  }

  /**
   * A slot.
   */
  protected function slot(string $id, array $values = []): AdSlotInterface {
    $slot = AdSlot::create($values + [
      'id' => $id,
      'label' => ucfirst(str_replace('_', ' ', $id)),
      'channel' => 'web',
      'desktop_format' => 'leaderboard',
      'phone_format' => 'mobile_banner',
      'sources' => ['booking', 'network', 'house'],
    ]);
    $slot->save();
    return $slot;
  }

  /**
   * A creative with renditions at twice each format's size.
   *
   * @param string[] $formats
   *   Format ids to give renditions for.
   * @param array $values
   *   Field values.
   */
  protected function creative(array $formats, array $values = []): AdCreativeInterface {
    $sizes = [
      'leaderboard' => [1456, 180],
      'mobile_banner' => [640, 200],
      'rectangle' => [600, 500],
      'skyscraper' => [320, 1200],
      'half_page' => [600, 1200],
      'email' => [1200, 400],
    ];
    $renditions = [];
    foreach ($formats as $format) {
      [$w, $h] = $sizes[$format];
      $renditions[] = ['target_id' => $this->rendition($w, $h)->id(), 'format' => $format];
    }
    $creative = AdCreative::create($values + [
      'label' => 'Creative',
      'status' => TRUE,
      'house' => FALSE,
      'renditions' => $renditions,
      'target_url' => 'https://advertiser.example/offer',
      'alt' => 'An offer',
      'kind' => 'plain',
    ]);
    $creative->save();
    return $creative;
  }

  /**
   * A booking.
   */
  protected function booking(AdCreativeInterface $creative, array $slots, string $start, string $end, string $state = 'approved', array $values = []): AdBookingInterface {
    $booking = AdBooking::create($values + [
      'label' => 'Booking',
      'creative' => $creative->id(),
      'slots' => $slots,
      'start_day' => $start,
      'end_day' => $end,
      'state' => $state,
    ]);
    $booking->save();
    return $booking;
  }

}
