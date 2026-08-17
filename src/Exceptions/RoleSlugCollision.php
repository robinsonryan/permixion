<?php

declare(strict_types=1);

namespace RobinsonRyan\Permixion\Exceptions;

use Exception;
use Throwable;

/**
 * A role's name could not be written into the tag `slug` column because a
 * sibling tag already holds that slug.
 *
 * Without this, the caller sees Taxon's raw
 * `UniqueConstraintViolationException` — a message naming
 * `tags_unique_slug_parent_tenant` and a slug value, neither of which any
 * Permixion API accepts or returns. This one names the role that was refused
 * and, where it can be found, the row standing in its way.
 */
class RoleSlugCollision extends Exception
{
    public function __construct(
        public readonly string $roleName,
        public readonly ?string $collidingName = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            $collidingName === null
                ? "Role '{$roleName}' could not be created: another role already occupies the tag slug '{$roleName}'."
                : "Role '{$roleName}' could not be created: the role '{$collidingName}' already occupies the tag slug '{$roleName}'.",
            0,
            $previous,
        );
    }
}
