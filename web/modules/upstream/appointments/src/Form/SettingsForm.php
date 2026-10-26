<?php

declare(strict_types=1);

namespace Drupal\appointments\Form;

use Drupal\appointments\BookingManager;
use Drupal\appointments\Calendar;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The calendar settings: hours, closures, booking rules and payment.
 */
final class SettingsForm extends ConfigFormBase {

  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typed_config_manager,
    protected BookingManager $booking,
  ) {
    parent::__construct($config_factory, $typed_config_manager);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('appointments.booking'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'appointments_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['appointments.settings'];
  }

  /**
   * The weekday names, by ISO number.
   *
   * @return array<int, \Drupal\Core\StringTranslation\TranslatableMarkup>
   *   Names keyed 1 (Monday) to 7 (Sunday).
   */
  private function weekdays(): array {
    return [
      1 => $this->t('Monday'),
      2 => $this->t('Tuesday'),
      3 => $this->t('Wednesday'),
      4 => $this->t('Thursday'),
      5 => $this->t('Friday'),
      6 => $this->t('Saturday'),
      7 => $this->t('Sunday'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('appointments.settings');

    $form['hours'] = [
      '#type' => 'details',
      '#title' => $this->t('Opening hours'),
      '#open' => TRUE,
      '#description' => $this->t('Ranges per day as HH:MM-HH:MM, comma-separated (e.g. 08:00-12:00, 14:00-18:00). Leave a day empty when closed.'),
      '#tree' => TRUE,
    ];
    $by_day = [];
    foreach ((array) $config->get('hours') as $row) {
      $by_day[(int) $row['day']][] = $row['start'] . '-' . $row['end'];
    }
    foreach ($this->weekdays() as $day => $name) {
      $form['hours'][$day] = [
        '#type' => 'textfield',
        '#title' => $name,
        '#default_value' => implode(', ', $by_day[$day] ?? []),
        '#size' => 40,
      ];
    }

    $form['closures'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Closed dates'),
      '#description' => $this->t('One date per line, YYYY-MM-DD (holidays, vacations).'),
      '#default_value' => implode("\n", (array) $config->get('closures')),
      '#rows' => 5,
    ];

    $form['rules'] = [
      '#type' => 'details',
      '#title' => $this->t('Booking rules'),
      '#open' => TRUE,
    ];
    $numbers = [
      'slot_step' => [$this->t('Minutes between offered start times'), 5],
      'lead_time' => [$this->t('Minimum notice (minutes)'), 0],
      'horizon' => [$this->t('Days ahead that can be booked'), 1],
      'capacity' => [$this->t('Appointments at the same time'), 1],
      'cancel_cutoff' => [$this->t('Clients can cancel or move until this many minutes before'), 0],
      'hold' => [$this->t('Minutes an unpaid booking holds its time'), 5],
    ];
    foreach ($numbers as $key => [$title, $min]) {
      $form['rules'][$key] = [
        '#type' => 'number',
        '#title' => $title,
        '#min' => $min,
        '#required' => TRUE,
        '#default_value' => (int) $config->get($key),
      ];
    }
    $form['rules']['timezone'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Time zone'),
      '#description' => $this->t('An IANA name such as America/Bogota. Empty: the site default.'),
      '#default_value' => (string) $config->get('timezone'),
    ];
    $form['rules']['notify'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Staff e-mail'),
      '#description' => $this->t('Who is told about new, moved and cancelled appointments. Comma-separated. Empty: the site e-mail.'),
      '#default_value' => (string) $config->get('notify'),
    ];

    $has_checkout = $this->booking->hasCheckout();
    $form['payment'] = [
      '#type' => 'details',
      '#title' => $this->t('Payment'),
      '#open' => TRUE,
      '#tree' => TRUE,
    ];
    $form['payment']['mode'] = [
      '#type' => 'radios',
      '#title' => $this->t('When booking, the client pays'),
      '#options' => [
        'none' => $this->t('Nothing online (pays at the appointment)'),
        'deposit' => $this->t('A deposit online'),
        'full' => $this->t('The full price online'),
      ],
      '#default_value' => (string) ($config->get('payment.mode') ?: 'none'),
      '#description' => $has_checkout
        ? $this->t('Online payment goes through the shop checkout. Services priced 0 are always booked without payment.')
        : $this->t('Online payment needs the Appointments: Commerce module. Until it is enabled, every booking is free.'),
    ];
    $form['payment']['deposit_type'] = [
      '#type' => 'radios',
      '#title' => $this->t('Deposit', [], ['context' => 'appointment payment']),
      '#options' => [
        'percent' => $this->t('A percentage of the price'),
        'fixed' => $this->t('A fixed amount'),
      ],
      '#default_value' => (string) ($config->get('payment.deposit_type') ?: 'percent'),
      '#states' => ['visible' => [':input[name="payment[mode]"]' => ['value' => 'deposit']]],
    ];
    $form['payment']['deposit_value'] = [
      '#type' => 'number',
      '#title' => $this->t('Deposit value'),
      '#min' => 0,
      '#step' => '0.01',
      '#default_value' => (string) $config->get('payment.deposit_value'),
      '#states' => ['visible' => [':input[name="payment[mode]"]' => ['value' => 'deposit']]],
    ];
    $form['payment']['currency'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Currency'),
      '#description' => $this->t('ISO 4217 code of the service prices, e.g. COP.'),
      '#size' => 3,
      '#maxlength' => 3,
      '#required' => TRUE,
      '#default_value' => (string) $config->get('currency'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);
    $hours = [];
    foreach ((array) $form_state->getValue('hours') as $day => $text) {
      foreach (array_filter(array_map('trim', explode(',', (string) $text))) as $range) {
        if (!preg_match('/^(\d{2}:\d{2})\s*-\s*(\d{2}:\d{2})$/', $range, $m) || !self::validTime($m[1]) || !self::validTime($m[2]) || $m[2] <= $m[1]) {
          $form_state->setErrorByName("hours][$day", $this->t('%range is not a range like 08:00-12:00.', ['%range' => $range]));
          continue;
        }
        $hours[] = ['day' => (int) $day, 'start' => $m[1], 'end' => $m[2]];
      }
    }
    $form_state->set('parsed_hours', $hours);

    $closures = [];
    foreach (preg_split('/\R/', (string) $form_state->getValue('closures')) ?: [] as $line) {
      $line = trim($line);
      if ($line === '') {
        continue;
      }
      if (!Calendar::isDate($line)) {
        $form_state->setErrorByName('closures', $this->t('%date is not a date like 2026-12-25.', ['%date' => $line]));
        continue;
      }
      $closures[$line] = $line;
    }
    ksort($closures);
    $form_state->set('parsed_closures', array_values($closures));

    $tz = trim((string) $form_state->getValue('timezone'));
    if ($tz !== '' && !in_array($tz, \DateTimeZone::listIdentifiers(), TRUE)) {
      $form_state->setErrorByName('timezone', $this->t('%tz is not a time zone name.', ['%tz' => $tz]));
    }
    $currency = strtoupper(trim((string) $form_state->getValue(['payment', 'currency'])));
    if (!preg_match('/^[A-Z]{3}$/', $currency)) {
      $form_state->setErrorByName('payment][currency', $this->t('Use a three-letter currency code.'));
    }
    $mode = (string) $form_state->getValue(['payment', 'mode']);
    if ($mode === 'deposit' && (string) $form_state->getValue(['payment', 'deposit_type']) === 'percent'
      && (float) $form_state->getValue(['payment', 'deposit_value']) > 100) {
      $form_state->setErrorByName('payment][deposit_value', $this->t('A percentage cannot exceed 100.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('appointments.settings')
      ->set('hours', $form_state->get('parsed_hours'))
      ->set('closures', $form_state->get('parsed_closures'))
      ->set('slot_step', (int) $form_state->getValue('slot_step'))
      ->set('lead_time', (int) $form_state->getValue('lead_time'))
      ->set('horizon', (int) $form_state->getValue('horizon'))
      ->set('capacity', (int) $form_state->getValue('capacity'))
      ->set('cancel_cutoff', (int) $form_state->getValue('cancel_cutoff'))
      ->set('hold', (int) $form_state->getValue('hold'))
      ->set('timezone', trim((string) $form_state->getValue('timezone')))
      ->set('notify', trim((string) $form_state->getValue('notify')))
      ->set('currency', strtoupper(trim((string) $form_state->getValue(['payment', 'currency']))))
      ->set('payment', [
        'mode' => (string) $form_state->getValue(['payment', 'mode']),
        'deposit_type' => (string) $form_state->getValue(['payment', 'deposit_type']),
        'deposit_value' => (string) $form_state->getValue(['payment', 'deposit_value']),
      ])
      ->save();
    parent::submitForm($form, $form_state);
  }

  /**
   * Whether HH:MM is a valid wall-clock time (24:00 allowed as an end).
   */
  private static function validTime(string $time): bool {
    return $time === '24:00' || (bool) preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time);
  }

}
