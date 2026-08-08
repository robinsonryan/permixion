<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use RobinsonRyan\Permixion\Exceptions\UnauthorizedException;
use RobinsonRyan\Permixion\Facades\Permixion;
use RobinsonRyan\Permixion\Tests\Fixtures\User;

beforeEach(function (): void {
    Permixion::createPermission('posts.create');
    Permixion::createPermission('posts.delete');
    Permixion::createRole('editor', ['posts.create']);
    Permixion::createRole('admin', ['posts.create', 'posts.delete']);

    $this->user = User::create(['name' => 'Test User', 'email' => 'test@example.com']);

    Route::middleware('role:admin')->get('/role-gate', fn (): string => 'ok');
    Route::middleware('role:admin,editor')->get('/role-gate-any', fn (): string => 'ok');
    Route::middleware('permission:posts.delete')->get('/permission-gate', fn (): string => 'ok');
    Route::middleware('role_or_permission:admin,posts.create')->get('/either-gate', fn (): string => 'ok');
});

/*
|--------------------------------------------------------------------------
| RoleMiddleware
|--------------------------------------------------------------------------
*/

test('role middleware admits a user holding the role', function (): void {
    $this->user->assignRole('admin');

    $this->actingAs($this->user)->get('/role-gate')->assertOk();
});

test('role middleware refuses a user without the role', function (): void {
    $this->user->assignRole('editor');

    $this->actingAs($this->user)->get('/role-gate')->assertForbidden();
});

test('role middleware accepts any one of several roles', function (): void {
    $this->user->assignRole('editor');

    $this->actingAs($this->user)->get('/role-gate-any')->assertOk();
});

test('role middleware refuses a guest', function (): void {
    $this->withoutExceptionHandling();

    expect(fn () => $this->get('/role-gate'))
        ->toThrow(UnauthorizedException::class, 'User is not logged in.');
});

/*
|--------------------------------------------------------------------------
| PermissionMiddleware
|--------------------------------------------------------------------------
*/

test('permission middleware admits a user holding the permission via a role', function (): void {
    $this->user->assignRole('admin');

    $this->actingAs($this->user)->get('/permission-gate')->assertOk();
});

test('permission middleware refuses a user whose role lacks the permission', function (): void {
    $this->user->assignRole('editor');

    $this->actingAs($this->user)->get('/permission-gate')->assertForbidden();
});

test('permission middleware admits a directly granted permission', function (): void {
    $this->user->givePermissionTo('posts.delete');

    $this->actingAs($this->user)->get('/permission-gate')->assertOk();
});

test('the refusal carries the required permissions for the consumer to render', function (): void {
    $this->withoutExceptionHandling();

    try {
        $this->actingAs($this->user)->get('/permission-gate');
        $this->fail('Expected UnauthorizedException.');
    } catch (UnauthorizedException $e) {
        expect($e->getRequiredPermissions())->toBe(['posts.delete'])
            ->and($e->getStatusCode())->toBe(403);
    }
});

/*
|--------------------------------------------------------------------------
| RoleOrPermissionMiddleware
|--------------------------------------------------------------------------
*/

test('role_or_permission admits on the role alone', function (): void {
    $this->user->assignRole('admin');

    $this->actingAs($this->user)->get('/either-gate')->assertOk();
});

test('role_or_permission admits on the permission alone', function (): void {
    $this->user->givePermissionTo('posts.create');

    $this->actingAs($this->user)->get('/either-gate')->assertOk();
});

test('role_or_permission refuses when neither is held', function (): void {
    $this->actingAs($this->user)->get('/either-gate')->assertForbidden();
});
