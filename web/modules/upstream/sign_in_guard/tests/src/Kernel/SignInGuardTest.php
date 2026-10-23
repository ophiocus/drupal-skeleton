<?php

declare(strict_types=1);

namespace Drupal\Tests\sign_in_guard\Kernel;

use Drupal\captcha\Entity\CaptchaPoint;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\encrypt\Entity\EncryptionProfile;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The guard is off without the host's keys, and on with each of them.
 */
#[Group('sign_in_guard')]
#[RunTestsInSeparateProcesses]
final class SignInGuardTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'key',
    'encrypt',
    'real_aes',
    'tfa',
    'captcha',
    'turnstile',
    'sign_in_guard',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installConfig(['captcha', 'tfa', 'turnstile', 'sign_in_guard']);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    putenv('TFA_ENCRYPTION_KEY');
    putenv('TURNSTILE_KEYS');
    parent::tearDown();
  }

  /**
   * The config as the site reads it, overrides applied.
   *
   * KernelTestBase::config() returns editable config, which never carries
   * overrides.
   */
  private function read(string $name): ImmutableConfig {
    return $this->container->get('config.factory')->get($name);
  }

  /**
   * Without keys the contrib keeps its shipped defaults.
   */
  public function testOffWithoutKeys(): void {
    $this->assertFalse($this->read('tfa.settings')->get('enabled'));
    $this->assertSame([], $this->read('tfa.settings')->get('required_roles'));
    $this->assertFalse(CaptchaPoint::load('user_login_form')->status());
    $this->assertSame('', $this->read('turnstile.settings')->get('keys'));
  }

  /**
   * With the encryption key, two-factor sign-in is required for staff.
   */
  public function testTwoFactorWithKey(): void {
    putenv('TFA_ENCRYPTION_KEY=' . base64_encode(random_bytes(32)));
    $this->container->get('config.factory')->reset();

    $tfa = $this->read('tfa.settings');
    $this->assertTrue($tfa->get('enabled'));
    $this->assertSame(['administrator' => 'administrator', 'content_editor' => 'content_editor'], $tfa->get('required_roles'));
    $this->assertSame('tfa_totp', $tfa->get('default_validation_plugin'));
    $this->assertSame('sign_in_guard_tfa', $tfa->get('encryption'));

    // The profile really encrypts with the host's key.
    $profile = EncryptionProfile::load('sign_in_guard_tfa');
    $this->assertSame([], $profile->validate());
    $encryption = $this->container->get('encryption');
    $secret = 'a TOTP seed';
    $sealed = $encryption->encrypt($secret, $profile);
    $this->assertNotSame($secret, $sealed);
    $this->assertSame($secret, $encryption->decrypt($sealed, $profile));

    // Turnstile stays off without its own keys.
    $this->assertFalse(CaptchaPoint::load('user_login_form')->status());
  }

  /**
   * With the widget's keys, Turnstile guards sign-in and password reset.
   */
  public function testTurnstileWithKeys(): void {
    putenv('TURNSTILE_KEYS=' . json_encode(['site_key' => 'site-key-x', 'secret_key' => 'secret-key-y']));
    $this->container->get('config.factory')->reset();
    $this->container->get('entity_type.manager')->getStorage('captcha_point')->resetCache();

    foreach (['user_login_form', 'user_pass'] as $form) {
      $point = CaptchaPoint::load($form);
      $this->assertTrue($point->status(), $form);
      $this->assertSame('turnstile/Turnstile', $point->getCaptchaType(), $form);
    }
    $this->assertFalse(CaptchaPoint::load('user_register_form')->status());
    $this->assertSame('sign_in_guard_turnstile', $this->read('turnstile.settings')->get('keys'));
    $keys = $this->container->get('key.repository')->getKey('sign_in_guard_turnstile')->getKeyValues();
    $this->assertSame('site-key-x', $keys['site_key']);
    $this->assertSame('secret-key-y', $keys['secret_key']);

    // Two-factor stays off without its own key.
    $this->assertFalse($this->read('tfa.settings')->get('enabled'));
  }

}
