<?php

declare(strict_types=1);

namespace Drupal\ad_inventory\Coverage;

use Drupal\ad_inventory\Entity\AdBooking;
use Drupal\ad_inventory\Scope\ScopeCollector;
use Drupal\ad_inventory\Scope\ScopeRelationInterface as R;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * What a booking would cover if it were approved now.
 *
 * The rule: a booking takes every page of its scope that is still free in
 * its spaces and days, and locks them. A booking whose pages are all held
 * already cannot be bought ("the blanket coverage does not allow the second
 * purchase"); a wider booking bought after a narrower one takes what is left
 * ("Blanket covers what is available, it will be calculated before
 * confirmation"; "Blanket works by retrieving all remaining available and
 * locking those on purchase": the owner's ruling, 2026-11-12). Two bookings
 * lock each other only when one of them is exclusive, as before.
 */
final class CoverageCalculator {

  /**
   * The sites' summaries (pages, volume, price).
   *
   * @var \Drupal\ad_inventory\Coverage\CoverageSummaryInterface[]
   */
  private array $summaries = [];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ScopeCollector $scopes,
    private readonly CoverageStore $store,
  ) {}

  /**
   * Adds a summary (service collector).
   */
  public function addSummary(CoverageSummaryInterface $summary): void {
    $this->summaries[] = $summary;
  }

  /**
   * Every site line about what a booking would lock.
   *
   * @return string[]
   *   Lines, in the summaries' order.
   */
  public function summary(AdBooking $booking, Coverage $coverage): array {
    $lines = [];
    foreach ($this->summaries as $summary) {
      array_push($lines, ...$summary->summary($booking, $coverage));
    }
    return $lines;
  }

  /**
   * The coverage a booking (saved or not) would lock.
   */
  public function calculate(AdBooking $booking): CoverageResult {
    $scope = $booking->scope();
    $exclusive = (bool) $booking->get('exclusive')->value;
    $only = NULL;
    $exclude = [];
    $taken = [];
    $kept = [];
    $holders = [];

    foreach ($this->rivals($booking) as $other) {
      if (!$exclusive && !$other->get('exclusive')->value) {
        continue;
      }
      $theirs = $this->store->get($other) ?? new Coverage($other->scope());
      $relation = $this->scopes->relation($theirs->scope, $scope);
      if ($relation === R::DISJOINT) {
        continue;
      }
      if ($relation === R::WITHIN || $relation === R::OVERLAPS) {
        // Part of our pages are theirs: they keep them.
        $exclude[$theirs->scope] = TRUE;
        $taken[] = ['key' => $theirs->scope, 'booking' => $other];
        continue;
      }
      // They hold all of our scope, but for what they were limited to or
      // left out when they were bought.
      if (!$this->holdsAll($theirs, $scope, $exclude, $taken, $other)) {
        continue;
      }
      $left = [];
      foreach ($theirs->exclude as $key) {
        $part = $this->scopes->relation($key, $scope);
        if ($part === R::SAME || $part === R::CONTAINS) {
          // They left out all of ours.
          continue 2;
        }
        if ($part === R::WITHIN || $part === R::OVERLAPS) {
          $left[] = $key;
        }
      }
      if ($left === []) {
        $holders[] = $other;
        continue;
      }
      $only = $only === NULL ? $left : array_values(array_intersect($only, $left));
      $kept[] = ['keys' => $left, 'booking' => $other];
      if ($only === []) {
        $holders[] = $other;
      }
    }

    if ($only !== NULL && $holders === []) {
      $only = array_values(array_diff($only, array_keys($exclude)));
      if ($only === []) {
        $holders = array_column($kept, 'booking');
      }
    }
    return new CoverageResult(new Coverage($scope, $only ?? [], array_keys($exclude)), $taken, $kept, $holders);
  }

  /**
   * Whether a rival that holds a wider scope holds all of ours.
   *
   * A rival limited to some keys holds only their pages: those it holds
   * inside our scope go to $exclude, and it holds all of ours only when one of
   * its keys does.
   *
   * @param \Drupal\ad_inventory\Coverage\Coverage $theirs
   *   The rival's coverage.
   * @param string $scope
   *   Our scope key.
   * @param array<string, bool> $exclude
   *   Our excluded keys, added to.
   * @param array<int, array{key: string, booking: \Drupal\ad_inventory\Entity\AdBooking}> $taken
   *   What others hold of ours, added to.
   * @param \Drupal\ad_inventory\Entity\AdBooking $other
   *   The rival.
   */
  private function holdsAll(Coverage $theirs, string $scope, array &$exclude, array &$taken, AdBooking $other): bool {
    if ($theirs->only === []) {
      return TRUE;
    }
    $all = FALSE;
    foreach ($theirs->only as $key) {
      $part = $this->scopes->relation($key, $scope);
      if ($part === R::SAME || $part === R::CONTAINS) {
        $all = TRUE;
      }
      elseif ($part === R::WITHIN || $part === R::OVERLAPS) {
        $exclude[$key] = TRUE;
        $taken[] = ['key' => $key, 'booking' => $other];
      }
    }
    return $all;
  }

  /**
   * Approved bookings that share a space and a day with this one.
   *
   * @return \Drupal\ad_inventory\Entity\AdBooking[]
   *   The rivals, oldest first.
   */
  private function rivals(AdBooking $booking): array {
    $storage = $this->entityTypeManager->getStorage('ad_booking');
    $ids = $storage->getQuery()->accessCheck(FALSE)
      ->condition('state', AdBooking::APPROVED)
      ->sort('id')
      ->execute();
    $rivals = [];
    foreach ($storage->loadMultiple($ids) as $other) {
      if (!$other instanceof AdBooking || (!$booking->isNew() && $other->id() === $booking->id())) {
        continue;
      }
      $days = $other->startDay() <= $booking->endDay() && $booking->startDay() <= $other->endDay();
      if ($days && array_intersect($booking->slots(), $other->slots()) !== []) {
        $rivals[] = $other;
      }
    }
    return $rivals;
  }

}
