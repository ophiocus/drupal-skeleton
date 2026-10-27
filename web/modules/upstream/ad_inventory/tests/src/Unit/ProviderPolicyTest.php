<?php

declare(strict_types=1);

namespace Drupal\Tests\ad_inventory\Unit;

use Drupal\ad_inventory\NetworkGate;
use Drupal\ad_inventory\Plugin\AdProvider\AdSense;
use Drupal\ad_inventory\Plugin\AdProvider\PastedSnippet;
use Drupal\ad_inventory\ProviderPolicy;
use Drupal\Core\Site\Settings;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Provider exclusions, labels, ratios and the AdSense plugin's output.
 */
#[Group('ad_inventory')]
#[RunTestsInSeparateProcesses]
final class ProviderPolicyTest extends UnitTestCase {

  /**
   * A pop-under network beside AdSense is a conflict; a banner one is not.
   */
  public function testPopUnderBesideAdSense(): void {
    new Settings([]);
    $gate = new NetworkGate();
    $adsense = new AdSense(['provider_id' => 'google'], 'adsense', [], $gate);
    $popunder = new PastedSnippet(['provider_id' => 'pu', 'settings' => ['kinds' => ['banner', 'pop_under']]], 'snippet', [], $gate);
    $banner = new PastedSnippet(['provider_id' => 'bn', 'settings' => ['kinds' => ['banner']]], 'snippet', [], $gate);

    $providers = [
      ProviderPolicy::describe('google', 'AdSense', $adsense),
      ProviderPolicy::describe('bn', 'Banners', $banner),
    ];
    $this->assertSame([], ProviderPolicy::conflicts($providers));

    $providers[] = ProviderPolicy::describe('pu', 'Popunder', $popunder);
    $conflicts = ProviderPolicy::conflicts($providers);
    $this->assertCount(1, $conflicts);
    $this->assertStringContainsString('AdSense forbids pop_under', $conflicts[0]);
    $this->assertSame([], ProviderPolicy::conflicts($providers, 'bn'), 'Only conflicts the named provider takes part in.');
    $this->assertCount(1, ProviderPolicy::conflicts($providers, 'pu'));
  }

  /**
   * Google's two labels, in either language, any case.
   */
  public function testLabels(): void {
    $this->assertTrue(ProviderPolicy::labelAllowed('Enlaces patrocinados', AdSense::LABELS));
    $this->assertTrue(ProviderPolicy::labelAllowed(' sponsored links ', AdSense::LABELS));
    $this->assertFalse(ProviderPolicy::labelAllowed('Publicidad', AdSense::LABELS));
    $this->assertTrue(ProviderPolicy::labelAllowed('Anything', []));
  }

  /**
   * A rendition's ratio must match its format within 1%.
   */
  public function testRatio(): void {
    $this->assertTrue(ProviderPolicy::ratioMatches(1456, 180, 728, 90));
    $this->assertTrue(ProviderPolicy::ratioMatches(1460, 180, 728, 90));
    $this->assertFalse(ProviderPolicy::ratioMatches(1480, 180, 728, 90));
    $this->assertFalse(ProviderPolicy::ratioMatches(0, 180, 728, 90));
  }

  /**
   * AdSense: publisher id from the environment, Google's ads.txt line.
   */
  public function testAdSense(): void {
    new Settings(['ad_inventory_accounts' => ['google' => 'ca-pub-1234567890123456']]);
    $plugin = new AdSense(['provider_id' => 'google', 'settings' => ['verification' => TRUE]], 'adsense', [], new NetworkGate());
    $this->assertSame('pub-1234567890123456', $plugin->account());
    $this->assertSame(['google.com, pub-1234567890123456, DIRECT, f08c47fec0942fa0'], $plugin->adsTxtLines());
    $this->assertSame('', $plugin->unitMarkup(['slot' => 'not a number']));
    $this->assertSame('<ins class="adsbygoogle" style="display:inline-block" data-ad-client="ca-pub-1234567890123456" data-ad-slot="42"></ins>', $plugin->unitMarkup([
      'slot' => '42',
      'channel' => 'bad channel!',
    ]));
    $this->assertSame(['name' => 'google-adsense-account', 'content' => 'ca-pub-1234567890123456'], $plugin->verificationTag()['#attributes']);
    $this->assertSame('pub-1234567890123456', AdSense::publisher('accounts/pub-1234567890123456'));
    $this->assertNull(AdSense::publisher('ca-1234'));

    new Settings([]);
    $this->assertNull($plugin->account());
    $this->assertSame([], $plugin->adsTxtLines());
    $this->assertSame('', $plugin->loaderUrl());
  }

  /**
   * The network gate is closed unless the environment opens it.
   */
  public function testGate(): void {
    new Settings([]);
    putenv(NetworkGate::ENV);
    $gate = new NetworkGate();
    $this->assertFalse($gate->enabled());
    putenv(NetworkGate::ENV . '=1');
    $this->assertTrue($gate->enabled());
    new Settings(['ad_inventory_network' => FALSE]);
    $this->assertFalse($gate->enabled(), 'settings.php wins over the environment.');
    putenv(NetworkGate::ENV);
  }

}
