<?php

declare(strict_types=1);

namespace Drupal\shop_delivery;

/**
 * Small text helpers the delivery pages share.
 */
final class DeliveryText {

  /**
   * A phone number as a wa.me link's number, or '' when it is not one.
   *
   * Colombian mobiles are ten digits starting with 3 and get the country
   * code; a number already carrying one is kept as written.
   */
  public static function whatsapp(string $phone): string {
    $digits = preg_replace('/\D+/', '', $phone) ?? '';
    if (strlen($digits) === 10 && $digits[0] === '3') {
      return '57' . $digits;
    }
    return strlen($digits) >= 11 ? $digits : '';
  }

  /**
   * A phone number for a tel: link: digits and a leading plus only.
   */
  public static function tel(string $phone): string {
    $phone = trim($phone);
    $digits = preg_replace('/\D+/', '', $phone) ?? '';
    return ($phone !== '' && $phone[0] === '+' ? '+' : '') . $digits;
  }

}
