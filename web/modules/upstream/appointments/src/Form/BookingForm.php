<?php

declare(strict_types=1);

namespace Drupal\appointments\Form;

use Drupal\appointments\BookingManager;
use Drupal\appointments\Calendar;
use Drupal\appointments\Entity\AppointmentInterface;
use Drupal\appointments\Messages;
use Drupal\appointments\SlotUnavailableException;
use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The public booking form: the client's details for a chosen start.
 *
 * The service and the start come from the route and are not editable here;
 * the calendar fields and the staff fields are hidden. Fields a site added to
 * appointments show up by being in the form display.
 */
final class BookingForm extends ContentEntityForm {

  /**
   * Bookings one client address may make per hour.
   */
  private const FLOOD_LIMIT = 5;

  /**
   * Fields the client never sets.
   */
  private const HIDDEN = [
    'service',
    'start',
    'end',
    'busy_until',
    'status',
    'hold_expires',
    'payment',
    'amount',
    'reference',
    'token',
  ];

  /**
   * The booking manager.
   */
  protected BookingManager $booking;

  /**
   * The calendar.
   */
  protected Calendar $calendar;

  /**
   * The mail texts and formatters.
   */
  protected Messages $messages;

  /**
   * The flood control.
   */
  protected FloodInterface $flood;

  /**
   * The language manager.
   */
  protected LanguageManagerInterface $languageManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $form = parent::create($container);
    $form->booking = $container->get('appointments.booking');
    $form->calendar = $container->get('appointments.calendar');
    $form->messages = $container->get('appointments.messages');
    $form->flood = $container->get('flood');
    $form->languageManager = $container->get('language_manager');
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);
    foreach (self::HIDDEN as $name) {
      if (isset($form[$name])) {
        $form[$name]['#access'] = FALSE;
      }
    }
    $appointment = $this->entity;
    assert($appointment instanceof AppointmentInterface);
    $service = $appointment->getService();
    $langcode = $this->languageManager->getCurrentLanguage()->getId();
    $what = $this->t('@service, @when', [
      '@service' => $service ? $service->label() : '',
      '@when' => $this->messages->when($appointment, $langcode),
    ]);
    $form['summary'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['appointments-summary']],
      '#weight' => -100,
      'what' => [
        '#markup' => '<p class="appointments-summary__what"><strong>' . $what . '</strong></p>',
      ],
      'change' => [
        '#type' => 'link',
        '#title' => $this->t('Choose another time'),
        '#url' => Url::fromRoute('appointments.book_service', ['appointment_service' => $service?->id()], [
          'query' => ['from' => $this->calendar->dateOf($appointment->getStart())],
        ]),
      ],
    ];
    if ($service && (float) $this->booking->amountDue($service) > 0) {
      $form['summary']['pay'] = [
        '#markup' => '<p class="appointments-summary__pay">' . $this->t('To hold this time you pay @amount online on the next step.', [
          '@amount' => $this->messages->price($this->booking->amountDue($service), $langcode),
        ]) . '</p>',
      ];
    }
    $form['#attached']['library'][] = 'appointments/booking';
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  protected function actions(array $form, FormStateInterface $form_state): array {
    $actions = parent::actions($form, $form_state);
    $appointment = $this->entity;
    assert($appointment instanceof AppointmentInterface);
    $service = $appointment->getService();
    $paid = $service && (float) $this->booking->amountDue($service) > 0;
    $actions['submit']['#value'] = $paid ? $this->t('Continue to payment') : $this->t('Confirm the appointment');
    unset($actions['delete']);
    return $actions;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): AppointmentInterface {
    $entity = parent::validateForm($form, $form_state);
    assert($entity instanceof AppointmentInterface);
    if (!$this->flood->isAllowed('appointments.book', self::FLOOD_LIMIT, 3600)) {
      $form_state->setErrorByName('', $this->t('Too many bookings from your connection. Please call us, or try again in an hour.'));
    }
    return $entity;
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    $appointment = $this->entity;
    assert($appointment instanceof AppointmentInterface);
    $this->flood->register('appointments.book', 3600);
    try {
      $pay = $this->booking->book($appointment);
    }
    catch (SlotUnavailableException) {
      $this->messenger()->addError($this->t('Sorry, that time was just taken. Please choose another.'));
      $form_state->setRedirect('appointments.book_service', ['appointment_service' => $appointment->get('service')->target_id]);
      return 0;
    }
    if ($pay) {
      $form_state->setRedirectUrl($pay);
      return SAVED_NEW;
    }
    $this->messenger()->addStatus($this->t('Your appointment is booked. We sent the details to %mail.', ['%mail' => $appointment->getEmail()]));
    $form_state->setRedirect('appointments.manage', ['token' => $appointment->getToken()]);
    return SAVED_NEW;
  }

}
