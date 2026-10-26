<?php

declare(strict_types=1);

namespace Drupal\appointments\Entity;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\Core\Entity\EntityPublishedInterface;

/**
 * A bookable service: what the client books, and how long it takes.
 */
interface AppointmentServiceInterface extends ContentEntityInterface, EntityChangedInterface, EntityPublishedInterface {

  /**
   * The stable key seeders locate the service by (labels are editable copy).
   */
  public function getKey(): string;

  /**
   * Minutes the appointment itself lasts.
   */
  public function getDuration(): int;

  /**
   * Minutes kept free after the appointment (cleaning, notes).
   */
  public function getBuffer(): int;

  /**
   * The price as a decimal string, or '0' when the service is free.
   */
  public function getPrice(): string;

}
