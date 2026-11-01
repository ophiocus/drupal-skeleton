<?php

declare(strict_types=1);

namespace Drupal\sign_in_guard\Config;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryOverrideInterface;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Site\Settings;

/**
 * Turns the guard on from the host environment, never from config/sync.
 *
 * Two-factor sign-in is required once TFA_ENCRYPTION_KEY is set; Turnstile
 * guards the sign-in and password-reset forms once TURNSTILE_KEYS is set, and
 * becomes the challenge of the public forms a site already protects. With
 * neither (a local copy, CI, a fresh build) the contrib keeps its shipped
 * defaults, which change nothing, so no environment can lock itself out by
 * importing another's config.
 */
final class SignInGuardOverrides implements ConfigFactoryOverrideInterface {

  /**
   * The roles that must set up two-factor sign-in.
   */
  public const TFA_ROLES = ['administrator', 'content_editor'];

  /**
   * The forms Turnstile guards, switched on.
   */
  public const TURNSTILE_FORMS = ['user_login_form', 'user_pass'];

  /**
   * Public forms whose challenge becomes Turnstile; on or off stays the site's.
   *
   * $settings['sign_in_guard_turnstile_forms'] adds a site's own form ids.
   */
  public const TURNSTILE_CHALLENGE_FORMS = [
    'user_register_form',
    'contact_message_feedback_form',
    'contact_message_personal_form',
  ];

  /**
   * {@inheritdoc}
   */
  public function loadOverrides($names): array {
    $overrides = [];
    if (in_array('tfa.settings', $names, TRUE) && self::tfaOn()) {
      $overrides['tfa.settings'] = [
        'enabled' => TRUE,
        'required_roles' => array_combine(self::TFA_ROLES, self::TFA_ROLES),
        'allowed_validation_plugins' => [
          'tfa_totp' => 'tfa_totp',
          'tfa_recovery_code' => 'tfa_recovery_code',
        ],
        'default_validation_plugin' => 'tfa_totp',
        // TOTP keeps the plugin's defaults: the authenticator app labels the
        // account with the site's name.
        'validation_plugin_settings' => [
          'tfa_recovery_code' => ['recovery_codes_amount' => 10],
        ],
        'encryption' => 'sign_in_guard_tfa',
        // Three sign-ins to set it up, then it is required; each sign-in
        // without it goes straight to the setup page.
        'validation_skip' => 3,
        'users_without_tfa_redirect' => TRUE,
      ];
    }
    if (self::turnstileOn()) {
      if (in_array('turnstile.settings', $names, TRUE)) {
        $overrides['turnstile.settings'] = ['keys' => 'sign_in_guard_turnstile'];
      }
      foreach (self::TURNSTILE_FORMS as $form) {
        $name = "captcha.captcha_point.$form";
        if (in_array($name, $names, TRUE)) {
          $overrides[$name] = ['status' => TRUE, 'captchaType' => 'turnstile/Turnstile'];
        }
      }
      foreach (self::challengeForms() as $form) {
        $name = "captcha.captcha_point.$form";
        if (in_array($name, $names, TRUE) && !isset($overrides[$name])) {
          $overrides[$name] = ['captchaType' => 'turnstile/Turnstile'];
        }
      }
    }
    return $overrides;
  }

  /**
   * The public forms whose challenge becomes Turnstile, the site's own added.
   *
   * @return string[]
   *   Form ids.
   */
  public static function challengeForms(): array {
    $own = array_filter((array) Settings::get('sign_in_guard_turnstile_forms', []), 'is_string');
    return array_values(array_unique(array_merge(self::TURNSTILE_CHALLENGE_FORMS, $own)));
  }

  /**
   * Whether the host gives the two-factor encryption key.
   */
  public static function tfaOn(): bool {
    return (string) getenv('TFA_ENCRYPTION_KEY') !== '';
  }

  /**
   * Whether the host gives the Turnstile widget's keys.
   */
  public static function turnstileOn(): bool {
    return (string) getenv('TURNSTILE_KEYS') !== '';
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheSuffix(): string {
    return 'sign_in_guard:' . (int) self::tfaOn() . (int) self::turnstileOn() . ':' . md5(implode(',', self::challengeForms()));
  }

  /**
   * {@inheritdoc}
   */
  public function createConfigObject($name, $collection = StorageInterface::DEFAULT_COLLECTION) {
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheableMetadata($name): CacheableMetadata {
    return new CacheableMetadata();
  }

}
