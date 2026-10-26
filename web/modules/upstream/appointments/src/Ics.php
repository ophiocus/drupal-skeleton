<?php

declare(strict_types=1);

namespace Drupal\appointments;

use Drupal\appointments\Entity\AppointmentInterface;

/**
 * Writes an appointment as an iCalendar (RFC 5545) event.
 */
final class Ics {

  /**
   * The .ics document for an appointment.
   *
   * @param \Drupal\appointments\Entity\AppointmentInterface $appointment
   *   The appointment.
   * @param string $organizer
   *   The site name, shown as the event's location and organiser.
   * @param string $url
   *   The client's manage page.
   * @param string $host
   *   The site host, for the event UID.
   */
  public static function build(AppointmentInterface $appointment, string $organizer, string $url, string $host): string {
    $service = $appointment->getService();
    $summary = ($service ? $service->label() : 'Appointment') . ' · ' . $organizer;
    $cancelled = $appointment->getStatus() === AppointmentInterface::CANCELLED;
    $lines = [
      'BEGIN:VCALENDAR',
      'VERSION:2.0',
      'PRODID:-//Drupal//Appointments//EN',
      'CALSCALE:GREGORIAN',
      'METHOD:' . ($cancelled ? 'CANCEL' : 'PUBLISH'),
      'BEGIN:VEVENT',
      'UID:appointment-' . $appointment->uuid() . '@' . $host,
      'DTSTAMP:' . self::utc((int) $appointment->get('changed')->value ?: time()),
      'DTSTART:' . self::utc($appointment->getStart()),
      'DTEND:' . self::utc($appointment->getEnd()),
      'SUMMARY:' . self::text($summary),
      'LOCATION:' . self::text($organizer),
      'DESCRIPTION:' . self::text($url),
      'URL:' . $url,
      'STATUS:' . ($cancelled ? 'CANCELLED' : 'CONFIRMED'),
      'END:VEVENT',
      'END:VCALENDAR',
    ];
    return implode("\r\n", array_map(self::fold(...), $lines)) . "\r\n";
  }

  /**
   * A timestamp in iCalendar UTC form.
   */
  private static function utc(int $timestamp): string {
    return gmdate('Ymd\THis\Z', $timestamp);
  }

  /**
   * Escapes a TEXT value.
   */
  private static function text(string $value): string {
    return str_replace(["\\", ';', ',', "\r\n", "\n"], ["\\\\", '\;', '\,', '\n', '\n'], $value);
  }

  /**
   * Folds a content line at 75 octets, without splitting a UTF-8 character.
   */
  private static function fold(string $line): string {
    if (strlen($line) <= 75) {
      return $line;
    }
    $out = '';
    $chunk = '';
    foreach (mb_str_split($line) as $char) {
      if (strlen($chunk . $char) > ($out === '' ? 75 : 74)) {
        $out .= ($out === '' ? '' : "\r\n ") . $chunk;
        $chunk = '';
      }
      $chunk .= $char;
    }
    return $out . "\r\n " . $chunk;
  }

}
