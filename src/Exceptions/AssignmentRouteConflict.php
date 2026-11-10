<?php

declare(strict_types=1);

namespace RobinsonRyan\Permixion\Exceptions;

use Exception;

/**
 * Thrown when assignRole() would merge two routes to one role in one scope.
 *
 * A holder holds a role once per scope, so a capped route (assigned through a
 * holder or ceiling) and an uncapped one (a plain membership) cannot both be
 * stored under the same role name. Merging them would silently cap the
 * uncapped route, or — the other way round — be read as lifting the cap. Hold
 * the routes under route-specific role names (`firm-accountant` beside
 * `accountant`, with the same permissions), or removeRole() first.
 */
class AssignmentRouteConflict extends Exception
{
    public static function cappedOntoUncapped(string $roleName): self
    {
        return new self(
            "Role '{$roleName}' is already held uncapped in this scope; assigning it through a holder or ceiling "
            .'would cap that assignment too. Hold the capped route under its own role name '
            ."(e.g. 'firm-{$roleName}'), or removeRole() first."
        );
    }

    public static function uncappedOntoCapped(string $roleName): self
    {
        return new self(
            "Role '{$roleName}' is held capped in this scope; assigning it again with no through cannot lift "
            .'the cap and would hide a second route inside the first. Hold the uncapped route under its own '
            .'role name, or removeRole() and assign again.'
        );
    }
}
