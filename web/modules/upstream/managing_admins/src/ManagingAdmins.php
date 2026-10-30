<?php

declare(strict_types=1);

namespace Drupal\managing_admins;

use Drupal\Component\Utility\EmailValidatorInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\user\UserDataInterface;
use Drupal\user\UserInterface;

/**
 * Converges the managing admins onto the site's accounts.
 *
 * Every account listed exists, found by its e-mail, with the administrator
 * role. A missing one is created active, with no password: its owner gets in
 * through a password reset to that mailbox. The role is only ever given to an
 * account this code created (marked in user data), or kept on one that holds
 * it already: on a site where visitors can register, anyone could open an
 * account under a listed e-mail first, and adopting it would hand them the
 * site. Such an account is reported, never promoted. Name, password, status
 * and other roles stay the owner's. No other account is ever read for change,
 * and no mail is sent.
 */
final class ManagingAdmins {

  /**
   * The role every managing admin holds.
   */
  public const ROLE = 'administrator';

  /**
   * The user data key that marks an account this code created or adopted.
   */
  public const MARK = 'managed';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EmailValidatorInterface $emailValidator,
    private readonly Connection $database,
    private readonly LanguageManagerInterface $languageManager,
    private readonly UserDataInterface $userData,
  ) {}

  /**
   * Reads the host's list: "Name:mail" entries separated by commas.
   *
   * @param string $list
   *   The value of MANAGING_ADMINS: "Ana:ana@example.com,Bo:bo@example.com".
   *
   * @return array<string, string>
   *   Account names, keyed by e-mail (lowercase).
   *
   * @throws \InvalidArgumentException
   *   When an entry has no name, an invalid e-mail, or repeats an e-mail.
   */
  public function parse(string $list): array {
    $admins = [];
    foreach (array_filter(array_map('trim', explode(',', $list)), 'strlen') as $entry) {
      $at = strrpos($entry, ':');
      $name = $at === FALSE ? '' : trim(substr($entry, 0, $at));
      $mail = $at === FALSE ? '' : strtolower(trim(substr($entry, $at + 1)));
      if ($name === '' || !$this->emailValidator->isValid($mail)) {
        throw new \InvalidArgumentException('MANAGING_ADMINS: an entry is not "Name:mail" (entry ' . (count($admins) + 1) . ')');
      }
      if (isset($admins[$mail])) {
        throw new \InvalidArgumentException("MANAGING_ADMINS: $name repeats an e-mail already listed");
      }
      $admins[$mail] = $name;
    }
    return $admins;
  }

  /**
   * Makes every listed account exist with the administrator role.
   *
   * @param array<string, string> $admins
   *   Account names keyed by e-mail, as parse() returns them.
   * @param bool $check
   *   Report what would change, and write nothing.
   *
   * @return array<string, string>
   *   What happened (or would) to each account, keyed by its listed name:
   *   "created", "created, named by its e-mail" (another account holds the
   *   name), "role added" (an account this code created had lost it),
   *   "unchanged", or "not promoted: an account it did not create" (promote
   *   it by hand, after checking who owns it); ", blocked" is appended for an
   *   existing account that is blocked, which is left so. No e-mail address
   *   appears in it: it is printed in deploy logs.
   *
   * @throws \RuntimeException
   *   When the site has no administrator role, or two accounts share a listed
   *   e-mail (which one is meant is not this code's guess).
   */
  public function converge(array $admins, bool $check = FALSE): array {
    if (!$this->entityTypeManager->getStorage('user_role')->load(self::ROLE)) {
      throw new \RuntimeException('managing admins: this site has no "' . self::ROLE . '" role');
    }
    $storage = $this->entityTypeManager->getStorage('user');
    $result = [];
    foreach ($admins as $mail => $name) {
      $found = $this->byMail($mail);
      if (count($found) > 1) {
        throw new \RuntimeException("managing admins: several accounts share $name's e-mail; resolve that by hand");
      }
      $account = reset($found);
      if ($account instanceof UserInterface) {
        $uid = (int) $account->id();
        $managed = (bool) $this->userData->get('managing_admins', $uid, self::MARK);
        $did = 'unchanged';
        if ($account->hasRole(self::ROLE)) {
          // Already an administrator: it is the managing admin's account.
          if (!$managed && !$check) {
            $this->userData->set('managing_admins', $uid, self::MARK, 1);
          }
        }
        elseif (!$managed) {
          $did = 'not promoted: an account it did not create';
        }
        else {
          $account->addRole(self::ROLE);
          if (!$check) {
            $account->save();
          }
          $did = 'role added';
        }
        $result[$name] = $did . ($account->isBlocked() ? ', blocked' : '');
        continue;
      }

      // A name another account holds is that account's: the new one takes its
      // e-mail as its name instead, which no other account can hold.
      $taken = (bool) $storage->getQuery()->accessCheck(FALSE)->condition('name', $name)->range(0, 1)->execute();
      $account = $storage->create([
        'name' => $taken ? $mail : $name,
        'mail' => $mail,
        'init' => $mail,
        'status' => 1,
        'roles' => [self::ROLE],
        'langcode' => $this->languageManager->getDefaultLanguage()->getId(),
        'preferred_langcode' => $this->languageManager->getDefaultLanguage()->getId(),
      ]);
      if (!$check) {
        $account->save();
        $this->userData->set('managing_admins', (int) $account->id(), self::MARK, 1);
      }
      $result[$name] = $taken ? 'created, named by its e-mail' : 'created';
    }
    return $result;
  }

  /**
   * The accounts whose e-mail is this one, in any letter case.
   *
   * @return \Drupal\user\UserInterface[]
   *   The accounts, anonymous never among them.
   */
  private function byMail(string $mail): array {
    $storage = $this->entityTypeManager->getStorage('user');
    $ids = $storage->getQuery()->accessCheck(FALSE)
      ->condition('uid', 0, '>')
      ->condition('mail', $this->database->escapeLike($mail), 'LIKE')
      ->execute();
    return array_values(array_filter(
      $storage->loadMultiple($ids),
      static fn (UserInterface $account): bool => strcasecmp((string) $account->getEmail(), $mail) === 0,
    ));
  }

}
