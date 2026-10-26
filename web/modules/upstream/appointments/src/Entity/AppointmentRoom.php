<?php

declare(strict_types=1);

namespace Drupal\appointments\Entity;

use Drupal\appointments\AppointmentRoomListBuilder;
use Drupal\appointments\Form\AppointmentRoomForm;
use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\Core\Entity\Attribute\ConfigEntityType;
use Drupal\Core\Entity\EntityDeleteForm;
use Drupal\Core\Entity\Routing\AdminHtmlRouteProvider;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * A room of the clinic: one more calendar running in parallel.
 *
 * Config, not content: rooms are the shape of the calendar, like the opening
 * hours, and travel with the site's config. Each room takes one appointment
 * at a time and follows the calendar's opening hours. With no rooms defined,
 * the calendar is one room whose parallel capacity is the `capacity` setting.
 */
#[ConfigEntityType(
  id: 'appointment_room',
  label: new TranslatableMarkup('Room'),
  label_collection: new TranslatableMarkup('Rooms'),
  label_singular: new TranslatableMarkup('room'),
  label_plural: new TranslatableMarkup('rooms'),
  config_prefix: 'room',
  entity_keys: [
    'id' => 'id',
    'label' => 'label',
    'status' => 'status',
    'weight' => 'weight',
  ],
  handlers: [
    'list_builder' => AppointmentRoomListBuilder::class,
    'form' => [
      'default' => AppointmentRoomForm::class,
      'delete' => EntityDeleteForm::class,
    ],
    'route_provider' => ['html' => AdminHtmlRouteProvider::class],
  ],
  links: [
    'collection' => '/admin/appointments/rooms',
    'add-form' => '/admin/appointments/rooms/add',
    'edit-form' => '/admin/appointments/rooms/{appointment_room}',
    'delete-form' => '/admin/appointments/rooms/{appointment_room}/delete',
  ],
  admin_permission: 'administer appointments',
  label_count: [
    'singular' => '@count room',
    'plural' => '@count rooms',
  ],
  config_export: ['id', 'label', 'weight', 'services'],
)]
final class AppointmentRoom extends ConfigEntityBase implements AppointmentRoomInterface {

  /**
   * The machine name.
   */
  protected ?string $id = NULL;

  /**
   * The name clients see.
   */
  protected ?string $label = NULL;

  /**
   * The order in lists and in automatic assignment.
   */
  protected int $weight = 0;

  /**
   * Keys of the services offered; empty means all.
   *
   * @var list<string>
   */
  protected array $services = [];

  /**
   * {@inheritdoc}
   */
  public function getServiceKeys(): array {
    return array_values(array_filter(array_map('strval', $this->services)));
  }

  /**
   * {@inheritdoc}
   */
  public function offers(AppointmentServiceInterface $service): bool {
    $keys = $this->getServiceKeys();
    return $keys === [] || in_array($service->getKey(), $keys, TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function getWeight(): int {
    return $this->weight;
  }

}
