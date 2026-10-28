<?php

declare(strict_types=1);

namespace Drupal\Tests\ad_inventory\Traits;

use Drupal\ad_inventory\Entity\AdBooking;
use Drupal\ad_inventory\Entity\AdCreative;
use Drupal\ad_inventory\Entity\AdSlot;
use Drupal\ad_inventory\Fill;
use Drupal\file\Entity\File;
use Drupal\media\Entity\Media;

/**
 * Spaces, creatives with renditions, and bookings for tests.
 *
 * The using class must create an "image" media type first.
 */
trait AdFixturesTrait {

  /**
   * A web space (or an email one) taking every fill source.
   */
  protected function createSlot(string $id, string $desktop = 'leaderboard', string $phone = 'rectangle', string $channel = AdSlot::WEB): AdSlot {
    $slot = AdSlot::create([
      'id' => $id,
      'label' => $id,
      'channel' => $channel,
      'desktop_format' => $desktop,
      'phone_format' => $phone,
      'alternatives' => [],
      'fill' => [Fill::BOOKING, Fill::PROVIDER, Fill::HOUSE],
      'kinds' => [],
    ]);
    $slot->save();
    return $slot;
  }

  /**
   * A creative with a real image per format, at twice the format's size.
   *
   * @param string[] $formats
   *   Format ids with their pixel sizes known to the test.
   * @param array<string, mixed> $values
   *   Field values (state, house, target_url…).
   */
  protected function createCreative(array $formats, array $values = []): AdCreative {
    $sizes = [
      'leaderboard' => [728, 90],
      'mobile_banner' => [320, 100],
      'rectangle' => [300, 250],
      'skyscraper' => [160, 600],
      'half_page' => [300, 600],
      'email' => [600, 200],
    ];
    $creative = AdCreative::create($values + [
      'label' => 'Test creative',
      'target_url' => 'https://example.com/landing',
      'alt' => 'A test banner',
      'state' => AdCreative::DRAFT,
    ]);
    foreach ($formats as $format) {
      [$w, $h] = $sizes[$format];
      $image = imagecreatetruecolor($w * 2, $h * 2);
      $uri = 'public://ad-test-' . $format . '-' . bin2hex(random_bytes(4)) . '.png';
      ob_start();
      imagepng($image);
      file_put_contents($uri, ob_get_clean());
      $file = File::create(['uri' => $uri, 'status' => 1]);
      $file->save();
      $media = Media::create([
        'bundle' => 'image',
        'name' => $format,
        'field_media_image' => ['target_id' => $file->id(), 'alt' => 'x'],
        'status' => 1,
      ]);
      $media->save();
      $creative->setRendition($format, (int) $media->id());
    }
    $creative->save();
    return $creative;
  }

  /**
   * A booking of spaces from one day to another (YYYY-MM-DD).
   *
   * @param \Drupal\ad_inventory\Entity\AdCreative $creative
   *   The creative.
   * @param string[] $slots
   *   Space ids.
   * @param string $start
   *   First day.
   * @param string $end
   *   Last day.
   * @param string $state
   *   The booking's state.
   * @param string $scope
   *   A scope key, or '' for every page.
   */
  protected function createBooking(AdCreative $creative, array $slots, string $start, string $end, string $state = AdBooking::APPROVED, string $scope = ''): AdBooking {
    $booking = AdBooking::create([
      'label' => 'Test booking',
      'creative' => $creative->id(),
      'slots' => ['ids' => $slots],
      'scope' => $scope,
      'start_day' => $start,
      'end_day' => $end,
      'state' => $state,
    ]);
    $booking->save();
    return $booking;
  }

}
