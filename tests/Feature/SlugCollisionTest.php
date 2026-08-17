<?php

declare(strict_types=1);

use Illuminate\Database\UniqueConstraintViolationException;
use RobinsonRyan\Permixion\Exceptions\PermissionSlugCollision;
use RobinsonRyan\Permixion\Exceptions\RoleAlreadyExists;
use RobinsonRyan\Permixion\Exceptions\RoleSlugCollision;
use RobinsonRyan\Permixion\Facades\Permixion;
use RobinsonRyan\Taxon\Models\Tag;

/*
|--------------------------------------------------------------------------
| What a slug collision says out loud
|--------------------------------------------------------------------------
|
| Identity in this package is the name, and the name is now written verbatim
| into the tag `slug` column that Taxon's hardened unique index covers. So a row
| written by an earlier version — which stored `Str::slug($name)` there — can sit
| on the slug a new, differently-named role needs. The name-exists check ahead of
| the insert cannot see it, because the names differ.
|
| What the caller used to get for that was Taxon's raw
| `UniqueConstraintViolationException`: an index name and a slug value, neither
| of which any Permixion API accepts or returns. These tests pin the replacement
| — an exception that names the role or permission that was refused, and the row
| standing in its way.
|
*/

function occupySlug(Tag $category, string $slug, string $name): Tag
{
    return Tag::create([
        'name' => $name,
        'slug' => $slug,
        'parent_id' => $category->id,
        'assignable' => true,
    ]);
}

it('names the role that was refused and the one already holding its slug', function (): void {
    occupySlug(Permixion::rolesCategory(), 'sales_rep', 'Sales Rep');

    expect(fn () => Permixion::createRole('sales_rep'))
        ->toThrow(
            RoleSlugCollision::class,
            "Role 'sales_rep' could not be created: the role 'Sales Rep' already occupies the tag slug 'sales_rep'."
        );
});

it('names the permission that was refused and the one already holding its slug', function (): void {
    occupySlug(Permixion::permissionsCategory(), 'account.view', 'accountview');

    expect(fn () => Permixion::createPermission('account.view'))
        ->toThrow(
            PermissionSlugCollision::class,
            "Permission 'account.view' could not be created: the permission 'accountview' already occupies the tag slug 'account.view'."
        );
});

it('keeps the database error as the cause and the refused name on the exception', function (): void {
    occupySlug(Permixion::rolesCategory(), 'sales_rep', 'Sales Rep');

    try {
        Permixion::createRole('sales_rep');
        $this->fail('createRole should have refused the colliding slug.');
    } catch (RoleSlugCollision $e) {
        expect($e->roleName)->toBe('sales_rep')
            ->and($e->collidingName)->toBe('Sales Rep')
            ->and($e->getPrevious())->toBeInstanceOf(UniqueConstraintViolationException::class);
    }
});

/*
 * The same name is still `RoleAlreadyExists`, not a collision — the check ahead
 * of the insert catches it and never reaches the database. Pinned so the new
 * catch block cannot quietly take over that case.
 */
it('still reports a same-name role as already existing, not as a collision', function (): void {
    Permixion::createRole('shop_manager');

    expect(fn () => Permixion::createRole('shop_manager'))
        ->toThrow(RoleAlreadyExists::class);
});
