<?php

declare(strict_types=1);

namespace Drupal\ad_inventory;

use Drupal\ad_inventory\Entity\AdBookingInterface;
use Drupal\ad_inventory\Entity\AdCreativeInterface;
use Drupal\ad_inventory\Entity\AdFormatInterface;
use Drupal\ad_inventory\Entity\AdProviderInterface;
use Drupal\ad_inventory\Entity\AdSlotInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\file\FileInterface;
use Drupal\media\MediaInterface;

/**
 * Reads the inventory: slots, formats, providers, bookings, creatives.
 */
final class Inventory {

  /**
   * How many house creatives one slot carries at most.
   */
  public const HOUSE_LIMIT = 20;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * A slot, or NULL.
   */
  public function slot(string $id): ?AdSlotInterface {
    $slot = $id === '' ? NULL : $this->entityTypeManager->getStorage('ad_slot')->load($id);
    return $slot instanceof AdSlotInterface ? $slot : NULL;
  }

  /**
   * Every slot, keyed by id.
   *
   * @return \Drupal\ad_inventory\Entity\AdSlotInterface[]
   *   Slots.
   */
  public function slots(): array {
    return $this->entityTypeManager->getStorage('ad_slot')->loadMultiple();
  }

  /**
   * A format, or NULL.
   */
  public function format(string $id): ?AdFormatInterface {
    $format = $id === '' ? NULL : $this->entityTypeManager->getStorage('ad_format')->load($id);
    return $format instanceof AdFormatInterface ? $format : NULL;
  }

  /**
   * Every format, keyed by id.
   *
   * @return \Drupal\ad_inventory\Entity\AdFormatInterface[]
   *   Formats.
   */
  public function formats(): array {
    return $this->entityTypeManager->getStorage('ad_format')->loadMultiple();
  }

  /**
   * Providers in fill order.
   *
   * @param bool $enabled_only
   *   Leave out disabled ones.
   *
   * @return \Drupal\ad_inventory\Entity\AdProviderInterface[]
   *   Providers keyed by id, lowest weight first.
   */
  public function providers(bool $enabled_only = TRUE): array {
    $providers = array_filter(
      $this->entityTypeManager->getStorage('ad_provider')->loadMultiple(),
      static fn($provider): bool => $provider instanceof AdProviderInterface && (!$enabled_only || $provider->status()),
    );
    uasort($providers, static function (AdProviderInterface $a, AdProviderInterface $b): int {
      return [$a->weight(), (string) $a->label()] <=> [$b->weight(), (string) $b->label()];
    });
    return $providers;
  }

  /**
   * Slot ids from slot ids and "group:<name>" values.
   *
   * @param string[] $values
   *   A booking's slot values.
   *
   * @return string[]
   *   Slot ids.
   */
  public function expandSlots(array $values): array {
    $slots = [];
    $groups = [];
    foreach ($values as $value) {
      if (str_starts_with($value, 'group:')) {
        $groups[] = substr($value, 6);
      }
      elseif ($value !== '') {
        $slots[] = $value;
      }
    }
    if ($groups) {
      foreach ($this->slots() as $slot) {
        if ($slot->group() !== '' && in_array($slot->group(), $groups, TRUE)) {
          $slots[] = (string) $slot->id();
        }
      }
    }
    return array_values(array_unique($slots));
  }

  /**
   * The values a booking may name a slot by: its id and its group.
   *
   * @return string[]
   *   Slot values.
   */
  public static function slotValuesFor(AdSlotInterface $slot): array {
    $values = [(string) $slot->id()];
    if ($slot->group() !== '') {
      $values[] = 'group:' . $slot->group();
    }
    return $values;
  }

  /**
   * Approved bookings of a slot that are not over, earliest first.
   *
   * @return \Drupal\ad_inventory\Entity\AdBookingInterface[]
   *   Bookings.
   */
  public function bookingsFor(AdSlotInterface $slot, string $today): array {
    $storage = $this->entityTypeManager->getStorage('ad_booking');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('state', 'approved')
      ->condition('slots', self::slotValuesFor($slot), 'IN')
      ->condition('end_day', $today, '>=')
      ->sort('start_day')
      ->sort('id')
      ->execute();
    return $ids ? array_values($storage->loadMultiple($ids)) : [];
  }

