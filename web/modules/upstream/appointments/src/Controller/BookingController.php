<?php

declare(strict_types=1);

namespace Drupal\appointments\Controller;

use Drupal\appointments\BookingManager;
use Drupal\appointments\Calendar;
use Drupal\appointments\Entity\AppointmentInterface;
use Drupal\appointments\Entity\AppointmentServiceInterface;
use Drupal\appointments\Freshness;
use Drupal\appointments\Ics;
use Drupal\appointments\Messages;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The public booking pages.
 *
 * One page picks the time: a service dropdown, the rooms that offer it
 * (hidden when there is only one), and the free start times a week of open
 * days at a time. A chosen time opens the client's details form.
 *
 * The picker is cacheable, by Drupal and at the edge, for as long as what it
 * shows stays true (Freshness); the pages after it are never cached.
 */
final class BookingController extends ControllerBase {

  /**
   * Days shown per page of the picker.
   */
  private const DAYS_PER_PAGE = 7;

  public function __construct(
    private readonly Calendar $calendar,
    private readonly BookingManager $booking,
    private readonly Messages $messages,
    private readonly DateFormatterInterface $dateFormatter,
    private readonly TimeInterface $time,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('appointments.calendar'),
      $container->get('appointments.booking'),
      $container->get('appointments.messages'),
      $container->get('date.formatter'),
      $container->get('datetime.time'),
    );
  }

  /**
   * The page title for the details form.
   */
  public function serviceTitle(AppointmentServiceInterface $appointment_service): string {
    return (string) $this->t('Book: @service', ['@service' => $appointment_service->label()]);
  }

  /**
   * The picker: service, room and free time on one page.
   *
   * Query: service (a service key), room (a room machine name), from (the
   * first date shown, Y-m-d), reschedule (the manage token of an appointment
   * being moved; its service is then fixed).
   *
   * @return array<string, mixed>
   *   A render array.
   */
  public function picker(Request $request): array {
    $langcode = $this->languageManager()->getCurrentLanguage()->getId();
    // What the page depends on. Bookings (appointment_list), services, rooms
    // and settings invalidate it by tag, at the edge too; the clock by its
    // deadline (Freshness).
    $cacheability = (new CacheableMetadata())
      ->addCacheTags([
        'appointment_list',
        'appointment_service_list',
        'config:appointment_room_list',
        'config:appointments.settings',
        'config:system.date',
      ])
      ->addCacheContexts([
        'url.query_args:service',
        'url.query_args:room',
        'url.query_args:from',
        'url.query_args:reschedule',
        'languages:' . LanguageInterface::TYPE_CONTENT,
      ]);
    $services = $this->calendar->services();
    if (!$services) {
      $build = ['#markup' => '<p>' . $this->t('There is nothing to book at the moment.') . '</p>'];
      return $this->fresh($build, $cacheability, $this->calendar->freshFor(NULL));
    }

    $moving = $this->movingAppointment($request);
    $service = $moving?->getService();
    if (!$service) {
      $key = (string) $request->query->get('service', '');
      $service = $services[$key] ?? reset($services);
    }

    // Rooms: listed when more than one offers the service. In "any" mode the
    // list starts with "Any room" and no room is preselected; in "required"
    // mode the client always books a named room, the first by default.
    $rooms = $this->calendar->roomsFor($service);
    $required = $this->calendar->settings()->get('room_choice') === 'required';
    $room = (string) $request->query->get('room', '');
    $room = isset($rooms[$room]) ? $room : NULL;
    if ($room === NULL && $required && count($rooms) > 1) {
      $room = (string) array_key_first($rooms);
    }
    if (count($rooms) <= 1) {
      $room = NULL;
    }

    $base = array_filter([
      'service' => $moving ? NULL : $service->getKey(),
      'reschedule' => $moving?->getToken(),
    ]);
    // Generated URLs carry cacheability of their own (the language prefix).
    $url = static function (Url $url) use ($cacheability): string {
      $generated = $url->toString(TRUE);
      $cacheability->addCacheableDependency($generated);
      return $generated->getGeneratedUrl();
    };
    $here = static fn (array $query): string => $url(Url::fromRoute('appointments.book', [], ['query' => array_filter($query)]));

    $room_links = [];
    if (count($rooms) > 1) {
      if (!$required) {
        $room_links[] = [
          'label' => $this->t('Any room'),
          'url' => $here($base),
          'active' => $room === NULL,
        ];
      }
      foreach ($rooms as $id => $candidate) {
        $room_links[] = [
          'label' => $candidate->label(),
          'url' => $here($base + ['room' => $id]),
          'active' => $room === $id,
        ];
      }
    }

    // A week of open days, from ?from=.
    $dates = $this->calendar->openDates();
    $from = (string) $request->query->get('from', '');
    $offset = 0;
    if ($from !== '' && Calendar::isDate($from)) {
      foreach ($dates as $i => $date) {
        if ($date >= $from) {
          $offset = $i;
          break;
        }
      }
    }
    $tz = $this->calendar->timezone();
    $days = [];
    $any = FALSE;
    $earliest = NULL;
    foreach (array_slice($dates, $offset, self::DAYS_PER_PAGE) as $date) {
      $slots = [];
      foreach ($this->calendar->slots($service, $date, $moving ? (int) $moving->id() : NULL, $room) as $start) {
        $earliest ??= $start;
        $query = array_filter(['room' => $room]);
        $slot_url = $moving
          ? Url::fromRoute('appointments.move', ['token' => $moving->getToken(), 'start' => $start], ['query' => $query])
          : Url::fromRoute('appointments.book_slot', ['appointment_service' => $service->id(), 'start' => $start], ['query' => $query]);
        $slots[] = [
          'time' => $this->dateFormatter->format($start, 'custom', 'H:i', $tz, $langcode),
          'url' => $url($slot_url),
        ];
      }
      $any = $any || $slots;
      $noon = (new \DateTimeImmutable($date . ' 12:00', new \DateTimeZone($tz)))->getTimestamp();
      $days[] = ['label' => $this->messages->day($noon, $langcode), 'slots' => $slots];
    }
    $prev = $offset > 0 ? $dates[max(0, $offset - self::DAYS_PER_PAGE)] : NULL;
    $next = $dates[$offset + self::DAYS_PER_PAGE] ?? NULL;
    $paging = $base + ['room' => $room];

    $options = [];
    foreach ($services as $key => $candidate) {
      $options[] = ['key' => $key, 'label' => $candidate->label(), 'selected' => $candidate->id() === $service->id()];
    }
    $price = (float) $service->getPrice() > 0 ? $this->messages->price($service->getPrice(), $langcode) : '';

    $build = [
      '#theme' => 'appointments_picker',
      '#action' => $url(Url::fromRoute('appointments.book')),
      '#services' => $moving ? [] : $options,
      '#service' => [
        'label' => $service->label(),
        'description' => (string) $service->get('description')->value,
        'duration' => $this->t('@min min', ['@min' => $service->getDuration()]),
        'price' => $price,
        'online' => $this->paymentNote($service, $langcode),
      ],
      '#rooms' => $room_links,
      '#days' => $days,
      '#any' => $any,
      '#moving' => $moving ? $this->messages->when($moving, $langcode) : '',
      '#prev' => $prev ? $here($paging + ['from' => $prev]) : '',
      '#next' => $next ? $here($paging + ['from' => $next]) : '',
      '#timezone' => $tz,
      '#attached' => ['library' => ['appointments/booking', 'appointments/picker']],
    ];
    // An appointment being moved is one client's page: never kept anywhere.
    return $this->fresh($build, $cacheability, $moving ? 0 : $this->calendar->freshFor($earliest));
  }

  /**
   * Adds a picker page's cacheability and its deadline.
   *
   * @param array<string, mixed> $build
   *   The render array.
   * @param \Drupal\Core\Cache\CacheableMetadata $cacheability
   *   What the page depends on.
   * @param int $seconds
   *   How long it stays true; 0: not cacheable.
   *
   * @return array<string, mixed>
   *   The render array.
   */
  private function fresh(array $build, CacheableMetadata $cacheability, int $seconds): array {
    $cacheability->mergeCacheMaxAge($seconds);
    $cacheability->applyTo($build);
    // The deadline as a moment, not a duration: a copy served later by the
    // dynamic page cache still knows when it stops being true.
    $until = (int) $this->time->getCurrentTime() + $seconds;
    $build['#attached']['http_header'][] = [Freshness::HEADER, (string) $until, TRUE];
    return $build;
  }

  /**
   * The old per-service picker address: on to the picker with it selected.
   */
  public function legacy(AppointmentServiceInterface $appointment_service, Request $request): RedirectResponse {
    $query = array_filter([
      'service' => $appointment_service->getKey(),
      'reschedule' => (string) $request->query->get('reschedule', ''),
      'from' => (string) $request->query->get('from', ''),
    ]);
    return $this->redirect('appointments.book', [], ['query' => $query]);
  }

  /**
   * The client's details for a chosen start (and room, from ?room=).
   *
   * @return array<string, mixed>|\Symfony\Component\HttpFoundation\RedirectResponse
   *   The booking form, or a redirect back to the picker.
   */
  public function form(AppointmentServiceInterface $appointment_service, int $start, Request $request): array|RedirectResponse {
    if (!$appointment_service->isPublished()) {
      throw new NotFoundHttpException();
    }
    $room = (string) $request->query->get('room', '');
    $room = isset($this->calendar->roomsFor($appointment_service)[$room]) ? $room : NULL;
    if (!$this->calendar->isAvailable($appointment_service, $start, NULL, $room)) {
      $this->messenger()->addWarning($this->t('That time is no longer free. Please choose another.'));
      return $this->redirect('appointments.book', [], [
        'query' => array_filter([
          'service' => $appointment_service->getKey(),
          'room' => $room,
          'from' => $this->calendar->dateOf($start),
        ]),
      ]);
    }
    $values = ['service' => $appointment_service->id(), 'start' => $start];
    if ($room !== NULL) {
      $values['room'] = $room;
    }
    $appointment = $this->entityTypeManager()->getStorage('appointment')->create($values);
    return $this->entityFormBuilder()->getForm($appointment, 'book');
  }

  /**
   * The client's page for one appointment, opened by its private link.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  public function manage(string $token): array {
    $appointment = $this->load($token);
    $langcode = $this->languageManager()->getCurrentLanguage()->getId();
    $service = $appointment->getService();
    $can_change = $this->booking->clientCanChange($appointment);
    $statuses = $appointment::statusOptions();
    $details = [
      (string) $this->t('Service') => $service ? $service->label() : '',
      (string) $this->t('When') => $this->messages->when($appointment, $langcode),
    ];
    if (($room = $appointment->getRoom()) && count($this->calendar->rooms()) > 1) {
      $details[(string) $this->t('Room')] = (string) $room->label();
    }
    $details += [
      (string) $this->t('Status') => (string) ($statuses[$appointment->getStatus()] ?? $appointment->getStatus()),
      (string) $this->t('Name') => (string) $appointment->get('name')->value,
    ] + $this->messages->extraFields($appointment);
    if ((float) $appointment->get('amount')->value > 0) {
      $details[(string) $this->t('Paid online')] = $this->messages->price((string) $appointment->get('amount')->value, $langcode);
    }

    $active = in_array($appointment->getStatus(), [AppointmentInterface::CONFIRMED, AppointmentInterface::PENDING], TRUE);
    $links = [];
    if ($active) {
      $links[] = [
        'title' => $this->t('Add to my calendar'),
        'url' => Url::fromRoute('appointments.ics', ['token' => $token])->toString(),
      ];
    }
    if ($can_change && $service && $service->isPublished()) {
      $links[] = [
        'title' => $this->t('Choose another time'),
        'url' => Url::fromRoute('appointments.book', [], ['query' => ['reschedule' => $token]])->toString(),
      ];
    }
    if ($can_change) {
      $links[] = [
        'title' => $this->t('Cancel the appointment'),
        'url' => Url::fromRoute('appointments.cancel', ['token' => $token])->toString(),
      ];
    }

    return [
      '#theme' => 'appointments_manage',
      '#details' => $details,
      '#links' => $links,
      '#locked' => !$can_change && $active,
      '#attached' => ['library' => ['appointments/booking']],
      '#cache' => ['max-age' => 0],
    ];
  }

  /**
   * The appointment as an .ics download.
   */
  public function ics(string $token, Request $request): Response {
    $appointment = $this->load($token);
    $site = (string) $this->config('system.site')->get('name');
    $room = $appointment->getRoom();
    $where = $room && count($this->calendar->rooms()) > 1 ? $site . ', ' . $room->label() : $site;
    $url = Url::fromRoute('appointments.manage', ['token' => $token], ['absolute' => TRUE])->toString();
    $body = Ics::build($appointment, $where, $url, $request->getHost());
    return new Response($body, 200, [
      'Content-Type' => 'text/calendar; charset=utf-8',
      'Content-Disposition' => 'attachment; filename="appointment-' . $appointment->id() . '.ics"',
      'Cache-Control' => 'private, no-store',
      'X-Robots-Tag' => 'noindex',
    ]);
  }

  /**
   * The appointment of a token, or a 404.
   */
  private function load(string $token): AppointmentInterface {
    $appointment = $this->booking->loadByToken($token);
    if (!$appointment) {
      throw new NotFoundHttpException();
    }
    return $appointment;
  }

  /**
   * The appointment being moved, from ?reschedule=<token>, if any.
   */
  private function movingAppointment(Request $request): ?AppointmentInterface {
    $token = (string) $request->query->get('reschedule', '');
    if ($token === '') {
      return NULL;
    }
    $appointment = $this->load($token);
    if (!$this->booking->clientCanChange($appointment)) {
      throw new AccessDeniedHttpException();
    }
    return $appointment;
  }

  /**
   * What the client pays online for a service, as a sentence, or ''.
   */
  private function paymentNote(AppointmentServiceInterface $service, string $langcode): string {
    $due = $this->booking->amountDue($service);
    if ((float) $due <= 0) {
      return '';
    }
    $price = $this->messages->price($due, $langcode);
    return $this->booking->paymentMode() === 'deposit'
      ? (string) $this->t('Deposit of @amount online to hold the time; the rest at the appointment.', ['@amount' => $price])
      : (string) $this->t('Paid online when booking: @amount.', ['@amount' => $price]);
  }

}
