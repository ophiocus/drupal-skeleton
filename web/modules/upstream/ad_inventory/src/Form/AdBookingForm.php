<?php

declare(strict_types=1);

namespace Drupal\ad_inventory\Form;

use Drupal\ad_inventory\Coverage\Coverage;
use Drupal\ad_inventory\Coverage\CoverageCalculator;
use Drupal\ad_inventory\Coverage\CoverageResult;
use Drupal\ad_inventory\Coverage\CoverageStore;
use Drupal\ad_inventory\Entity\AdBooking;
use Drupal\ad_inventory\Entity\AdCreative;
use Drupal\ad_inventory\Event\AdBookingValidateEvent;
use Drupal\ad_inventory\Scope\ScopeCollector;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Books a creative in spaces, for some days, on some pages.
 *
 * Approval needs a creative that is approved and carries every format the
 * spaces use, pages still free in those spaces and days, and the site's
 * consent (the validate event), for example a show's registration code
 * matched in a register.
 *
 * Approving locks the pages: the booking covers every page of its scope that
 * no other exclusive booking holds in its spaces and days. A booking whose
 * pages are all held can't be approved; when other bookings hold some, the
 * form lists them and asks staff to confirm before saving (Coverage).
 */
final class AdBookingForm extends EntityForm {

  /**
   * Scope keys.
   */
  protected ScopeCollector $scopes;

  /**
   * The event dispatcher.
   */
  protected EventDispatcherInterface $dispatcher;

  /**
   * What a booking would lock.
   */
  protected CoverageCalculator $coverage;

