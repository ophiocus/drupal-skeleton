<?php

declare(strict_types=1);

namespace Drupal\edge_purge\Cache;

use Drupal\Core\Cache\CacheBackendInterface;

/**
 * A cache bin that ignores deleteAll() and passes everything else through.
 *
 * Stands in for a bin only while SoftRebuild runs core's cache flush, so the
 * flush empties every other bin and leaves this one's items. The rebuilt
 * container that follows the flush no longer holds it.
 */
final class FlushShield implements CacheBackendInterface {

  public function __construct(
    private readonly CacheBackendInterface $bin,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function deleteAll(): void {
    // The point of this class: keep the items.
  }

  /**
   * {@inheritdoc}
   */
  public function get($cid, $allow_invalid = FALSE) {
    return $this->bin->get($cid, $allow_invalid);
  }

  /**
   * {@inheritdoc}
   */
  public function getMultiple(&$cids, $allow_invalid = FALSE) {
    return $this->bin->getMultiple($cids, $allow_invalid);
  }

  /**
   * {@inheritdoc}
   */
  public function set($cid, $data, $expire = CacheBackendInterface::CACHE_PERMANENT, array $tags = []) {
    $this->bin->set($cid, $data, $expire, $tags);
  }

  /**
   * {@inheritdoc}
   */
  public function setMultiple(array $items) {
    $this->bin->setMultiple($items);
  }

  /**
   * {@inheritdoc}
   */
  public function delete($cid) {
    $this->bin->delete($cid);
  }

  /**
   * {@inheritdoc}
   */
  public function deleteMultiple(array $cids) {
    $this->bin->deleteMultiple($cids);
  }

  /**
   * {@inheritdoc}
   */
  public function invalidate($cid) {
    $this->bin->invalidate($cid);
  }

  /**
   * {@inheritdoc}
   */
  public function invalidateMultiple(array $cids) {
    $this->bin->invalidateMultiple($cids);
  }

  /**
   * {@inheritdoc}
   */
  public function invalidateAll() {
    $this->bin->invalidateAll();
  }

  /**
   * {@inheritdoc}
   */
  public function garbageCollection() {
    $this->bin->garbageCollection();
  }

  /**
   * {@inheritdoc}
   */
  public function removeBin() {
    $this->bin->removeBin();
  }

}
