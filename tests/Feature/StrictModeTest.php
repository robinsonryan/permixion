<?php

declare(strict_types=1);

use RobinsonRyan\Permixion\Exceptions\PermissionAlreadyExists;
use RobinsonRyan\Permixion\Exceptions\PermissionDoesNotExist;
use RobinsonRyan\Permixion\Exceptions\RoleAlreadyExists;
use RobinsonRyan\Permixion\Exceptions\RoleDoesNotExist;
use RobinsonRyan\Permixion\Facades\Permixion;
use RobinsonRyan\Permixion\Tests\Fixtures\User;

/*
|--------------------------------------------------------------------------
| permixion.strict
|--------------------------------------------------------------------------
| TestCase pins strict => true, so the false branch had never executed. Both
| branches are covered here. The asymmetry is the point: strict governs the
| WRITE paths only. Read paths never throw in either mode, which is what makes
| the Gate::before hook safe to install.
*/

beforeEach(function (): void {
    $this->user = User::create(['name' => 'Test User', 'email' => 'test@example.com']);
});

/*
|--------------------------------------------------------------------------
| strict = true (the pinned default)
|--------------------------------------------------------------------------
*/

test('strict: creating a duplicate role throws', function (): void {
    Permixion::createRole('editor');

    expect(fn () => Permixion::createRole('editor'))->toThrow(RoleAlreadyExists::class);
});

test('strict: creating a duplicate permission throws', function (): void {
    Permixion::createPermission('posts.create');

    expect(fn () => Permixion::createPermission('posts.create'))->toThrow(PermissionAlreadyExists::class);
});

test('strict: assigning an unknown role throws', function (): void {
    expect(fn () => $this->user->assignRole('nonexistent'))->toThrow(RoleDoesNotExist::class);
});

test('strict: granting an unknown permission throws', function (): void {
    expect(fn () => $this->user->givePermissionTo('nonexistent'))->toThrow(PermissionDoesNotExist::class);
});

/*
|--------------------------------------------------------------------------
| strict = false — never previously executed
|--------------------------------------------------------------------------
*/

test('lenient: creating a duplicate role returns the existing one', function (): void {
    config()->set('permixion.strict', false);

    $first = Permixion::createRole('editor');
    $second = Permixion::createRole('editor');

    expect($second->name)->toBe($first->name)
        ->and($second->tag()->id)->toBe($first->tag()->id);
});

test('lenient: creating a duplicate permission returns the existing one', function (): void {
    config()->set('permixion.strict', false);

    $first = Permixion::createPermission('posts.create');
    $second = Permixion::createPermission('posts.create');

    expect($second->name)->toBe($first->name)
        ->and($second->tag()->id)->toBe($first->tag()->id);
});

test('lenient: assigning an unknown role CREATES it rather than throwing', function (): void {
    config()->set('permixion.strict', false);

    $this->user->assignRole('brand-new-role');

    expect($this->user->hasRole('brand-new-role'))->toBeTrue()
        ->and(Permixion::findRole('brand-new-role'))->not->toBeNull();
});

test('lenient: granting an unknown permission CREATES it rather than throwing', function (): void {
    config()->set('permixion.strict', false);

    $this->user->givePermissionTo('brand-new-permission');

    expect($this->user->hasPermissionTo('brand-new-permission'))->toBeTrue()
        ->and(Permixion::findPermission('brand-new-permission'))->not->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Read paths are unaffected by strict — in BOTH modes
|--------------------------------------------------------------------------
*/

test('reading an unknown role or permission never throws, in either mode', function (bool $strict): void {
    config()->set('permixion.strict', $strict);

    expect($this->user->hasRole('nonexistent'))->toBeFalse()
        ->and($this->user->hasPermissionTo('nonexistent'))->toBeFalse()
        ->and($this->user->hasAnyRole(['nope', 'nada']))->toBeFalse()
        ->and($this->user->hasAnyPermission(['nope', 'nada']))->toBeFalse();
})->with([true, false]);
