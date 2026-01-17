<?php

use RobinsonRyan\Permixion\Facades\Permixion;
use RobinsonRyan\Permixion\Tests\Fixtures\User;

beforeEach(function () {
    Permixion::createPermission('posts.*');
    Permixion::createPermission('users.view');

    Permixion::createRole('editor', ['posts.*']);
    Permixion::createRole('viewer', ['users.view']);

    $this->user = User::create(['name' => 'Test User', 'email' => 'test@example.com']);
});

test('wildcard permissions match specific permissions', function () {
    $this->user->assignRole('editor');

    expect($this->user->hasPermissionTo('posts.create'))->toBeTrue()
        ->and($this->user->hasPermissionTo('posts.edit'))->toBeTrue()
        ->and($this->user->hasPermissionTo('posts.delete'))->toBeTrue()
        ->and($this->user->hasPermissionTo('users.create'))->toBeFalse();
});

test('exact permission still works', function () {
    $this->user->assignRole('viewer');

    expect($this->user->hasPermissionTo('users.view'))->toBeTrue()
        ->and($this->user->hasPermissionTo('users.edit'))->toBeFalse();
});
