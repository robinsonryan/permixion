<?php

declare(strict_types=1);

namespace RobinsonRyan\Permixion\Tests\Fixtures;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * A policy with a genuine per-instance clause.
 *
 * This mirrors the shape that matters in consuming apps: the decision cannot be
 * expressed as a permission name, because it depends on which record is being
 * acted on. Any short-circuit that answers `update` without seeing the Post is
 * answering a question it cannot know the answer to.
 *
 * The user is typed to the framework contract rather than a fixture class so the
 * same policy serves both {@see User} (trait only) and {@see GateUser} (trait +
 * contract), which is exactly the pair the Gate::before guard distinguishes.
 */
class PostPolicy
{
    public function update(Authenticatable $user, Post $post): bool
    {
        return $post->author_id === $user->getAuthIdentifier();
    }

    /** An ability with no model argument — a coarse capability question. */
    public function viewAny(Authenticatable $user): bool
    {
        return false;
    }
}
