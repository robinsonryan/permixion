<?php

use RobinsonRyan\Permixion\Facades\Permixion;
use RobinsonRyan\Permixion\Tests\Fixtures\User;

beforeEach(function () {
    // Create basic roles and permissions
    Permixion::createPermission('posts.create');
    Permixion::createPermission('posts.edit');
    Permixion::createPermission('posts.delete');
    Permixion::createPermission('users.manage');

    Permixion::createRole('admin', ['posts.create', 'posts.edit', 'posts.delete', 'users.manage']);
    Permixion::createRole('editor', ['posts.create', 'posts.edit']);
    Permixion::createRole('member', ['posts.create']);

    $this->user = User::create(['name' => 'Test User', 'email' => 'test@example.com']);
});

test('user can be assigned a role', function () {
    $this->user->assignRole('editor');

    expect($this->user->hasRole('editor'))->toBeTrue();
});

test('user can have multiple roles', function () {
    $this->user->assignRole(['editor', 'member']);

    expect($this->user->hasRole('editor'))->toBeTrue()
        ->and($this->user->hasRole('member'))->toBeTrue();
});

test('user can remove a role', function () {
    $this->user->assignRole('editor');
    $this->user->removeRole('editor');

    expect($this->user->hasRole('editor'))->toBeFalse();
});

test('user can sync roles', function () {
    $this->user->assignRole(['editor', 'member']);
    $this->user->syncRoles(['admin']);

    expect($this->user->hasRole('admin'))->toBeTrue()
        ->and($this->user->hasRole('editor'))->toBeFalse()
        ->and($this->user->hasRole('member'))->toBeFalse();
});

test('user has permissions through role', function () {
    $this->user->assignRole('editor');

    expect($this->user->hasPermissionTo('posts.create'))->toBeTrue()
        ->and($this->user->hasPermissionTo('posts.edit'))->toBeTrue()
        ->and($this->user->hasPermissionTo('posts.delete'))->toBeFalse();
});

test('user can have direct permissions', function () {
    $this->user->givePermissionTo('posts.delete');

    expect($this->user->hasPermissionTo('posts.delete'))->toBeTrue();
});

test('user can check any permission', function () {
    $this->user->assignRole('member');

    expect($this->user->hasAnyPermission(['posts.create', 'posts.delete']))->toBeTrue()
        ->and($this->user->hasAnyPermission(['posts.edit', 'posts.delete']))->toBeFalse();
});

test('user can check all permissions', function () {
    $this->user->assignRole('editor');

    expect($this->user->hasAllPermissions(['posts.create', 'posts.edit']))->toBeTrue()
        ->and($this->user->hasAllPermissions(['posts.create', 'posts.delete']))->toBeFalse();
});

test('role can give and revoke permissions', function () {
    $role = Permixion::findRole('member');

    $role->givePermissionTo('posts.edit');
    expect($role->hasPermissionTo('posts.edit'))->toBeTrue();

    $role->revokePermissionTo('posts.edit');
    expect($role->hasPermissionTo('posts.edit'))->toBeFalse();
});

test('user can check any role', function () {
    $this->user->assignRole('editor');

    expect($this->user->hasAnyRole(['admin', 'editor']))->toBeTrue()
        ->and($this->user->hasAnyRole(['admin', 'member']))->toBeFalse();
});

test('user can check all roles', function () {
    $this->user->assignRole(['editor', 'member']);

    expect($this->user->hasAllRoles(['editor', 'member']))->toBeTrue()
        ->and($this->user->hasAllRoles(['editor', 'admin']))->toBeFalse();
});
