<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use RobinsonRyan\Permixion\Facades\Permixion;
use RobinsonRyan\Permixion\Tests\Fixtures\Post;
use RobinsonRyan\Permixion\Tests\Fixtures\PostPolicy;
use RobinsonRyan\Permixion\Tests\Fixtures\Team;
use RobinsonRyan\Permixion\Tests\Fixtures\User;

/*
|--------------------------------------------------------------------------
| HasRoles::can()
|--------------------------------------------------------------------------
| The second, independent short-circuit. Unlike Gate::before it has NO
| `instanceof HasPermissions` guard and is not governed by `register_gate` —
| it applies wherever the trait is used. These tests deliberately use the
| trait-only User fixture so the can() door is isolated from the gate hook.
*/

beforeEach(function (): void {
    Gate::policy(Post::class, PostPolicy::class);

    Permixion::createPermission('update');
    Permixion::createPermission('posts.create');
    Permixion::createRole('editor', ['update']);

    $this->author = User::create(['name' => 'Author', 'email' => 'author@example.com']);
    $this->other = User::create(['name' => 'Other', 'email' => 'other@example.com']);

    $this->post = Post::create(['title' => 'Hello', 'author_id' => $this->author->id]);
});

/*
|--------------------------------------------------------------------------
| The convenience this method exists for — must survive any change
|--------------------------------------------------------------------------
*/

test('a bare ability string resolves as a permission check', function (): void {
    $this->other->givePermissionTo('posts.create');

    expect($this->other->can('posts.create'))->toBeTrue();
});

test('a bare ability the user lacks falls through to the gate', function (): void {
    expect($this->other->can('posts.create'))->toBeFalse();
});

test('a Scope passed as the first argument scopes the permission check', function (): void {
    config()->set('permixion.teams.enabled', true);

    $teamA = Team::create(['name' => 'Team A']);
    $teamB = Team::create(['name' => 'Team B']);

    $this->other->givePermissionTo('posts.create', $teamA);

    expect($this->other->can('posts.create', [$teamA]))->toBeTrue()
        ->and($this->other->can('posts.create', [$teamB]))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Crash-safety, pinned by the fixes in ec4b5ef
|--------------------------------------------------------------------------
*/

test('a non-ArrayAccess object argument does not fatal', function (): void {
    // `$user->can('update', $someDto)` previously fataled on $arguments[0].
    $dto = new stdClass;

    expect(fn (): bool => $this->other->can('update', $dto))->not->toThrow(Throwable::class);
});

test('an iterable ability delegates to the parent instead of fataling', function (): void {
    // Laravel's Authorizable::can() accepts iterable|string|UnitEnum.
    expect(fn (): bool => $this->other->can(['update', 'posts.create']))->not->toThrow(TypeError::class);
});

/*
|--------------------------------------------------------------------------
| The defect — CHARACTERIZATION of today's behaviour
|--------------------------------------------------------------------------
| These two tests are expected to INVERT when can() is narrowed. They exist so
| the change is visible as a deliberate behavioural edit, not a silent drift.
*/

test('CURRENT: a model argument is ignored and the policy is bypassed', function (): void {
    $this->other->assignRole('editor');

    // The policy would refuse — $other is not the author.
    expect((new PostPolicy)->update($this->other, $this->post))->toBeFalse();

    // can() ignores the Post entirely and answers from the permission name.
    expect($this->other->can('update', $this->post))->toBeTrue();
});

test('CURRENT: the bypass applies to a trait-only user, with no opt-in', function (): void {
    // No `implements HasPermissions`, and register_gate is irrelevant here.
    // This is the door that cannot be closed by configuration.
    $this->other->assignRole('editor');

    expect($this->other)->not->toBeInstanceOf(RobinsonRyan\Permixion\Contracts\HasPermissions::class)
        ->and($this->other->can('update', $this->post))->toBeTrue();
});

test('without the permission, the model form reaches the policy correctly', function (): void {
    expect($this->author->can('update', $this->post))->toBeTrue()
        ->and($this->other->can('update', $this->post))->toBeFalse();
});
