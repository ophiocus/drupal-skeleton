<?php

declare(strict_types=1);

namespace Drupal\Tests\appointments\Kernel;

use Drupal\Core\Recipe\Recipe;
use Drupal\Core\Recipe\RecipeRunner;
use Drupal\KernelTests\KernelTestBase;
use Drupal\user\Entity\Role;
use Drupal\user\RoleInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The recipe that turns bookings on: recipes/appointments.
 */
#[Group('appointments')]
#[RunTestsInSeparateProcesses]
class AppointmentsRecipeTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user'];

  /**
   * Installs the module and lets visitors book; a second run changes nothing.
   */
  public function testRecipe(): void {
    $this->installEntitySchema('user');
    Role::create(['id' => RoleInterface::ANONYMOUS_ID, 'label' => 'Anonymous'])->save();
    Role::create(['id' => RoleInterface::AUTHENTICATED_ID, 'label' => 'Authenticated'])->save();
    $recipe = DRUPAL_ROOT . '/../recipes/appointments';

    RecipeRunner::processRecipe(Recipe::createFromDirectory($recipe));
    $this->assertTrue($this->container->get('module_handler')->moduleExists('appointments'));
    foreach ([RoleInterface::ANONYMOUS_ID, RoleInterface::AUTHENTICATED_ID] as $role) {
      $this->assertTrue(Role::load($role)->hasPermission('book appointments'), "$role may book.");
    }

    // A site that changed a setting keeps it when the recipe runs again.
    $this->config('appointments.settings')->set('room_choice', 'required')->save();
    RecipeRunner::processRecipe(Recipe::createFromDirectory($recipe));
    $this->assertSame('required', $this->config('appointments.settings')->get('room_choice'));
  }

}