  /**
   * The bookings a page prints for a slot: in force today, then the next.
   *
   * @param \Drupal\ad_inventory\Entity\AdSlotInterface $slot
   *   The slot.
   * @param string $today
   *   Y-m-d.
   * @param string[] $scopes
   *   The page's context values; a scoped booking needs one of them.
   *
   * @return \Drupal\ad_inventory\Entity\AdBookingInterface[]
   *   Bookings whose creative is active.
   */
  public function printable(AdSlotInterface $slot, string $today, array $scopes): array {
    $printed = [];
    $next = NULL;
    foreach ($this->bookingsFor($slot, $today) as $booking) {
      if ($booking->scope() !== '' && !in_array($booking->scope(), $scopes, TRUE)) {
        continue;
      }
      $creative = $booking->creative();
      if ($creative === NULL || !$creative->isPublished()) {
        continue;
      }
      if ($booking->start() <= $today) {
        $printed[] = $booking;
      }
      elseif ($next === NULL) {
        $next = $booking;
      }
    }
    if ($next !== NULL) {
      $printed[] = $next;
    }
    return $printed;
  }

  /**
   * Bookings in force on a day, in the order the server picks them.
   *
   * Exclusive first, then the earliest start, then the oldest.
   *
   * @return \Drupal\ad_inventory\Entity\AdBookingInterface[]
   *   Bookings whose creative is active.
   */
  public function inForce(AdSlotInterface $slot, string $day): array {
    $bookings = array_values(array_filter(
      $this->bookingsFor($slot, $day),
      static fn(AdBookingInterface $b): bool => $b->inForceOn($day) && $b->scope() === '' && ($b->creative()?->isPublished() ?? FALSE),
    ));
    usort($bookings, static function (AdBookingInterface $a, AdBookingInterface $b): int {
      return [(int) !$a->isExclusive(), $a->start(), (int) $a->id()]
        <=> [(int) !$b->isExclusive(), $b->start(), (int) $b->id()];
    });
    return $bookings;
  }

  /**
   * Active house creatives, oldest first.
   *
   * @return \Drupal\ad_inventory\Entity\AdCreativeInterface[]
   *   Creatives.
   */
  public function houseCreatives(): array {
    $storage = $this->entityTypeManager->getStorage('ad_creative');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('status', 1)
      ->condition('house', 1)
      ->sort('id')
      ->range(0, self::HOUSE_LIMIT)
      ->execute();
    return $ids ? array_values($storage->loadMultiple($ids)) : [];
  }

  /**
   * The formats a booking's slots need: desktop and phone of each.
   *
   * @return string[]
   *   Format ids.
   */
  public function neededFormats(AdBookingInterface $booking): array {
    $formats = [];
    foreach ($this->expandSlots($booking->slotValues()) as $id) {
      $slot = $this->slot($id);
      if ($slot !== NULL) {
        $formats[] = $slot->desktopFormat();
        $formats[] = $slot->phoneFormat();
      }
    }
    return array_values(array_unique(array_filter($formats)));
  }

  /**
   * What keeps a creative from filling these formats.
   *
   * @param \Drupal\ad_inventory\Entity\AdCreativeInterface $creative
   *   The creative.
   * @param string[] $formats
   *   Format ids.
   *
   * @return string[]
   *   One sentence per problem; empty when every format has a rendition of
   *   the right ratio (within 1%).
   */
  public function renditionProblems(AdCreativeInterface $creative, array $formats): array {
    $problems = [];
    foreach ($formats as $id) {
      $format = $this->format($id);
      if ($format === NULL) {
        $problems[] = sprintf('The format "%s" does not exist.', $id);
        continue;
      }
      $media = $creative->rendition($id);
      if ($media === NULL) {
        $problems[] = sprintf('No %s rendition (%d×%d, uploaded at %d×%d).', $format->label(), $format->width(), $format->height(), $format->width() * 2, $format->height() * 2);
        continue;
      }
      [$width, $height] = self::mediaSize($media);
      if (!ProviderPolicy::ratioMatches($width, $height, $format->width(), $format->height())) {
        $problems[] = sprintf('The %s rendition is %d×%d; its ratio must match %d×%d within 1%%.', $format->label(), $width, $height, $format->width(), $format->height());
      }
    }
    return $problems;
  }

  /**
   * Whether a creative can fill these formats.
   *
   * @param \Drupal\ad_inventory\Entity\AdCreativeInterface $creative
   *   The creative.
   * @param string[] $formats
   *   Format ids.
   */
  public function fits(AdCreativeInterface $creative, array $formats): bool {
    return $this->renditionProblems($creative, $formats) === [];
  }

  /**
   * A media image's size in pixels.
   *
   * @return int[]
   *   Width and height; zeros when unknown.
   */
  public static function mediaSize(MediaInterface $media): array {
    $source = $media->getSource();
    return [
      (int) $source->getMetadata($media, 'width'),
      (int) $source->getMetadata($media, 'height'),
    ];
  }

  /**
   * A media image's file URI, or NULL.
   */
  public static function mediaUri(MediaInterface $media): ?string {
    $field = $media->getSource()->getConfiguration()['source_field'] ?? '';
    if ($field === '' || !$media->hasField($field)) {
      return NULL;
    }
    $file = $media->get($field)->entity;
    return $file instanceof FileInterface ? $file->getFileUri() : NULL;
  }

}
