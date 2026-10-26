<?php

declare(strict_types=1);

namespace Drupal\Tests\managing_admins\Kernel;

use Drupal\Core\Test\AssertMailTrait;
use Drupal\KernelTests\KernelTestBase;
use Drupal\managing_admins\ManagingAdmins;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Listed accounts exist with the administrator role; nothing else changes.
 */
#[Group('managing_admins')]
#[RunTestsInSeparateProcesses]
final class ManagingAdminsTest extends KernelTestBase {

  use AssertMailTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'managing_admins'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installSchema('user', ['users_data']);
    Role::create(['id' => 'administrator', 'label' => 'Administrator', 'is_admin' => TRUE])->save();
    Role::create(['id' => 'editor', 'label' => 'Editor'])->save();
    // The anonymous user, as every site has it.
    User::create(['uid' => 0, 'name' => '', 'status' => 0])->save();
  }

  /**
   * The converger.
   */
  private function admins(): ManagingAdmins {
    return $this->container->get('managing_admins.converger');
  }

  /**
   * The one account with an e-mail, reloaded.
   */
  private function byMail(string $mail): UserInterface {
    $found = $this->container->get('entity_type.manager')->getStorage('user')->loadByProperties(['mail' => $mail]);
    $this->assertCount(1, $found, "one account for $mail");
    $account = reset($found);
    $this->assertInstanceOf(UserInterface::class, $account);
    return $account;
  }

  /**
   * How many accounts exist, anonymous included.
   */
  private function accounts(): int {
    return (int) $this->container->get('entity_type.manager')->getStorage('user')
      ->getQuery()->accessCheck(FALSE)->count()->execute();
  }

  /**
   * The host's list parses to names keyed by lowercase e-mail.
   */
  public function testParse(): void {
    $this->assertSame(
      ['ana@example.com' => 'Ana', 'bo@example.com' => 'Bo Ek'],
      $this->admins()->parse(' Ana:Ana@Example.com , Bo Ek:bo@example.com,'),
    );
    $this->assertSame([], $this->admins()->parse(''));
    foreach (['ana@example.com', ':ana@example.com', 'Ana:not-a-mail', 'Ana:a@example.com,Bo:A@example.com'] as $bad) {
      try {
        $this->admins()->parse($bad);
        $this->fail("parsed: $bad");
      }
      catch (\InvalidArgumentException) {
        $this->addToAssertionCount(1);
      }
    }
  }

  /**
   * Missing accounts are created: active, administrator, no password, no mail.
   */
  public function testCreates(): void {
    $admins = $this->admins()->parse('Ana:ana@example.com,Bo:bo@example.com');
    $this->assertSame(['Ana' => 'created', 'Bo' => 'created'], $this->admins()->converge($admins));

    foreach (['ana@example.com' => 'Ana', 'bo@example.com' => 'Bo'] as $mail => $name) {
      $account = $this->byMail($mail);
      $this->assertSame($name, $account->getAccountName());
      $this->assertTrue($account->isActive());
      $this->assertTrue($account->hasRole('administrator'));
      $this->assertEmpty($account->getPassword(), 'no password is set');
    }
    $this->assertCount(0, $this->getMails(), 'no mail is sent');

    // A second run changes nothing.
    $this->assertSame(['Ana' => 'unchanged', 'Bo' => 'unchanged'], $this->admins()->converge($admins));
    $this->assertSame(3, $this->accounts());
  }

  /**
   * An existing account is found by e-mail in any case, and only gains a role.
   */
  public function testAdoptsExisting(): void {
    $existing = User::create([
      'name' => 'ana-own-name',
      'mail' => 'ANA@example.com',
      'status' => 0,
      'roles' => ['editor'],
    ]);
    $existing->setPassword('kept');
    $existing->save();
    $hash = $existing->getPassword();
    $other = User::create(['name' => 'someone', 'mail' => 'someone@example.com', 'status' => 1]);
    $other->save();

    $result = $this->admins()->converge($this->admins()->parse('Ana:ana@example.com'));
    $this->assertSame(['Ana' => 'role added, blocked'], $result);

    $account = User::load($existing->id());
    $this->assertSame('ana-own-name', $account->getAccountName(), 'the name is the owner\'s');
    $this->assertTrue($account->hasRole('editor'), 'other roles are kept');
    $this->assertTrue($account->hasRole('administrator'));
    $this->assertTrue($account->isBlocked(), 'a blocked account stays blocked');
    $this->assertSame($hash, $account->getPassword(), 'the password is the owner\'s');

    $this->assertSame([], User::load($other->id())->getRoles(TRUE), 'another account is not touched');
    $this->assertSame(3, $this->accounts());
  }

  /**
   * A name held by another account: the new one is named by its e-mail.
   */
  public function testNameHeldByAnother(): void {
    User::create(['name' => 'Ana', 'mail' => 'other@example.com', 'status' => 1])->save();
    $result = $this->admins()->converge($this->admins()->parse('Ana:ana@example.com'));
    $this->assertSame(['Ana' => 'created, named by its e-mail'], $result);
    $this->assertSame('ana@example.com', $this->byMail('ana@example.com')->getAccountName());
    $this->assertSame([], $this->byMail('other@example.com')->getRoles(TRUE));
  }

  /**
   * A check counts what would change and writes nothing.
   */
  public function testCheckWritesNothing(): void {
    User::create(['name' => 'bo', 'mail' => 'bo@example.com', 'status' => 1])->save();
    $result = $this->admins()->converge($this->admins()->parse('Ana:ana@example.com,Bo:bo@example.com'), TRUE);
    $this->assertSame(['Ana' => 'created', 'Bo' => 'role added'], $result);
    $this->assertSame(2, $this->accounts());
    $this->container->get('entity_type.manager')->getStorage('user')->resetCache();
    $this->assertFalse($this->byMail('bo@example.com')->hasRole('administrator'));
  }

  /**
   * Two accounts on one listed e-mail, or no administrator role: it stops.
   */
  public function testRefusesToGuess(): void {
    User::create(['name' => 'a1', 'mail' => 'ana@example.com'])->save();
    User::create(['name' => 'a2', 'mail' => 'Ana@example.com'])->save();
    try {
      $this->admins()->converge($this->admins()->parse('Ana:ana@example.com'));
      $this->fail('converged onto one of two accounts');
    }
    catch (\RuntimeException) {
      $this->addToAssertionCount(1);
    }

    Role::load('administrator')->delete();
    $this->expectException(\RuntimeException::class);
    $this->admins()->converge($this->admins()->parse('Bo:bo@example.com'));
  }

}
