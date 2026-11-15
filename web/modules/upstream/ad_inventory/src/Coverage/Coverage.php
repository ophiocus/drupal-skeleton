<?php

declare(strict_types=1);

namespace Drupal\ad_inventory\Coverage;

use Drupal\ad_inventory\Scope\AdScope;

/**
 * The pages an approved booking locked when it was bought.
 *
 * A booking covers every page of its scope that was still free when it was
 * approved, and locks them: another booking cannot take them afterwards.
 * Pages another booking already held stay with it. Stored as keys, not as a
 * list of addresses, so a page that appears later under the scope (a new
 * show in the city) belongs to the booking too.
 *
 * - scope: the booking's scope key ('' is every page);
 * - only: when not empty, the booking holds only pages carrying one of these
 *   keys (a wider booking held the rest of the scope already);
 * - exclude: pages carrying any of these keys belong to other bookings.
 */
final class Coverage {

  /**
   * Builds a coverage.
   *
   * @param string $scope
   *   The booking's scope key.
   * @param string[] $only
   *   Keys the booking is limited to (empty: the whole scope).
   * @param string[] $exclude
   *   Keys whose pages other bookings hold.
   */
  public function __construct(
    public readonly string $scope,
    public readonly array $only = [],
    public readonly array $exclude = [],
  ) {}

  /**
   * Whether the booking shows on a page.
   */
  public function matches(AdScope $page): bool {
    if (!$page->matches($this->scope)) {
      return FALSE;
    }
    if ($this->only !== [] && array_intersect($this->only, $page->keys) === []) {
      return FALSE;
    }
    return array_intersect($this->exclude, $page->keys) === [];
  }

  /**
   * Whether the booking holds its whole scope.
   */
  public function isWhole(): bool {
    return $this->only === [] && $this->exclude === [];
  }

  /**
   * The coverage as stored.
   *
   * @return array{scope: string, only: string[], exclude: string[]}
   *   The values.
   */
  public function toArray(): array {
    return ['scope' => $this->scope, 'only' => $this->only, 'exclude' => $this->exclude];
  }

  /**
   * A stored coverage.
   *
   * @param array<string, mixed> $values
   *   As toArray() wrote them.
   */
  public static function fromArray(array $values): self {
    $keys = static fn ($list): array => array_values(array_filter((array) $list, 'is_string'));
    return new self((string) ($values['scope'] ?? ''), $keys($values['only'] ?? []), $keys($values['exclude'] ?? []));
  }

}