  /**
   * What approved bookings locked.
   */
  protected CoverageStore $coverageStore;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = parent::create($container);
    $instance->scopes = $container->get('ad_inventory.scopes');
    $instance->dispatcher = $container->get('event_dispatcher');
    $instance->coverage = $container->get('ad_inventory.coverage');
    $instance->coverageStore = $container->get('ad_inventory.coverage_store');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);
    /** @var \Drupal\ad_inventory\Entity\AdBooking $booking */
    $booking = $this->entity;
    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Name'),
      '#description' => $this->t('For example the buyer and the week.'),
      '#default_value' => $booking->label(),
      '#required' => TRUE,
    ];
    $form['creative'] = [
      '#type' => 'entity_autocomplete',
      '#title' => $this->t('Creatives'),
      '#description' => $this->t('One or more, separated by commas, in order. They take turns in the space: the one with the fewest views this week shows next.'),
      '#target_type' => 'ad_creative',
      '#tags' => TRUE,
      '#default_value' => $booking->creatives(),
      '#required' => TRUE,
    ];
    $slots = [];
    foreach ($this->entityTypeManager->getStorage('ad_slot')->loadMultiple() as $id => $slot) {
      /** @var \Drupal\ad_inventory\Entity\AdSlot $slot */
      $slots[$id] = $slot->label() . ' (' . $slot->desktopFormat() . ' / ' . $slot->phoneFormat() . ')';
    }
    $form['slots'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Spaces'),
      '#options' => $slots,
      '#default_value' => $booking->slots(),
      '#required' => TRUE,
    ];
    $form['scope'] = [
      '#type' => 'select',
      '#title' => $this->t('Pages'),
      '#options' => ['' => $this->t('Every page of these spaces')] + $this->scopes->options(),
      '#default_value' => $booking->scope(),
      '#description' => $this->t('What the page is about, never who reads it.'),
    ];
    $form['start_day'] = [
      '#type' => 'date',
      '#title' => $this->t('First day'),
      '#default_value' => $booking->startDay(),
      '#required' => TRUE,
    ];
    $form['end_day'] = [
      '#type' => 'date',
      '#title' => $this->t('Last day'),
      '#default_value' => $booking->endDay(),
      '#required' => TRUE,
    ];
    $form['exclusive'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Exclusive: no other booking in these spaces and pages on these days'),
      '#default_value' => $booking->isNew() ? TRUE : (bool) $booking->get('exclusive')->value,
    ];
    $form['coverage_seen'] = [
      '#type' => 'hidden',
      '#default_value' => '',
    ];
    $form['coverage_confirm'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Confirm the pages this booking covers'),
      '#description' => $this->t('Asked when other bookings already hold some pages of its scope: the booking then covers only the pages still free, and locks them.'),
      '#default_value' => FALSE,
    ];
    $coverage = $booking->isNew() ? NULL : $this->coverageStore->get($booking);
    if ($coverage !== NULL && !$coverage->isWhole() && $booking->state() === AdBooking::APPROVED) {
      $form['coverage_now'] = [
        '#type' => 'item',
        '#title' => $this->t('Pages it locked'),
        '#markup' => $this->describe($coverage),
        '#weight' => -1,
      ];
    }
    $form['reference'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Reference code'),
      '#description' => $this->t("A code the buyer supplies that the site checks (for example a show's registration code)."),
      '#default_value' => $booking->get('reference')->value,
      '#maxlength' => 64,
    ];
    $form['state'] = [
      '#type' => 'radios',
      '#title' => $this->t('State'),
      '#options' => [
        AdBooking::DRAFT => $this->t('Draft: only staff who preview drafts see it'),
        AdBooking::APPROVED => $this->t('Approved: readers see it on its days'),
        AdBooking::STOPPED => $this->t('Stopped'),
      ],
      '#default_value' => $booking->state(),
    ];
    if ($booking->state() === AdBooking::STOPPED && $booking->get('state_reason')->value) {
      $form['state']['#description'] = $this->t('Stopped: @why', ['@why' => $booking->get('state_reason')->value]);
    }
    $form['notes'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Notes'),
      '#description' => $this->t('Price, invoice, contact. Staff only.'),
      '#default_value' => $booking->get('notes')->value,
      '#rows' => 3,
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);
    $start = (string) $form_state->getValue('start_day');
    $end = (string) $form_state->getValue('end_day');
    if ($start !== '' && $end !== '' && $end < $start) {
      $form_state->setErrorByName('end_day', $this->t('The last day comes before the first.'));
    }
    if ($form_state->getValue('state') !== AdBooking::APPROVED) {
      return;
    }
    $creatives = $this->entityTypeManager->getStorage('ad_creative')->loadMultiple(self::creativeIds($form_state->getValue('creative')));
    $slot_ids = array_values(array_filter((array) $form_state->getValue('slots')));
    if ($creatives === []) {
      return;
    }
    $slots = $this->entityTypeManager->getStorage('ad_slot')->loadMultiple($slot_ids);
    foreach ($creatives as $creative) {
      if (!$creative instanceof AdCreative) {
        continue;
      }
      if (!$creative->isApproved()) {
        $form_state->setErrorByName('creative', $this->t('Approve the creative %creative first.', ['%creative' => $creative->label()]));
      }
      foreach ($slots as $slot) {
        /** @var \Drupal\ad_inventory\Entity\AdSlot $slot */
        $missing = $creative->missing($slot->formats());
        if ($missing !== []) {
          $form_state->setErrorByName('slots', $this->t('%slot needs the creative %creative in @formats.', [
            '%slot' => $slot->label(),
            '%creative' => $creative->label(),
            '@formats' => implode(', ', $this->formatLabels($missing)),
          ]));
        }
        if (!$slot->allowsKind($creative->kind())) {
          $form_state->setErrorByName('slots', $this->t('%slot does not take @kind creatives.', [
            '%slot' => $slot->label(),
            '@kind' => $creative->kind(),
          ]));
        }
      }
    }
    $probe = clone $this->entity;
    $this->copyFormValuesToEntity($probe, $form, $form_state);
    $result = $this->coverage->calculate($probe);
    $lines = $result->refused() ? [] : $this->coverage->summary($probe, $result->coverage);
    $this->checkCoverage($form, $form_state, $result, $lines);

    $event = new AdBookingValidateEvent($probe, AdBookingValidateEvent::SAVE);
    $this->dispatcher->dispatch($event, AdBookingValidateEvent::NAME);
    foreach ($event->errors() as $error) {
      $form_state->setErrorByName('reference', $error);
    }
  }

  /**
   * Refuses a booking with no free page; asks to confirm a partial one.
   *
   * @param array $form
   *   The form, whose fingerprint field gets what was shown.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param \Drupal\ad_inventory\Coverage\CoverageResult $result
   *   What the booking would lock now.
   * @param string[] $lines
   *   The site's lines about it (pages, volume, price): with any, staff
   *   confirm even a whole coverage.
   */
  private function checkCoverage(array &$form, FormStateInterface $form_state, CoverageResult $result, array $lines): void {
    foreach ($result->holders as $other) {
      $form_state->setErrorByName('scope', $this->t('%other already holds every page of this booking in these spaces from @a to @b.', [
        '%other' => $other->label(),
        '@a' => $other->startDay(),
        '@b' => $other->endDay(),
      ]));
    }
    if ($result->refused()) {
      return;
    }
    $form_state->set('ad_coverage', $result->coverage);
    if ($result->coverage->isWhole() && $lines === []) {
      return;
    }
    // What was calculated is shown, and only that is saved: a booking saved
    // in between changes the fingerprint and the list is shown again.
    $fingerprint = $result->fingerprint($lines);
    $form['coverage_seen']['#value'] = $fingerprint;
    if ($form_state->getValue('coverage_confirm') && $form_state->getValue('coverage_seen') === $fingerprint) {
      return;
    }
    $message = $result->coverage->isWhole()
      ? $this->t('This booking would cover @pages.', ['@pages' => $this->describe($result->coverage, $result)])
      : $this->t('Other bookings already hold some of these pages. This booking would cover @pages.', ['@pages' => $this->describe($result->coverage, $result)]);
    $form_state->setErrorByName('coverage_confirm', $this->t('@message @lines Tick «Confirm the pages this booking covers» and save again.', [
      '@message' => $message,
      '@lines' => $lines === [] ? '' : implode(' ', $lines),
    ]));
  }

  /**
   * A coverage in words: its scope, what it is limited to, what it leaves.
   */
  private function describe(Coverage $coverage, ?CoverageResult $result = NULL): string {
    $holder = [];
    foreach ($result?->taken ?? [] as $item) {
      $holder[$item['key']] = (string) $item['booking']->label();
    }
    $words = $this->scopes->label($coverage->scope);
    if ($coverage->only !== []) {
      $words = (string) $this->t('@scope, only @keys', [
        '@scope' => $words,
        '@keys' => implode(', ', array_map($this->scopes->label(...), $coverage->only)),
      ]);
    }
    if ($coverage->exclude !== []) {
      $left = [];
      foreach ($coverage->exclude as $key) {
        $label = $this->scopes->label($key);
        $left[] = isset($holder[$key])
          ? (string) $this->t('@key (held by @booking)', ['@key' => $label, '@booking' => $holder[$key]])
          : $label;
      }
      $words = (string) $this->t('@scope, except @keys', ['@scope' => $words, '@keys' => implode('; ', $left)]);
    }
    return $words;
  }

  /**
   * {@inheritdoc}
   */
  protected function copyFormValuesToEntity(EntityInterface $entity, array $form, FormStateInterface $form_state): void {
    assert($entity instanceof AdBooking);
    foreach (['label', 'scope', 'start_day', 'end_day', 'reference', 'state', 'notes'] as $field) {
      $entity->set($field, trim((string) $form_state->getValue($field)));
    }
    $entity->setCreatives(self::creativeIds($form_state->getValue('creative')));
    $entity->setSlots(array_values(array_filter((array) $form_state->getValue('slots'))));
    $entity->set('exclusive', (bool) $form_state->getValue('exclusive'));
    if ($form_state->getValue('state') !== AdBooking::STOPPED) {
      $entity->set('state_reason', '');
    }
  }

  /**
   * Creative ids from the autocomplete's value, in order.
   *
   * @return int[]
   *   Ids.
   */
  private static function creativeIds(mixed $value): array {
    $ids = [];
    foreach (is_array($value) ? $value : [$value] as $item) {
      $id = (int) (is_array($item) ? ($item['target_id'] ?? 0) : $item);
      if ($id > 0 && !in_array($id, $ids, TRUE)) {
        $ids[] = $id;
      }
    }
    return $ids;
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    $result = parent::save($form, $form_state);
    /** @var \Drupal\ad_inventory\Entity\AdBooking $booking */
    $booking = $this->entity;
    $coverage = $form_state->get('ad_coverage');
    if ($booking->state() === AdBooking::APPROVED && $coverage instanceof Coverage) {
      $this->coverageStore->set($booking, $coverage);
    }
    else {
      $this->coverageStore->delete((string) $booking->id());
    }
    // The booking's own save evicted its pages before the coverage was
    // stored; evict them again so no page keeps the old reading.
    Cache::invalidateTags($booking->getCacheTagsToInvalidate());
    $this->messenger()->addStatus($this->t('Saved the booking %label.', ['%label' => $this->entity->label()]));
    $form_state->setRedirectUrl($this->entity->toUrl('collection'));
    return $result;
  }

  /**
   * The labels of these format ids, in order; an unknown id as itself.
   *
   * @param string[] $ids
   *   Format ids.
   *
   * @return string[]
   *   Their labels.
   */
  private function formatLabels(array $ids): array {
    $formats = $this->entityTypeManager->getStorage('ad_format')->loadMultiple($ids);
    return array_map(static fn (string $id): string => isset($formats[$id]) ? (string) $formats[$id]->label() : $id, $ids);
  }

}
