<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use RobinsonRyan\Permixion\Facades\Permixion;
use RobinsonRyan\Permixion\Models\Permission;
use RobinsonRyan\Permixion\Tests\Fixtures\Seeders\PermissionSeeder;
use RobinsonRyan\Permixion\Tests\Fixtures\Seeders\RoleSeeder;
use RobinsonRyan\Permixion\Tests\Fixtures\Seeders\StaffSeeder;
use RobinsonRyan\Permixion\Tests\Fixtures\User;

/*
|--------------------------------------------------------------------------
| Seeding works
|--------------------------------------------------------------------------
|
| The acceptance bar for the Taxon 0.5.x upgrade is that a consuming
| application's seeders still run and still produce the grants they claim to.
| The rest of the suite calls the engine directly; this file goes through
| `db:seed` with seeder classes shaped the way a real application's are — find
| or create, sync the whole permission set, re-runnable — so the seeding path
| is exercised end to end rather than inferred from its parts.
|
| Two shapes here are load-bearing rather than decorative: one user is created
| with `createQuietly()`, and one whole run happens inside
| `Model::withoutEvents()`. Both suppress the model events Taxon 0.5.0 relied on
| to flush a deferred tag assignment, and seeders are exactly where they live.
|
*/

/** @return list<string> */
function seededPermissionNames(): array
{
    return collect(Permixion::getAllPermissions())
        ->map(fn (Permission $permission): string => $permission->name)
        ->sort()
        ->values()
        ->all();
}

it('creates every permission the seeder names', function (): void {
    $this->seed(PermissionSeeder::class);

    expect(seededPermissionNames())->toBe(collect(PermissionSeeder::PERMISSIONS)->sort()->values()->all());
});

/*
 * `account.view` and `accountview` both reduce to `accountview` under
 * `Str::slug`, and Taxon 0.4.0's hardened index refuses two siblings with the
 * same slug. Storing the name verbatim is what lets a seeder hold both, so the
 * pair is seeded and then read back by name.
 */
it('seeds two permissions whose slugs would have collided', function (): void {
    $this->seed(PermissionSeeder::class);

    expect(Permixion::permissionExists('account.view'))->toBeTrue()
        ->and(Permixion::permissionExists('accountview'))->toBeTrue()
        ->and(Permixion::findPermissionOrFail('account.view')->slug)->toBe('account.view')
        ->and(Permixion::findPermissionOrFail('accountview')->slug)->toBe('accountview');
});

it('creates every role with exactly the permissions the seeder gives it', function (): void {
    $this->seed([PermissionSeeder::class, RoleSeeder::class]);

    foreach (RoleSeeder::ROLES as $name => $permissions) {
        expect(Permixion::roleExists($name))->toBeTrue()
            ->and(collect(Permixion::findRoleOrFail($name)->getPermissionNames())->sort()->values()->all())
            ->toBe(collect($permissions)->sort()->values()->all());
    }
});

it('grants the seeded users their roles and the permissions behind them', function (): void {
    $this->seed([PermissionSeeder::class, RoleSeeder::class, StaffSeeder::class]);

    $manager = User::where('email', 'manager@example.test')->firstOrFail();
    $technician = User::where('email', 'tech@example.test')->firstOrFail();

    expect($manager->hasRole('shop_manager'))->toBeTrue()
        ->and($manager->hasPermissionTo('settings.update'))->toBeTrue()
        ->and($technician->hasRole('technician'))->toBeTrue()
        ->and($technician->hasPermissionTo('work_order.close'))->toBeTrue()
        ->and($technician->hasPermissionTo('settings.update'))->toBeFalse();
});

/*
 * The one that would have caught Taxon 0.5.0's quiet-save defect if this package
 * had been exposed to it: a user created with `createQuietly()` fires no model
 * events, so anything flushing a grant from a `saved` listener drops it — with
 * a saved user, no pivot row, and no exception.
 */
it('grants a role to a user the seeder created quietly', function (): void {
    $this->seed([PermissionSeeder::class, RoleSeeder::class, StaffSeeder::class]);

    $desk = User::where('email', 'desk@example.test')->firstOrFail();

    expect($desk->exists)->toBeTrue()
        ->and($desk->hasRole('front_desk'))->toBeTrue()
        ->and($desk->hasPermissionTo('account.view'))->toBeTrue();
});

it('grants roles when the whole seed runs with model events switched off', function (): void {
    Model::withoutEvents(function (): void {
        $this->seed([PermissionSeeder::class, RoleSeeder::class, StaffSeeder::class]);
    });

    $manager = User::where('email', 'manager@example.test')->firstOrFail();

    expect($manager->hasRole('shop_manager'))->toBeTrue()
        ->and($manager->hasPermissionTo('work_order.assign'))->toBeTrue();
});

/*
 * Seeders get re-run. Strict mode throws on a second `createRole`, so the
 * find-or-create shape is the thing under test as much as the result is.
 */
it('is re-runnable — a second pass adds nothing and throws nothing', function (): void {
    $this->seed([PermissionSeeder::class, RoleSeeder::class]);
    $this->seed([PermissionSeeder::class, RoleSeeder::class]);

    expect(seededPermissionNames())->toBe(collect(PermissionSeeder::PERMISSIONS)->sort()->values()->all())
        ->and(Permixion::getAllRoles())->toHaveCount(count(RoleSeeder::ROLES))
        ->and(collect(Permixion::findRoleOrFail('technician')->getPermissionNames())->sort()->values()->all())
        ->toBe(collect(RoleSeeder::ROLES['technician'])->sort()->values()->all());
});
