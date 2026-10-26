<?php

declare(strict_types=1);

namespace Drupal\appointments;

use Drupal\appointments\Entity\AppointmentInterface;
use Drupal\appointments\Entity\AppointmentRoomInterface;
use Drupal\appointments\Entity\AppointmentServiceInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * The calendar: which start times are free for a service on a given day.
 *
 * One calendar per site. Opening hours are windows per ISO weekday, in the
 * calendar's time zone; a start is offered when the appointment fits inside a
 * window, respects the minimum notice and the booking horizon, and overlaps
 * fewer holding appointments than the capacity allows. The buffer of the new
 * appointment and of the booked ones counts as busy time.
 */
final class Calendar {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly TimeInterface $time,
  ) {}

  /**
   * The settings object.
   */
  public function settings(): ImmutableConfig {
    return $this->configFactory->get('appointments.settings');
  }

  /**
   * The calendar's time zone name.
   */
  public function timezone(): string {
    $tz = (string) $this->settings()->get('timezone');
    if ($tz === '') {
      $tz = (string) $this->configFactory->get('system.date')->get('timezone.default');
    }
    return $tz !== '' ? $tz : 'UTC';
  }

  /**
   * Today's date in the calendar's time zone, as Y-m-d.
   */
  public function today(): string {
    return $this->dateOf($this->time->getCurrentTime());
  }

  /**
   * The calendar date of a timestamp, as Y-m-d.
   */
  public function dateOf(int $timestamp): string {
    return (new \DateTimeImmutable('@' . $timestamp))
      ->setTimezone(new \DateTimeZone($this->timezone()))
      ->format('Y-m-d');
  }

  /**
   * Whether the date is a valid Y-m-d calendar date.
   */
  public static function isDate(string $date): bool {
    $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    return $d !== FALSE && $d->format('Y-m-d') === $date;
  }

  /**
   * The opening windows of a date, as [start, end] timestamp pairs.
   *
   * @return list<array{0: int, 1: int}>
   *   Empty on a closed date.
   */
  public function windows(string $date): array {
    if (!self::isDate($date) || in_array($date, (array) $this->settings()->get('closures'), TRUE)) {
      return [];
    }
    $tz = new \DateTimeZone($this->timezone());
    $day = new \DateTimeImmutable($date . ' 00:00', $tz);
    $weekday = (int) $day->format('N');
    $windows = [];
    foreach ((array) $this->settings()->get('hours') as $row) {
      if ((int) ($row['day'] ?? 0) !== $weekday) {
        continue;
      }
      $open = self::at($date, (string) ($row['start'] ?? ''), $tz);
      $close = self::at($date, (string) ($row['end'] ?? ''), $tz);
      if ($open !== NULL && $close !== NULL && $close > $open) {
        $windows[] = [$open, $close];
      }
    }
    usort($windows, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
    return $windows;
  }

  /**
   * The bookable rooms, lightest first. Empty: the calendar has no rooms.
   *
   * @return array<string, \Drupal\appointments\Entity\AppointmentRoomInterface>
   *   Rooms keyed by machine name.
   */
  public function rooms(): array {
    $rooms = array_filter(
      $this->entityTypeManager->getStorage('appointment_room')->loadMultiple(),
      static fn (AppointmentRoomInterface $room): bool => $room->status(),
    );
    uasort(
      $rooms,
      static fn (AppointmentRoomInterface $a, AppointmentRoomInterface $b): int
        => [$a->getWeight(), $a->label()] <=> [$b->getWeight(), $b->label()],
    );
    return $rooms;
  }

  /**
   * The bookable rooms that offer a service.
   *
   * @return array<string, \Drupal\appointments\Entity\AppointmentRoomInterface>
   *   Rooms keyed by machine name, lightest first.
   */
  public function roomsFor(AppointmentServiceInterface $service): array {
    return array_filter($this->rooms(), static fn (AppointmentRoomInterface $room): bool => $room->offers($service));
  }

  /**
   * The free start times of a service on a date.
   *
   * @param \Drupal\appointments\Entity\AppointmentServiceInterface $service
   *   The service to fit.
   * @param string $date
   *   The date, Y-m-d, in the calendar's time zone.
   * @param int|null $ignore
   *   An appointment id that does not count as busy (the one being moved).
   * @param string|null $room
   *   A room machine name to restrict to; NULL for any room.
   *
   * @return list<int>
   *   Start timestamps, ascending.
   */
  public function slots(AppointmentServiceInterface $service, string $date, ?int $ignore = NULL, ?string $room = NULL): array {
    if (!$this->withinHorizon($date)) {
      return [];
    }
    $windows = $this->windows($date);
    if (!$windows) {
      return [];
    }
    $settings = $this->settings();
    $step = max(5, (int) $settings->get('slot_step')) * 60;
    $length = $service->getDuration() * 60;
    $busy_length = $length + $service->getBuffer() * 60;
    $now = $this->time->getCurrentTime();
    $earliest = $now + max(0, (int) $settings->get('lead_time')) * 60;

    $from = $windows[0][0];
    $to = end($windows)[1] + $busy_length;
    $busy = $this->busy($from - 86400, $to, $ignore, $now);

    $slots = [];
    foreach ($windows as [$open, $close]) {
      for ($t = $open; $t + $length <= $close; $t += $step) {
        if ($t >= $earliest && $this->freeRoom($service, $t, $busy, $room) !== NULL) {
          $slots[] = $t;
        }
      }
    }
    return $slots;
  }

  /**
   * Whether a start time is offered for a service right now.
   */
  public function isAvailable(AppointmentServiceInterface $service, int $start, ?int $ignore = NULL, ?string $room = NULL): bool {
    return in_array($start, $this->slots($service, $this->dateOf($start), $ignore, $room), TRUE);
  }

  /**
   * The room a booking from a start would get, or NULL when none is free.
   *
   * Unlike isAvailable() it ignores hours, notice and horizon: it answers
   * only whether the calendar has room, for a booking being placed or one
   * that already exists.
   *
   * @return string|null
   *   The room's machine name ('' when the calendar has no rooms), or NULL.
   */
  public function roomFor(AppointmentServiceInterface $service, int $start, ?int $ignore = NULL, ?string $room = NULL): ?string {
    $busy_length = ($service->getDuration() + $service->getBuffer()) * 60;
    $busy = $this->busy($start - 86400, $start + $busy_length, $ignore, $this->time->getCurrentTime());
    return $this->freeRoom($service, $start, $busy, $room);
  }

  /**
   * Whether the time from a start is free of other bookings.
   */
  public function slotIsFree(AppointmentServiceInterface $service, int $start, ?int $ignore = NULL, ?string $room = NULL): bool {
    return $this->roomFor($service, $start, $ignore, $room) !== NULL;
  }

  /**
   * The first free room for a service from a start, against busy intervals.
   *
   * Without rooms the calendar is one room of `capacity` parallel places.
   * With rooms each takes one appointment at a time; an appointment that
   * names no known room (booked before rooms existed) takes one free room
   * of the whole calendar.
   *
   * @param \Drupal\appointments\Entity\AppointmentServiceInterface $service
   *   The service.
   * @param int $t
   *   The start.
   * @param list<array{0: int, 1: int, 2: string}> $busy
   *   Busy intervals: start, busy until, room ('' for none).
   * @param string|null $room
   *   The room asked for, or NULL for any.
   *
   * @return string|null
   *   The room ('' without rooms), or NULL when nothing is free.
   */
  private function freeRoom(AppointmentServiceInterface $service, int $t, array $busy, ?string $room): ?string {
    $end = $t + ($service->getDuration() + $service->getBuffer()) * 60;
    $overlapping = array_filter($busy, static fn (array $b): bool => $b[0] < $end && $b[1] > $t);
    $rooms = $this->rooms();
    if (!$rooms) {
      return count($overlapping) < max(1, (int) $this->settings()->get('capacity')) ? '' : NULL;
    }
    $occupied = [];
    $loose = 0;
    foreach ($overlapping as [, , $in]) {
      if (isset($rooms[$in])) {
        $occupied[$in] = TRUE;
      }
      else {
        $loose++;
      }
    }
    if (count($rooms) - count($occupied) <= $loose) {
      return NULL;
    }
    foreach ($this->roomsFor($service) as $id => $candidate) {
      if (($room === NULL || $room === $id) && !isset($occupied[$id])) {
        return (string) $id;
      }
    }
    return NULL;
  }

  /**
   * The open dates from today to the horizon, with at least one window.
   *
   * @return list<string>
   *   Dates, Y-m-d.
   */
  public function openDates(): array {
    $tz = new \DateTimeZone($this->timezone());
    $day = new \DateTimeImmutable($this->today() . ' 00:00', $tz);
    $dates = [];
    for ($i = 0; $i <= max(0, (int) $this->settings()->get('horizon')); $i++) {
      $date = $day->modify("+$i days")->format('Y-m-d');
      if ($this->windows($date)) {
        $dates[] = $date;
      }
    }
    return $dates;
  }

  /**
   * Whether the date lies between today and the booking horizon.
   */
  public function withinHorizon(string $date): bool {
    if (!self::isDate($date)) {
      return FALSE;
    }
    $tz = new \DateTimeZone($this->timezone());
    $last = (new \DateTimeImmutable($this->today() . ' 00:00', $tz))
      ->modify('+' . max(0, (int) $this->settings()->get('horizon')) . ' days')
      ->format('Y-m-d');
    return $date >= $this->today() && $date <= $last;
  }

  /**
   * Busy intervals of holding appointments that touch a time range.
   *
   * @return list<array{0: int, 1: int, 2: string}>
   *   [start, busy until, room] ('' for no room).
   */
  private function busy(int $from, int $to, ?int $ignore, int $now): array {
    $storage = $this->entityTypeManager->getStorage('appointment');
    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('status', [AppointmentInterface::CONFIRMED, AppointmentInterface::PENDING], 'IN')
      ->condition('start', $to, '<')
      ->condition('start', $from, '>=');
    if ($ignore !== NULL) {
      $query->condition('id', $ignore, '<>');
    }
    $busy = [];
    /** @var \Drupal\appointments\Entity\AppointmentInterface $appointment */
    foreach ($storage->loadMultiple($query->execute()) as $appointment) {
      if ($appointment->isHolding($now)) {
        $busy[] = [$appointment->getStart(), $appointment->getBusyUntil(), $appointment->getRoomId()];
      }
    }
    return $busy;
  }

  /**
   * A wall-clock time on a date, as a timestamp, or NULL if malformed.
   */
  private static function at(string $date, string $time, \DateTimeZone $tz): ?int {
    if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) && $time !== '24:00') {
      return NULL;
    }
    $moment = $time === '24:00'
      ? (new \DateTimeImmutable($date . ' 00:00', $tz))->modify('+1 day')
      : new \DateTimeImmutable($date . ' ' . $time, $tz);
    return $moment->getTimestamp();
  }

}
