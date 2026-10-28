<?php

declare(strict_types=1);

namespace Drupal\ad_inventory\Form;

use Drupal\ad_inventory\Entity\AdBooking;
use Drupal\ad_inventory\Entity\AdCreative;
use Drupal\ad_inventory\Event\AdBookingValidateEvent;
use Drupal\ad_inventory\Scope\ScopeCollector;
use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Books a creative in spaces, for some days, on some pages.
 *
 * Approval needs a creative that is approved and carries every format the
 * spaces use, no other exclusive booking over the same spaces, scope and
 * days, and the site's consent (the validate event), for example a show's
 * registration code matched in a register.
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
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = parent::create($container);
    $instance->scopes = $container->get('ad_inventory.scopes');
    $instance->dispatcher = $container->get('event_dispatcher');
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
      '#title' => $this->t('Creative'),
      '#target_type' => 'ad_creative',
      '#default_value' => $booking->creative(),
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
    $creative = $this->entityTypeManager->getStorage('ad_creative')->load((int) $form_state->getValue('creative'));
    $slot_ids = array_values(array_filter((array) $form_state->getValue('slots')));
    if (!$creative instanceof AdCreative) {
      return;
    }
    if (!$creative->isApproved()) {
      $form_state->setErrorByName('creative', $this->t('Approve the creative first.'));
    }
    foreach ($this->entityTypeManager->getStorage('ad_slot')->loadMultiple($slot_ids) as $slot) {
      /** @var \Drupal\ad_inventory\Entity\AdSlot $slot */
      $missing = $creative->missing($slot->formats());
      if ($missing !== []) {
        $form_state->setErrorByName('slots', $this->t('%slot needs the creative in @formats.', [
          '%slot' => $slot->label(),
          '@formats' => implode(', ', $missing),
        ]));
      }
      if (!$slot->allowsKind($creative->kind())) {
        $form_state->setErrorByName('slots', $this->t('%slot does not take @kind creatives.', [
          '%slot' => $slot->label(),
          '@kind' => $creative->kind(),
        ]));
      }
    }
    $this->checkOverlap($form_state, $slot_ids, (string) $form_state->getValue('scope'), $start, $end);

    $probe = clone $this->entity;
    $this->copyFormValuesToEntity($probe, $form, $form_state);
    $event = new AdBookingValidateEvent($probe, AdBookingValidateEvent::SAVE);
    $this->dispatcher->dispatch($event, AdBookingValidateEvent::NAME);
    foreach ($event->errors() as $error) {
      $form_state->setErrorByName('reference', $error);
    }
  }

  /**
   * Refuses an exclusive overlap with another approved booking.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param string[] $slot_ids
   *   Spaces.
   * @param string $scope
   *   Scope key.
   * @param string $start
   *   First day.
   * @param string $end
   *   Last day.
   */
  private function checkOverlap(FormStateInterface $form_state, array $slot_ids, string $scope, string $start, string $end): void {
    $storage = $this->entityTypeManager->getStorage('ad_booking');
    $ids = $storage->getQuery()->accessCheck(FALSE)->condition('state', AdBooking::APPROVED)->execute();
    foreach ($storage->loadMultiple($ids) as $other) {
      if (!$other instanceof AdBooking || $other->id() === $this->entity->id()) {
        continue;
      }
      $exclusive = $form_state->getValue('exclusive') || (bool) $other->get('exclusive')->value;
      $same_pages = $other->scope() === $scope || $other->scope() === '' || $scope === '';
      $days = $other->startDay() <= $end && $start <= $other->endDay();
      $shared = array_intersect($slot_ids, $other->slots());
      if ($exclusive && $same_pages && $days && $shared !== []) {
        $form_state->setErrorByName('slots', $this->t('%other already holds @slots from @a to @b.', [
          '%other' => $other->label(),
          '@slots' => implode(', ', $shared),
          '@a' => $other->startDay(),
          '@b' => $other->endDay(),
        ]));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function copyFormValuesToEntity(EntityInterface $entity, array $form, FormStateInterface $form_state): void {
    assert($entity instanceof AdBooking);
    foreach (['label', 'scope', 'start_day', 'end_day', 'reference', 'state', 'notes'] as $field) {
      $entity->set($field, trim((string) $form_state->getValue($field)));
    }
    $entity->set('creative', (int) $form_state->getValue('creative') ?: NULL);
    $entity->setSlots(array_values(array_filter((array) $form_state->getValue('slots'))));
    $entity->set('exclusive', (bool) $form_state->getValue('exclusive'));
    if ($form_state->getValue('state') !== AdBooking::STOPPED) {
      $entity->set('state_reason', '');
    }
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    $result = parent::save($form, $form_state);
    $this->messenger()->addStatus($this->t('Saved the booking %label.', ['%label' => $this->entity->label()]));
    $form_state->setRedirectUrl($this->entity->toUrl('collection'));
    return $result;
  }

}
