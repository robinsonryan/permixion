<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use RobinsonRyan\Permixion\Contracts\HasPermissions;
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
| The bare-scope fix (0.6.0). Laravel treats `can($ability, $arg)` and
| `can($ability, [$arg])` as the same call; before 0.6.0 the bare form skipped
| scope extraction and checked the ambient scope instead.
*/

test('a bare Scope argument scopes the permission check, as the array form does', function (): void {
    config()->set('permixion.teams.enabled', true);

    $teamA = Team::create(['name' => 'Team A']);
    $teamB = Team::create(['name' => 'Team B']);

    $this->other->givePermissionTo('posts.create', $teamA);

    expect($this->other->can('posts.create', $teamA))->toBeTrue()
        ->and($this->other->can('posts.create', $teamB))->toBeFalse()
        ->and($this->other->can('posts.create', $teamA))->toBe($this->other->can('posts.create', [$teamA]))
        ->and($this->other->can('posts.create', $teamB))->toBe($this->other->can('posts.create', [$teamB]));
});

test('a bare Scope no longer falls back to an unscoped grant', function (): void {
    // The old leak: a permission held only unscoped satisfied can('x', $team),
    // because the bare Scope was ignored and the check ran unscoped.
    $team = Team::create(['name' => 'Team A']);

    $this->other->givePermissionTo('posts.create');

    expect($this->other->can('posts.create', $team))->toBeFalse()
        ->and($this->other->can('posts.create', [$team]))->toBeFalse()
        ->and($this->other->can('posts.create'))->toBeTrue();
});

test('a bare Scope wins over the ambient scope', function (): void {
    config()->set('permixion.teams.enabled', true);
    config()->set('permixion.teams.resolver', 'callback');

    $teamA = Team::create(['name' => 'Team A']);
    $teamB = Team::create(['name' => 'Team B']);
    config()->set('permixion.teams.callback', fn (): Team => $teamA);

    $this->other->givePermissionTo('posts.create', $teamA);

    expect($this->other->can('posts.create'))->toBeTrue()
        ->and($this->other->can('posts.create', $teamB))->toBeFalse();
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
| The narrowing — a permission name may not answer a per-instance question
|--------------------------------------------------------------------------
| These two inverted when can() was narrowed. That inversion IS the fix, and it
| is recorded here rather than in a changelog line so it cannot regress quietly.
*/

test('a model argument is handed to the policy instead of being answered here', function (): void {
    $this->other->assignRole('editor');

    // Holds the `update` permission outright...
    expect($this->other->hasPermissionTo('update'))->toBeTrue();

    // ...but the policy owns this question, and it refuses: not the author.
    expect((new PostPolicy)->update($this->other, $this->post))->toBeFalse()
        ->and($this->other->can('update', $this->post))->toBeFalse();
});

test('the narrowing applies to trait-only users, with no opt-in needed', function (): void {
    // This door has no `instanceof HasPermissions` guard and ignores
    // register_gate, so the fix has to hold without any consumer opting in.
    $this->other->assignRole('editor');

    expect($this->other)->not->toBeInstanceOf(HasPermissions::class)
        ->and($this->other->can('update', $this->post))->toBeFalse();
});

test('the policy still grants when it should — narrowing removed no legitimate access', function (): void {
    $this->author->assignRole('editor');

    expect($this->author->can('update', $this->post))->toBeTrue();
});

test('a class-string argument is a policy question and is delegated', function (): void {
    $this->other->assignRole('editor');
    Permixion::createPermission('viewAny');
    $this->other->givePermissionTo('viewAny');

    // PostPolicy::viewAny() returns false. The permission must not override it.
    expect($this->other->can('viewAny', Post::class))->toBeFalse();
});

test('without the permission, the model form reaches the policy correctly', function (): void {
    expect($this->author->can('update', $this->post))->toBeTrue()
        ->and($this->other->can('update', $this->post))->toBeFalse();
});

test('the narrowing holds in both defer_to_policies states', function (bool $defer): void {
    // defer_to_policies governs the Gate::before hook only. It must never be able
    // to re-open this door, in either direction.
    config()->set('permixion.gate.defer_to_policies', $defer);

    $this->other->assignRole('editor');

    expect($this->other->can('update', $this->post))->toBeFalse();
})->with([true, false]);

test('the narrowing holds regardless of register_gate', function (bool $registered): void {
    // Nor is this door governed by register_gate — nbss runs with it false and must
    // still get the policy's answer.
    config()->set('permixion.register_gate', $registered);

    $this->other->assignRole('editor');

    expect($this->other->can('update', $this->post))->toBeFalse();
})->with([true, false]);
