<?php

declare(strict_types=1);

namespace Drupal\Tests\ad_inventory\Kernel;

use Drupal\ad_inventory\AdResolver;
use Drupal\ad_inventory\Coverage\Coverage;
use Drupal\ad_inventory\Coverage\CoverageSummaryInterface;
use Drupal\ad_inventory\Coverage\CoverageCalculator;
use Drupal\ad_inventory\Coverage\CoverageStore;
use Drupal\ad_inventory\Entity\AdBooking;
use Drupal\ad_inventory\Entity\AdCreative;
use Drupal\ad_inventory\Entity\AdSlot;
use Drupal\ad_inventory\Form\AdBookingForm;
use Drupal\ad_inventory\Scope\AdScope;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Form\FormState;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\ad_inventory\Traits\AdFixturesTrait;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * A booking locks the pages still free when it is bought.
 *
 * A wider booking bought first keeps a narrower one from being bought; a
 * wider booking bought after a narrower one covers only what is left, and the
 * form shows that before staff confirm.
 */
#[Group('ad_inventory')]
#[RunTestsInSeparateProcesses]
final class CoverageTest extends KernelTestBase {

  use AdFixturesTrait;
  use MediaTypeCreationTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'file',
    'image',
    'media',
    'block',
    'adstxt',
    'ad_inventory',
  ];

  /**
   * The space under test.
   */
  private AdSlot $slot;

  /**
   * An approved creative for the space.
   */
  private AdCreative $creative;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('file');
    $this->installEntitySchema('media');
    $this->installEntitySchema('ad_creative');
    $this->installEntitySchema('ad_booking');
    $this->installEntitySchema('ad_override');
    $this->installSchema('file', ['file_usage']);
    $this->installSchema('ad_inventory', ['ad_inventory_count']);
    $this->installConfig(['system', 'image', 'media', 'ad_inventory']);
    $this->createMediaType('image', ['id' => 'image']);
    $this->slot = $this->createSlot('test_end');
    $this->creative = $this->createCreative(['leaderboard', 'rectangle'], ['state' => AdCreative::APPROVED]);
  }

  /**
   * The calculator.
   */
  private function calculator(): CoverageCalculator {
    return $this->container->get('ad_inventory.coverage');
  }

  /**
   * The store.
   */
  private function store(): CoverageStore {
    return $this->container->get('ad_inventory.coverage_store');
  }

  /**
   * A day relative to the site's today.
   */
  private function day(int $offset): string {
    $today = $this->container->get('ad_inventory.site_day')->today();
    return (new \DateTimeImmutable($today))->modify(sprintf('%+d days', $offset))->format('Y-m-d');
  }

  /**
   * Books and approves through the calculator, as the form does.
   */
  private function buy(string $scope, int $from = 0): AdBooking {
    $booking = $this->createBooking($this->creative, ['test_end'], $this->day($from), $this->day($from + 6), AdBooking::DRAFT, $scope);
    $result = $this->calculator()->calculate($booking);
    $this->assertFalse($result->refused(), 'The booking has free pages.');
    $booking->set('state', AdBooking::APPROVED)->save();
    $this->store()->set($booking, $result->coverage);
    return $booking;
  }

  /**
   * An unsaved booking, to ask what it would take.
   */
  private function probe(string $scope, int $from = 0): AdBooking {
    return AdBooking::create([
      'label' => 'Probe',
      'creative' => $this->creative->id(),
      'slots' => ['ids' => ['test_end']],
      'scope' => $scope,
      'start_day' => $this->day($from),
      'end_day' => $this->day($from + 6),
      'state' => AdBooking::APPROVED,
    ]);
  }

  /**
   * The booking that fills the space today on a page with these keys.
   */
  private function winner(array $keys): ?AdBooking {
    $resolver = new AdResolver(
      $this->container->get('entity_type.manager'),
      $this->container->get('ad_inventory.site_day'),
      $this->container->get('ad_inventory.overrides'),
      new CoverageStore($this->container->get('keyvalue')),
    );
    $fill = $resolver->resolve($this->slot, new AdScope(NULL, $keys, new CacheableMetadata()), new AnonymousUserSession(), new CacheableMetadata());
    return $fill?->booking;
  }

  /**
   * A wider booking bought first: no narrower booking under it can be bought.
   */
  public function testBlanketFirstRefusesTheSecondPurchase(): void {
    $blanket = $this->buy('');

    $result = $this->calculator()->calculate($this->probe('city:medellin'));
    $this->assertTrue($result->refused());
    $this->assertSame([$blanket->id()], array_map(static fn (AdBooking $b) => $b->id(), $result->holders));

    // The same scope twice is refused as before.
    $this->assertTrue($this->calculator()->calculate($this->probe(''))->refused());
    // Another week, or another space, is free.
    $this->assertFalse($this->calculator()->calculate($this->probe('city:medellin', 7))->refused());
  }

  /**
   * A wider booking bought after a narrower one covers only what is left.
   */
  public function testBlanketAfterCoversWhatIsAvailable(): void {
    $theatre = $this->buy('city:medellin');

    $result = $this->calculator()->calculate($this->probe(''));
    $this->assertFalse($result->refused());
    $this->assertSame(['city:medellin'], $result->coverage->exclude);
    $this->assertSame($theatre->id(), $result->taken[0]['booking']->id());
    $blanket = $this->buy('');

    // Each keeps its own pages.
    $this->assertSame($theatre->id(), $this->winner(['city:medellin'])?->id());
    $this->assertSame($blanket->id(), $this->winner(['city:bogota'])?->id());
    $this->assertSame($blanket->id(), $this->winner([])?->id());
  }

  /**
   * Pages the blanket left out stay out of it, even when they free up.
   */
  public function testLockedPagesStayAsBought(): void {
    $theatre = $this->buy('city:medellin');
    $blanket = $this->buy('');
    $theatre->stop('Cancelled')->save();

    // The blanket does not grow into the freed pages ...
    $this->assertNull($this->winner(['city:medellin']));
    $this->assertSame($blanket->id(), $this->winner(['city:bogota'])?->id());
    // ... so they can be bought again.
    $result = $this->calculator()->calculate($this->probe('city:medellin'));
    $this->assertFalse($result->refused());
    $this->assertTrue($result->coverage->isWhole());
  }

  /**
   * A booking under a blanket's left-out keys takes only those.
   */
  public function testInsidePartialBlanket(): void {
    $theatre = $this->buy('city:medellin');
    $blanket = $this->buy('');
    // While the theatre holds its pages, nothing of family:3 is left.
    $this->assertTrue($this->calculator()->calculate($this->probe('family:3'))->refused());
    $theatre->stop('Cancelled')->save();
    // Unknown keys may share pages: the careful reading.
    $result = $this->calculator()->calculate($this->probe('family:3'));
    $this->assertFalse($result->refused());
    $this->assertSame(['city:medellin'], $result->coverage->only);
    $this->assertSame($blanket->id(), $result->kept[0]['booking']->id());
  }

  /**
   * Bookings that are not exclusive lock nothing.
   */
  public function testNonExclusiveLocksNothing(): void {
    $open = $this->createBooking($this->creative, ['test_end'], $this->day(0), $this->day(6), AdBooking::APPROVED, '');
    $open->set('exclusive', FALSE)->save();
    $probe = $this->probe('city:medellin');
    $probe->set('exclusive', FALSE);
    $this->assertTrue($this->calculator()->calculate($probe)->coverage->isWhole());
  }

  /**
   * The form shows the calculated pages and saves only after confirmation.
   */
  public function testFormAsksToConfirmBeforeLocking(): void {
    $this->container->get('router.builder')->rebuild();
    $this->setUpCurrentUser([], ['manage ad bookings']);
    $theatre = $this->buy('city:medellin');

    $values = [
      'label' => 'La Ruta',
      'creative' => $this->creative->label() . ' (' . $this->creative->id() . ')',
      'slots' => ['test_end' => 'test_end'],
      'scope' => '',
      'start_day' => $this->day(0),
      'end_day' => $this->day(6),
      'exclusive' => 1,
      'state' => AdBooking::APPROVED,
    ];
    [, $errors] = $this->submit($values);
    $this->assertArrayHasKey('coverage_confirm', $errors);
    $this->assertStringContainsString('held by Test booking', (string) $errors['coverage_confirm']);
    $this->assertCount(1, $this->container->get('entity_type.manager')->getStorage('ad_booking')->loadMultiple());

    // Ticked, but on a list it never saw: shown again.
    [, $errors] = $this->submit($values + ['coverage_confirm' => 1]);
    $this->assertArrayHasKey('coverage_confirm', $errors);

    $fingerprint = $this->calculator()->calculate($this->probe(''))->fingerprint();
    [, $errors] = $this->submit($values + ['coverage_confirm' => 1, 'coverage_seen' => $fingerprint]);
    $this->assertSame([], $errors);
    $saved = $this->container->get('entity_type.manager')->getStorage('ad_booking')->loadByProperties(['label' => 'La Ruta']);
    $saved = reset($saved);
    $this->assertInstanceOf(AdBooking::class, $saved);
    $this->assertSame(['city:medellin'], $this->store()->get($saved)?->exclude);
    $this->assertSame($theatre->id(), $this->winner(['city:medellin'])?->id());
  }

  /**
   * A site's summary lines make staff confirm even a whole coverage.
   */
  public function testSummaryLinesAreConfirmed(): void {
    $this->container->get('router.builder')->rebuild();
    $this->calculator()->addSummary(new class() implements CoverageSummaryInterface {

      /**
       * {@inheritdoc}
       */
      public function summary(AdBooking $booking, Coverage $coverage): array {
        return ['test_end: 12 pages locked.'];
      }

    });
    $values = [
      'label' => 'Solo',
      'creative' => $this->creative->label() . ' (' . $this->creative->id() . ')',
      'slots' => ['test_end' => 'test_end'],
      'scope' => '',
      'start_day' => $this->day(0),
      'end_day' => $this->day(6),
      'exclusive' => 1,
      'state' => AdBooking::APPROVED,
    ];
    [, $errors] = $this->submit($values);
    $this->assertStringContainsString('test_end: 12 pages locked.', (string) ($errors['coverage_confirm'] ?? ''));

    $probe = $this->probe('');
    $result = $this->calculator()->calculate($probe);
    $fingerprint = $result->fingerprint($this->calculator()->summary($probe, $result->coverage));
    [, $errors] = $this->submit($values + ['coverage_confirm' => 1, 'coverage_seen' => $fingerprint]);
    $this->assertSame([], array_map('strval', $errors));
  }

  /**
   * Submits the add form.
   *
   * @return array{0: \Drupal\Core\Form\FormStateInterface, 1: array<string, mixed>}
   *   The form state and its errors.
   */
  private function submit(array $values): array {
    $entity_type_manager = $this->container->get('entity_type.manager');
    $form = $entity_type_manager->getFormObject('ad_booking', 'default');
    $this->assertInstanceOf(AdBookingForm::class, $form);
    $form->setEntity($entity_type_manager->getStorage('ad_booking')->create());
    $form_state = (new FormState())->setValues($values + ['op' => 'Save']);
    $this->container->get('form_builder')->submitForm($form, $form_state);
    return [$form_state, $form_state->getErrors()];
  }

}
