<?php

declare(strict_types=1);

namespace Drupal\appointments;

use Drupal\appointments\Entity\Appointment;
use Drupal\appointments\Entity\AppointmentInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The staff's appointment list: upcoming first, or the past on request.
 */
final class AppointmentListBuilder extends EntityListBuilder {

  /**
   * {@inheritdoc}
   */
  protected $limit = 50;

  public function __construct(
    EntityTypeInterface $entity_type,
    EntityStorageInterface $storage,
    protected Calendar $calendar,
    protected DateFormatterInterface $dateFormatter,
    protected TimeInterface $time,
    protected RequestStack $requestStack,
  ) {
    parent::__construct($entity_type, $storage);
  }

  /**
   * {@inheritdoc}
   */
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type): static {
    return new static(
      $entity_type,
      $container->get('entity_type.manager')->getStorage($entity_type->id()),
      $container->get('appointments.calendar'),
      $container->get('date.formatter'),
      $container->get('datetime.time'),
      $container->get('request_stack'),
    );
  }

  /**
   * Whether the list shows the past instead of what is coming.
   */
  private function showingPast(): bool {
    return $this->requestStack->getCurrentRequest()?->query->get('show') === 'past';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEntityIds(): array {
    // Upcoming: from the start of today, so today's earlier appointments can
    // still be marked completed or no-show.
    $today = (new \DateTimeImmutable($this->calendar->today() . ' 00:00', new \DateTimeZone($this->calendar->timezone())))->getTimestamp();
    $query = $this->getStorage()->getQuery()->accessCheck(TRUE);
    if ($this->showingPast()) {
      $query->condition('start', $today, '<')->sort('start', 'DESC');
    }
    else {
      $query->condition('start', $today, '>=')->sort('start', 'ASC');
    }
    if ($this->limit) {
      $query->pager($this->limit);
    }
    return $query->execute();
  }

  /**
   * {@inheritdoc}
   */
  public function buildHeader(): array {
    return [
      'when' => $this->t('When'),
      'service' => $this->t('Service'),
      'client' => $this->t('Client'),
      'contact' => $this->t('Contact'),
      'status' => $this->t('Status'),
    ] + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array {
    assert($entity instanceof AppointmentInterface);
    $service = $entity->getService();
    $statuses = Appointment::statusOptions();
    $client = (string) $entity->get('name')->value;
    foreach ($entity->getFieldDefinitions() as $name => $definition) {
      if (str_starts_with($name, 'field_') && !$entity->get($name)->isEmpty()) {
        $client .= ' · ' . (string) ($entity->get($name)->value ?? '');
      }
    }
    $status = (string) ($statuses[$entity->getStatus()] ?? $entity->getStatus());
    if ($entity->getStatus() === AppointmentInterface::PENDING && !$entity->isHolding($this->time->getCurrentTime())) {
      $status .= ' (' . $this->t('expired', [], ['context' => 'appointment status']) . ')';
    }
    return [
      'when' => $this->dateFormatter->format($entity->getStart(), 'custom', 'D Y-m-d H:i', $this->calendar->timezone()),
      'service' => $service ? $service->label() : '—',
      'client' => $client,
      'contact' => $entity->getEmail() . ' · ' . (string) $entity->get('phone')->value,
      'status' => $status,
    ] + parent::buildRow($entity);
  }

  /**
   * {@inheritdoc}
   */
  public function getDefaultOperations(EntityInterface $entity, ?CacheableMetadata $cacheability = NULL): array {
    $operations = parent::getDefaultOperations($entity, $cacheability);
    assert($entity instanceof AppointmentInterface);
    $actions = match ($entity->getStatus()) {
      AppointmentInterface::PENDING => [
        AppointmentInterface::CONFIRMED => $this->t('Confirm (paid)'),
        AppointmentInterface::CANCELLED => $this->t('Cancel'),
      ],
      AppointmentInterface::CONFIRMED => [
        AppointmentInterface::COMPLETED => Appointment::statusOptions()[AppointmentInterface::COMPLETED],
        AppointmentInterface::NO_SHOW => Appointment::statusOptions()[AppointmentInterface::NO_SHOW],
        AppointmentInterface::CANCELLED => $this->t('Cancel'),
      ],
      default => [],
    };
    $weight = 20;
    foreach ($actions as $status => $title) {
      $url = Url::fromRoute('appointments.status', ['appointment' => $entity->id(), 'status' => $status]);
      if ($url->access()) {
        $operations['status_' . $status] = ['title' => $title, 'url' => $url, 'weight' => $weight++];
      }
    }
    return $operations;
  }

  /**
   * {@inheritdoc}
   */
  public function render(): array {
    $build = parent::render();
    $past = $this->showingPast();
    $build['switch'] = [
      '#type' => 'link',
      '#title' => $past ? $this->t('Show upcoming appointments') : $this->t('Show past appointments'),
      '#url' => Url::fromRoute('entity.appointment.collection', [], $past ? [] : ['query' => ['show' => 'past']]),
      '#weight' => -10,
    ];
    $build['table']['#empty'] = $past ? $this->t('No past appointments.') : $this->t('No upcoming appointments.');
    $build['#cache']['contexts'][] = 'url.query_args:show';
    $build['#cache']['max-age'] = 0;
    return $build;
  }

}
