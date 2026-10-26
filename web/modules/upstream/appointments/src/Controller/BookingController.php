<?php

declare(strict_types=1);

namespace Drupal\appointments\Controller;

use Drupal\appointments\BookingManager;
use Drupal\appointments\Calendar;
use Drupal\appointments\Entity\AppointmentInterface;
use Drupal\appointments\Entity\AppointmentServiceInterface;
use Drupal\appointments\Ics;
use Drupal\appointments\Messages;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The public booking pages.
 */
final class BookingController extends ControllerBase {

  /**
   * Days shown per page of the slot picker.
   */
  private const DAYS_PER_PAGE = 7;

  public function __construct(
    private readonly Calendar $calendar,
    private readonly BookingManager $booking,
    private readonly Messages $messages,
    private readonly DateFormatterInterface $dateFormatter,
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
    );
  }

  /**
   * The page title for a service's pages.
   */
  public function serviceTitle(AppointmentServiceInterface $appointment_service): string {
    return (string) $this->t('Book: @service', ['@service' => $appointment_service->label()]);
  }

  /**
   * Step 1: the bookable services.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  public function services(): array {
    $storage = $this->entityTypeManager()->getStorage('appointment_service');
    $ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('status', 1)
      ->sort('weight')
      ->sort('label')
      ->execute();
    $langcode = $this->languageManager()->getCurrentLanguage()->getId();
    $items = [];
    /** @var \Drupal\appointments\Entity\AppointmentServiceInterface $service */
    foreach ($storage->loadMultiple($ids) as $service) {
      $items[] = [
        'label' => $service->label(),
        'description' => (string) $service->get('description')->value,
        'duration' => $this->t('@min min', ['@min' => $service->getDuration()]),
        'price' => (float) $service->getPrice() > 0 ? $this->messages->price($service->getPrice(), $langcode) : '',
        'online' => $this->paymentNote($service, $langcode),
        'url' => Url::fromRoute('appointments.book_service', ['appointment_service' => $service->id()])->toString(),
      ];
    }
    return [
      '#theme' => 'appointments_services',
      '#services' => $items,
      '#attached' => ['library' => ['appointments/booking']],
      '#cache' => [
        'tags' => ['appointment_service_list', 'config:appointments.settings'],
        'contexts' => ['languages:language_interface'],
      ],
    ];
  }

  /**
   * Step 2: the free start times of a service, a week at a time.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  public function slots(AppointmentServiceInterface $appointment_service, Request $request): array {
    if (!$appointment_service->isPublished()) {
      throw new NotFoundHttpException();
    }
    $moving = $this->movingAppointment($request);
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
    $page = array_slice($dates, $offset, self::DAYS_PER_PAGE);
    $langcode = $this->languageManager()->getCurrentLanguage()->getId();
    $tz = $this->calendar->timezone();
    $formatter = $this->dateFormatter;
    $days = [];
    foreach ($page as $date) {
      $slots = [];
      foreach ($this->calendar->slots($appointment_service, $date, $moving ? (int) $moving->id() : NULL) as $start) {
        $url = $moving
          ? Url::fromRoute('appointments.move', ['token' => $moving->getToken(), 'start' => $start])
          : Url::fromRoute('appointments.book_slot', [
            'appointment_service' => $appointment_service->id(),
            'start' => $start,
          ]);
        $slots[] = [
          'time' => $formatter->format($start, 'custom', 'H:i', $tz, $langcode),
          'url' => $url->toString(),
        ];
      }
      $noon = (new \DateTimeImmutable($date . ' 12:00', new \DateTimeZone($tz)))->getTimestamp();
      $days[] = [
        'label' => $this->messages->day($noon, $langcode),
        'slots' => $slots,
      ];
    }

    $query = $moving ? ['reschedule' => $moving->getToken()] : [];
    $prev = $offset > 0 ? $dates[max(0, $offset - self::DAYS_PER_PAGE)] : NULL;
    $next = $dates[$offset + self::DAYS_PER_PAGE] ?? NULL;
    $route = ['appointment_service' => $appointment_service->id()];

    return [
      '#theme' => 'appointments_slots',
      '#service' => [
        'label' => $appointment_service->label(),
        'duration' => $this->t('@min min', ['@min' => $appointment_service->getDuration()]),
        'online' => $this->paymentNote($appointment_service, $langcode),
      ],
      '#days' => $days,
      '#moving' => $moving ? $this->messages->when($moving, $langcode) : '',
      '#prev' => $prev ? Url::fromRoute('appointments.book_service', $route, ['query' => $query + ['from' => $prev]])->toString() : '',
      '#next' => $next ? Url::fromRoute('appointments.book_service', $route, ['query' => $query + ['from' => $next]])->toString() : '',
      '#back' => Url::fromRoute('appointments.book')->toString(),
      '#timezone' => $tz,
      '#attached' => ['library' => ['appointments/booking']],
      '#cache' => ['max-age' => 0],
    ];
  }

  /**
   * Step 3: the client's details for a chosen start.
   *
   * @return array<string, mixed>|\Symfony\Component\HttpFoundation\RedirectResponse
   *   The booking form, or a redirect back to the free times.
   */
  public function form(AppointmentServiceInterface $appointment_service, int $start): array|RedirectResponse {
    if (!$appointment_service->isPublished()) {
      throw new NotFoundHttpException();
    }
    if (!$this->calendar->isAvailable($appointment_service, $start)) {
      $this->messenger()->addWarning($this->t('That time is no longer free. Please choose another.'));
      return $this->redirect('appointments.book_service', ['appointment_service' => $appointment_service->id()], [
        'query' => ['from' => $this->calendar->dateOf($start)],
      ]);
    }
    $appointment = $this->entityTypeManager()->getStorage('appointment')->create([
      'service' => $appointment_service->id(),
      'start' => $start,
    ]);
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
        'url' => Url::fromRoute('appointments.book_service', ['appointment_service' => $service->id()], ['query' => ['reschedule' => $token]])->toString(),
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
    $url = Url::fromRoute('appointments.manage', ['token' => $token], ['absolute' => TRUE])->toString();
    $body = Ics::build($appointment, $site, $url, $request->getHost());
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
