<?php

declare(strict_types=1);

namespace RobinsonRyan\Permixion\Exceptions;

use Exception;
use Throwable;

/**
 * A permission's name could not be written into the tag `slug` column because a
 * sibling tag already holds that slug.
 *
 * Without this, the caller sees Taxon's raw
 * `UniqueConstraintViolationException` — a message naming
 * `tags_unique_slug_parent_tenant` and a slug value, neither of which any
 * Permixion API accepts or returns. This one names the permission that was
 * refused and, where it can be found, the row standing in its way.
 */
class PermissionSlugCollision extends Exception
{
    public function __construct(
        public readonly string $permissionName,
        public readonly ?string $collidingName = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            $collidingName === null
                ? "Permission '{$permissionName}' could not be created: another permission already occupies the tag slug '{$permissionName}'."
                : "Permission '{$permissionName}' could not be created: the permission '{$collidingName}' already occupies the tag slug '{$permissionName}'.",
            0,
            $previous,
        );
    }
}
