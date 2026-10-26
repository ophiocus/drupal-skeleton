<?php

declare(strict_types=1);

namespace Drupal\appointments;

use Drupal\appointments\Entity\AppointmentInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Url;

/**
 * The texts of the appointment e-mails, for hook_mail().
 */
final class Messages {

  use StringTranslationTrait;

  /**
   * Builds the texts.
   *
   * The last argument is Commerce's currency formatter (commerce_price's
   * CurrencyFormatterInterface) where Commerce is installed, NULL elsewhere.
   * It is typed `object` because a site without Commerce does not carry that
   * interface, and an upstream module must load on every site.
   */
  public function __construct(
    private readonly Calendar $calendar,
    private readonly DateFormatterInterface $dateFormatter,
    private readonly ConfigFactoryInterface $configFactory,
    TranslationInterface $string_translation,
    private readonly ?object $currencyFormatter = NULL,
  ) {
    $this->setStringTranslation($string_translation);
  }

  /**
   * The start of an appointment, as the client reads it.
   */
  public function when(AppointmentInterface $appointment, ?string $langcode = NULL): string {
    return $this->date($appointment->getStart(), 'l j F Y, H:i', $langcode);
  }

  /**
   * A calendar day as a heading, e.g. "Monday 26 October".
   */
  public function day(int $timestamp, ?string $langcode = NULL): string {
    return $this->date($timestamp, 'l j F', $langcode);
  }

  /**
   * Formats a timestamp in the calendar's time zone.
   *
   * The PHP date format itself is translatable (context "appointments date"),
   * so a language can add its words: Spanish says "lunes 26 de octubre".
   */
  private function date(int $timestamp, string $format, ?string $langcode): string {
    // phpcs:ignore Drupal.Semantics.FunctionT.NotLiteralString
    $localized = (string) $this->t($format, [], ['context' => 'appointments date', 'langcode' => $langcode]);
    return $this->dateFormatter->format($timestamp, 'custom', $localized, $this->calendar->timezone(), $langcode);
  }

  /**
   * A price in the calendar's currency, formatted for the language.
   */
  public function price(string $amount, ?string $langcode = NULL): string {
    $currency = (string) ($this->calendar->settings()->get('currency') ?: 'USD');
    $value = (float) $amount;
    // Commerce's formatter needs no PHP intl extension and knows each
    // currency's decimals and the language's separators.
    if ($this->currencyFormatter !== NULL) {
      try {
        return $this->currencyFormatter->format((string) $value, $currency, ['locale' => $langcode ?? 'en']);
      }
      catch (\Throwable) {
        // An unknown currency code: fall through to the plain forms.
      }
    }
    if (class_exists(\NumberFormatter::class)) {
      $formatter = new \NumberFormatter($langcode ?? 'en', \NumberFormatter::CURRENCY);
      if (floor($value) === $value) {
        $formatter->setAttribute(\NumberFormatter::FRACTION_DIGITS, 0);
      }
      $formatted = $formatter->formatCurrency($value, $currency);
      if ($formatted !== FALSE) {
        return $formatted;
      }
    }
    $decimals = floor($value) === $value ? 0 : 2;
    return $currency . ' ' . number_format($value, $decimals);
  }

