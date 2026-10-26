<?php

declare(strict_types=1);

namespace Drupal\appointments\Entity;

use Drupal\Core\Config\Entity\ConfigEntityInterface;

/**
 * A room: one parallel calendar that offers some or all of the services.
 */
interface AppointmentRoomInterface extends ConfigEntityInterface {

  /**
   * The keys of the services the room offers; empty means all of them.
   *
   * @return list<string>
   *   Service keys.
   */
  public function getServiceKeys(): array;

  /**
   * Whether the room offers a service.
   */
  public function offers(AppointmentServiceInterface $service): bool;

  /**
   * The weight that orders rooms in lists and in automatic assignment.
   */
  public function getWeight(): int;

}
