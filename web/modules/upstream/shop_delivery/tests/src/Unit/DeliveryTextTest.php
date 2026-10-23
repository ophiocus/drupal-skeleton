<?php

declare(strict_types=1);

namespace Drupal\Tests\shop_delivery\Unit;

use Drupal\shop_delivery\DeliveryText;
use Drupal\Tests\UnitTestCase;

/**
 * Phone numbers as the courier's links need them.
 *
 * @coversDefaultClass \Drupal\shop_delivery\DeliveryText
 * @group shop_delivery
 */
final class DeliveryTextTest extends UnitTestCase {

  /**
   * A Colombian mobile gets the country code; other numbers stay or go.
   *
   * @covers ::whatsapp
   */
  public function testWhatsapp(): void {
    $this->assertSame('573001234567', DeliveryText::whatsapp('300 123 4567'));
    $this->assertSame('573001234567', DeliveryText::whatsapp('+57 300-123-4567'));
    $this->assertSame('', DeliveryText::whatsapp('601 555 1234'), 'A Bogotá landline has no WhatsApp.');
    $this->assertSame('', DeliveryText::whatsapp(''));
  }

  /**
   * Digits, and the plus when it leads.
   *
   * @covers ::tel
   */
  public function testTel(): void {
    $this->assertSame('3001234567', DeliveryText::tel('300 123 4567'));
    $this->assertSame('+573001234567', DeliveryText::tel('+57 (300) 123-4567'));
  }

}