  /**
   * Fills a message array for hook_mail().
   *
   * @param string $key
   *   The mail key, '<event>.<audience>', e.g. confirmed.client.
   * @param array<string, mixed> $message
   *   The message, by reference.
   * @param array<string, mixed> $params
   *   The parameters: 'appointment' => the appointment.
   */
  public function build(string $key, array &$message, array $params): void {
    $appointment = $params['appointment'] ?? NULL;
    if (!$appointment instanceof AppointmentInterface) {
      return;
    }
    $langcode = $message['langcode'] ?? NULL;
    $options = ['langcode' => $langcode];
    [$event, $audience] = explode('.', $key, 2) + [1 => 'client'];
    $site = (string) $this->configFactory->get('system.site')->get('name');
    $service = $appointment->getService();
    $vars = [
      '@site' => $site,
      '@service' => $service ? $service->label() : '',
      '@when' => $this->when($appointment, $langcode),
      '@name' => (string) $appointment->get('name')->value,
    ];
    $manage = Url::fromRoute('appointments.manage', ['token' => $appointment->getToken()], ['absolute' => TRUE])->toString();
    $ics = Url::fromRoute('appointments.ics', ['token' => $appointment->getToken()], ['absolute' => TRUE])->toString();

    if ($audience === 'staff') {
      $message['subject'] = match ($event) {
        'confirmed' => $this->t('New appointment: @service, @when', $vars, $options),
        'rescheduled' => $this->t('Appointment moved: @service, @when', $vars, $options),
        'cancelled' => $this->t('Appointment cancelled: @service, @when', $vars, $options),
        'conflict' => $this->t('Paid appointment needs attention: @service, @when', $vars, $options),
        default => $this->t('Appointment: @service, @when', $vars, $options),
      };
      $lines = [
        (string) $message['subject'],
        '',
        $this->t('Client: @name', $vars, $options),
        $this->t('E-mail: @mail', ['@mail' => $appointment->getEmail()], $options),
        $this->t('Phone: @phone', ['@phone' => (string) $appointment->get('phone')->value], $options),
      ];
      foreach ($this->extraFields($appointment) as $label => $value) {
        $lines[] = $label . ': ' . $value;
      }
      if ((string) $appointment->get('notes')->value !== '') {
        $lines[] = $this->t('Notes: @notes', ['@notes' => (string) $appointment->get('notes')->value], $options);
      }
      if ($event === 'conflict') {
        $lines[] = '';
        $lines[] = $this->t('The payment arrived after the hold ran out and the slot had been taken. Contact the client to agree a new time or a refund (payment reference: @ref).', ['@ref' => (string) $appointment->get('reference')->value], $options);
      }
      $lines[] = '';
      $lines[] = Url::fromRoute('entity.appointment.collection', [], ['absolute' => TRUE])->toString();
    }
    else {
      $message['subject'] = match ($event) {
        'confirmed' => $this->t('Your appointment at @site: @when', $vars, $options),
        'rescheduled' => $this->t('Your appointment at @site has moved: @when', $vars, $options),
        'cancelled' => $this->t('Your appointment at @site is cancelled', $vars, $options),
        'expired' => $this->t('Your booking at @site was not completed', $vars, $options),
        default => $this->t('Your appointment at @site', $vars, $options),
      };
      $lines = [$this->t('Hello @name,', $vars, $options), ''];
      $lines[] = match ($event) {
        'confirmed' => $this->t('Your appointment is confirmed: @service, @when.', $vars, $options),
        'rescheduled' => $this->t('Your appointment has moved to @when (@service).', $vars, $options),
        'cancelled' => $this->t('Your appointment of @when (@service) is cancelled.', $vars, $options),
        'expired' => $this->t('The payment for @service on @when was not completed in time, so the slot was released. You are welcome to book again.', $vars, $options),
        default => $this->t('@service, @when.', $vars, $options),
      };
      if (in_array($event, ['confirmed', 'rescheduled'], TRUE)) {
        $lines[] = '';
        $lines[] = $this->t('Add it to your calendar: @ics', ['@ics' => $ics], $options);
        $lines[] = $this->t('To cancel or change it: @url', ['@url' => $manage], $options);
      }
      elseif ($event === 'expired' || $event === 'cancelled') {
        $lines[] = '';
        $lines[] = Url::fromRoute('appointments.book', [], ['absolute' => TRUE])->toString();
      }
      $lines[] = '';
      $lines[] = $site;
    }
    $message['body'][] = implode("\n", array_map('strval', $lines));
  }

  /**
   * The values of the fields a site added to appointments, by label.
   *
   * @return array<string, string>
   *   Field label => rendered plain value.
   */
  public function extraFields(AppointmentInterface $appointment): array {
    $values = [];
    foreach ($appointment->getFieldDefinitions() as $name => $definition) {
      if (!str_starts_with($name, 'field_') || $appointment->get($name)->isEmpty()) {
        continue;
      }
      $item = $appointment->get($name)->first();
      $value = $item ? $item->getValue() : [];
      $plain = (string) ($value['value'] ?? $value['target_id'] ?? '');
      $allowed = $definition->getFieldStorageDefinition()->getSetting('allowed_values');
      if (is_array($allowed) && isset($allowed[$plain])) {
        $plain = (string) $allowed[$plain];
      }
      $values[(string) $definition->getLabel()] = $plain;
    }
    return $values;
  }

}
