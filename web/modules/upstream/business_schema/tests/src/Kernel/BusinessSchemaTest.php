<?php

declare(strict_types=1);

namespace Drupal\Tests\business_schema\Kernel;

use Drupal\business_schema\BusinessFacts;
use Drupal\business_schema\JsonLd;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\HttpKernelUiHelperTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The business's JSON-LD and the block that shows the same facts.
 */
#[Group('business_schema')]
#[RunTestsInSeparateProcesses]
final class BusinessSchemaTest extends KernelTestBase {

  use HttpKernelUiHelperTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'business_schema'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installConfig(['system', 'user', 'business_schema']);
    $this->container->get('theme_installer')->install(['stark']);
    $this->config('system.theme')->set('default', 'stark')->save();
    $this->config('system.site')->set('name', 'Example Bakery')->save();
  }

  /**
   * Saves settings, merged over the module's defaults.
   */
  private function settings(array $values): void {
    $config = $this->config('business_schema.settings');
    foreach ($values as $key => $value) {
      $config->set($key, $value);
    }
    $config->save();
  }

  /**
   * Every fact the settings can hold, filled.
   */
  private function everything(): void {
    $this->settings([
      'types' => ['PetStore', 'VeterinaryCare'],
      'alternate_name' => 'Example',
      'legal_name' => 'Example S.A.S.',
      'description' => 'A pet shop with a clinic.',
      'telephone' => '+57 301 000 0000',
      'email' => 'hola@example.com',
      'tax_id' => '900000000-1',
      'price_range' => '$$',
      'same_as' => ['https://www.instagram.com/example/'],
      'images' => ['core/misc/druplicon.png'],
      'address' => [
        'street_address' => 'Calle 1 # 2-3',
        'address_locality' => 'Zipaquirá',
        'address_region' => 'Cundinamarca',
        'postal_code' => '250251',
        'address_country' => 'CO',
      ],
      'geo' => ['latitude' => '5.0142367', 'longitude' => '-73.9930965'],
      'opening_hours' => [
        [
          'days' => ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'],
          'opens' => '09:00',
          'closes' => '18:00',
        ],
        ['days' => ['Saturday'], 'opens' => '09:00', 'closes' => '13:00'],
      ],
      'special_hours' => [
        [
          'valid_from' => '2026-12-25',
          'valid_through' => '2026-12-25',
          'closed' => TRUE,
          'opens' => '',
          'closes' => '',
        ],
        [
          'valid_from' => '2026-12-24',
          'valid_through' => '2026-12-24',
          'closed' => FALSE,
          'opens' => '09:00',
          'closes' => '12:00',
        ],
      ],
      'serves_cuisine' => ['Panadería'],
      'menu' => '/tienda',
      'area_served' => ['Zipaquirá'],
      'departments' => [
        [
          'types' => ['VeterinaryCare'],
          'name' => 'Example Veterinaria',
          'url' => '/veterinaria',
          'telephone' => '',
        ],
      ],
      'contact_points' => [
        ['contact_type' => 'wholesale', 'telephone' => '+57 301 000 0001', 'email' => ''],
      ],
      'return_policy' => [
        'category' => 'MerchantReturnFiniteReturnWindow',
        'days' => 5,
        'country' => 'CO',
        'method' => 'ReturnInStore',
        'fees' => 'FreeReturn',
      ],
    ]);
  }

  /**
   * The document for the current settings.
   */
  private function document(): array {
    $facts = $this->container->get(BusinessFacts::class)->facts(new CacheableMetadata());
    return JsonLd::business($facts);
  }

  /**
   * Asserts that no value anywhere in a document is empty.
   */
  private function assertNoEmptyValues(array $data, string $path = ''): void {
    foreach ($data as $key => $value) {
      $this->assertNotContains($value, [NULL, '', []], "$path/$key is empty.");
      if (is_array($value)) {
        $this->assertNoEmptyValues($value, "$path/$key");
      }
    }
  }

  /**
   * With only a name, the site is an Organization and nothing more is said.
   */
  public function testOnlyFieldsWithValues(): void {
    // A theme that shows no logo has none to lend.
    $this->config('stark.settings')->set('logo.use_default', FALSE)->set('logo.path', '')->save();
    $document = $this->document();
    $this->assertSame('https://schema.org', $document['@context']);
    $this->assertCount(1, $document['@graph'], 'No address: no LocalBusiness.');
    $organization = $document['@graph'][0];
    $this->assertSame('Organization', $organization['@type']);
    $this->assertSame('Example Bakery', $organization['name']);
    $this->assertStringStartsWith('http', $organization['url']);
    $this->assertSame($organization['url'] . '#organization', $organization['@id']);
    foreach (['telephone', 'email', 'address', 'sameAs', 'logo', 'hasMerchantReturnPolicy', 'contactPoint', 'taxID'] as $absent) {
      $this->assertArrayNotHasKey($absent, $organization);
    }
    $this->assertNoEmptyValues($document);

    // A setting's name wins over the site's; a logo file that does not exist
    // is not published.
    $this->settings(['name' => 'Panadería Ejemplo', 'logo' => 'core/misc/no-such-logo.png']);
    $organization = $this->document()['@graph'][0];
    $this->assertSame('Panadería Ejemplo', $organization['name']);
    $this->assertArrayNotHasKey('logo', $organization);

    $this->settings(['logo' => 'core/misc/druplicon.png']);
    $this->assertStringEndsWith('/core/misc/druplicon.png', $this->document()['@graph'][0]['logo']);

    // No logo setting: the theme's own logo.
    $this->settings(['logo' => '']);
    $this->config('stark.settings')->set('logo.use_default', FALSE)->set('logo.path', 'core/misc/favicon.ico')->save();
    $this->assertStringEndsWith('/core/misc/favicon.ico', $this->document()['@graph'][0]['logo']);
  }

  /**
   * With an address, the business itself: its types, its place, its policy.
   */
  public function testBusiness(): void {
    $this->everything();
    $document = $this->document();
    $this->assertNoEmptyValues($document);
    [$organization, $business] = $document['@graph'];

    $this->assertSame(['PetStore', 'VeterinaryCare'], $business['@type']);
    $this->assertSame(['@id' => $organization['@id']], $business['parentOrganization']);
    $this->assertSame([
      '@type' => 'PostalAddress',
      'streetAddress' => 'Calle 1 # 2-3',
      'addressLocality' => 'Zipaquirá',
      'addressRegion' => 'Cundinamarca',
      'postalCode' => '250251',
      'addressCountry' => 'CO',
    ], $business['address']);
    $this->assertSame(['@type' => 'GeoCoordinates', 'latitude' => 5.0142367, 'longitude' => -73.9930965], $business['geo']);
    $this->assertSame('$$', $business['priceRange']);
    $this->assertStringEndsWith('/tienda', $business['menu']);
    $this->assertSame(['Panadería'], $business['servesCuisine']);
    $this->assertSame('VeterinaryCare', $business['department'][0]['@type']);
    $this->assertSame($business['address'], $business['department'][0]['address']);
    $this->assertStringEndsWith('/veterinaria', $business['department'][0]['url']);
    $this->assertStringEndsWith('/core/misc/druplicon.png', $business['image'][0]);

    $this->assertSame(['https://www.instagram.com/example/'], $organization['sameAs']);
    $this->assertSame('900000000-1', $organization['taxID']);
    $this->assertSame([
      '@type' => 'ContactPoint',
      'contactType' => 'wholesale',
      'telephone' => '+57 301 000 0001',
    ], $organization['contactPoint'][0]);
    $this->assertSame([
      '@type' => 'MerchantReturnPolicy',
      'applicableCountry' => 'CO',
      'returnPolicyCategory' => 'https://schema.org/MerchantReturnFiniteReturnWindow',
      'merchantReturnDays' => 5,
      'returnMethod' => 'https://schema.org/ReturnInStore',
      'returnFees' => 'https://schema.org/FreeReturn',
    ], $organization['hasMerchantReturnPolicy']);

    // One type is a string, not a list of one.
    $this->settings(['types' => ['Bakery']]);
    $this->assertSame('Bakery', $this->document()['@graph'][1]['@type']);
    // No returns: no window, no method, no fees.
    $this->settings([
      'return_policy' => [
        'category' => 'MerchantReturnNotPermitted',
        'days' => 5,
        'country' => 'CO',
        'method' => 'ReturnInStore',
        'fees' => 'FreeReturn',
      ],
    ]);
    $this->assertSame([
      '@type' => 'MerchantReturnPolicy',
      'applicableCountry' => 'CO',
      'returnPolicyCategory' => 'https://schema.org/MerchantReturnNotPermitted',
    ], $this->document()['@graph'][0]['hasMerchantReturnPolicy']);
    // A policy without its country says nothing.
    $this->settings([
      'return_policy' => [
        'category' => 'MerchantReturnNotPermitted',
        'days' => 0,
        'country' => '',
        'method' => '',
        'fees' => '',
      ],
    ]);
    $this->assertArrayNotHasKey('hasMerchantReturnPolicy', $this->document()['@graph'][0]);
  }

  /**
   * Hours as Google reads them: day names, hh:mm:ss, closed days as 00:00.
   */
  public function testHours(): void {
    $this->everything();
    $hours = $this->document()['@graph'][1]['openingHoursSpecification'];
    $this->assertSame([
      [
        '@type' => 'OpeningHoursSpecification',
        'dayOfWeek' => ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'],
        'opens' => '09:00:00',
        'closes' => '18:00:00',
      ],
      [
        '@type' => 'OpeningHoursSpecification',
        'dayOfWeek' => ['Saturday'],
        'opens' => '09:00:00',
        'closes' => '13:00:00',
      ],
      [
        '@type' => 'OpeningHoursSpecification',
        'validFrom' => '2026-12-25',
        'validThrough' => '2026-12-25',
        'opens' => '00:00:00',
        'closes' => '00:00:00',
      ],
      [
        '@type' => 'OpeningHoursSpecification',
        'validFrom' => '2026-12-24',
        'validThrough' => '2026-12-24',
        'opens' => '09:00:00',
        'closes' => '12:00:00',
      ],
    ], $hours);
    $this->assertSame('', JsonLd::time('25:00'));
    $this->assertSame('07:30:00', JsonLd::time('07:30'));
  }

  /**
   * No review or rating is ever printed, whatever the settings hold.
   */
  public function testNoRatings(): void {
    $this->everything();
    $json = JsonLd::encode($this->document());
    $this->assertStringNotContainsStringIgnoringCase('aggregateRating', $json);
    $this->assertStringNotContainsStringIgnoringCase('"review', $json);
    // And nothing can close the script element it is printed in.
    $this->settings(['description' => '</script><script>alert(1)</script>']);
    $this->assertStringNotContainsString('</script>', JsonLd::encode($this->document()));
  }

  /**
   * The front page carries one script with the document, valid JSON.
   */
  public function testFrontPage(): void {
    $this->everything();
    $this->config('system.site')->set('page.front', '/user/login')->save();
    $this->container->get('router.builder')->rebuild();

    $this->drupalGet('/');
    $this->assertSession()->statusCodeEquals(200);
    $scripts = $this->getSession()->getPage()->findAll('css', 'script[type="application/ld+json"]');
    $this->assertCount(1, $scripts);
    $document = json_decode($scripts[0]->getHtml(), TRUE, 512, JSON_THROW_ON_ERROR);
    $this->assertSame('Example Bakery', $document['@graph'][0]['name']);
    $this->assertSame(['PetStore', 'VeterinaryCare'], $document['@graph'][1]['@type']);
  }

  /**
   * Any other page carries none.
   *
   * A test of its own: the path matcher keeps its front-page answer for the
   * life of the container, which in a kernel test spans every request.
   */
  public function testOtherPage(): void {
    $this->everything();
    $this->config('system.site')->set('page.front', '/user/login')->save();
    $this->container->get('router.builder')->rebuild();

    $this->drupalGet('/user/password');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertCount(0, $this->getSession()->getPage()->findAll('css', 'script[type="application/ld+json"]'));
  }

  /**
   * The block shows what the JSON-LD says.
   */
  public function testBlock(): void {
    $this->everything();
    $block = $this->container->get('plugin.manager.block')->createInstance('business_schema_details', []);
    $build = $block->build();
    $html = (string) $this->container->get('renderer')->renderInIsolation($build);
    $this->setRawContent($html);

    $this->assertSame('Example Bakery', trim((string) $this->cssSelect('.business-details__name')[0]));
    $this->assertStringContainsString('Calle 1 # 2-3', $html);
    $this->assertStringContainsString('250251 Zipaquirá, Cundinamarca', $html);
    $this->assertStringContainsString('Colombia', $html);
    $phone = $this->cssSelect('a.business-details__telephone');
    $this->assertSame('tel:+573010000000', (string) $phone[0]['href']);
    $this->assertSame('+57 301 000 0000', trim((string) $phone[0]));
    $this->assertStringContainsString('Monday to Friday', $html);
    $this->assertStringContainsString('09:00–18:00', $html);
    $this->assertStringContainsString('Closed', $html);
    $map = $this->cssSelect('.business-details__map a');
    $this->assertStringContainsString('query=5.0142367%2C-73.9930965', (string) $map[0]['href']);
    $this->assertContains('config:business_schema.settings', $build['#cache']['tags']);

    // No point: no map. No phone: no link.
    $this->settings(['geo' => ['latitude' => '', 'longitude' => ''], 'telephone' => '']);
    $build = $block->build();
    $this->setRawContent((string) $this->container->get('renderer')->renderInIsolation($build));
    $this->assertCount(0, $this->cssSelect('.business-details__map'));
    $this->assertCount(0, $this->cssSelect('a.business-details__telephone'));
  }

}
