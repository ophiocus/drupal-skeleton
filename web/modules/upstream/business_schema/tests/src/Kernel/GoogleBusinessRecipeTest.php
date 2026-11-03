<?php

declare(strict_types=1);

namespace Drupal\Tests\business_schema\Kernel;

use Drupal\Core\Recipe\Recipe;
use Drupal\Core\Recipe\RecipeRunner;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The recipe that turns Google's basics on: recipes/google-business.
 *
 * Its shop variant, recipes/google-business-shop, needs Commerce, which the
 * upstream's own codebase does not carry; it is not applied here.
 */
#[Group('business_schema')]
#[RunTestsInSeparateProcesses]
final class GoogleBusinessRecipeTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user'];

  /**
   * Installs the modules, the sitemap and the meta tag defaults.
   */
  public function testRecipe(): void {
    $this->installEntitySchema('user');
    $recipe = DRUPAL_ROOT . '/../recipes/google-business';
    RecipeRunner::processRecipe(Recipe::createFromDirectory($recipe));

    $modules = $this->container->get('module_handler');
    foreach (['token', 'pathauto', 'metatag', 'metatag_open_graph', 'simple_sitemap', 'business_schema'] as $module) {
      $this->assertTrue($modules->moduleExists($module), "$module is installed.");
    }
    $active = $this->container->get('config.storage');
    $this->assertTrue($active->exists('simple_sitemap.sitemap.default'), 'The sitemap exists.');
    $this->assertTrue($active->exists('business_schema.settings'));
    $this->assertSame(['/'], array_column($this->config('simple_sitemap.custom_links.default')->get('links'), 'path'), 'The front page is in the sitemap.');

    $global = $this->config('metatag.metatag_defaults.global')->get('tags');
    $this->assertSame('[site:slogan]', $global['description']);
    $this->assertSame('[site:name]', $global['og_site_name']);
    // The tags metatag ships are kept.
    $this->assertSame('[current-page:url]', $global['canonical_url']);
    $front = $this->config('metatag.metatag_defaults.front')->get('tags');
    $this->assertSame('website', $front['og_type']);
    $this->assertSame('[site:url]', $front['canonical_url']);
    $this->assertSame('[term:name]', $this->config('metatag.metatag_defaults.taxonomy_term')->get('tags.og_title'));

    // No product configuration: that is the shop recipe's.
    $this->assertFalse($active->exists('pathauto.pattern.producto'));
    $this->assertFalse($active->exists('metatag.metatag_defaults.commerce_product'));

    // A site that already chose its own defaults keeps everything the
    // actions do not name; applying again changes nothing else.
    $this->config('metatag.metatag_defaults.node')->set('tags.description', '[node:field_teaser]')->save();
    RecipeRunner::processRecipe(Recipe::createFromDirectory($recipe));
    $this->assertSame('[node:field_teaser]', $this->config('metatag.metatag_defaults.node')->get('tags.description'));
  }

}
