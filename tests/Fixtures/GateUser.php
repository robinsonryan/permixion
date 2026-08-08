<?php

declare(strict_types=1);

namespace RobinsonRyan\Permixion\Tests\Fixtures;

use Illuminate\Foundation\Auth\User as Authenticatable;
use RobinsonRyan\Permixion\Contracts\HasPermissions;
use RobinsonRyan\Permixion\Traits\HasRoles;
use RobinsonRyan\Taxon\HasTags;

/**
 * A user that explicitly opts in to the Gate::before short-circuit.
 *
 * The distinction from {@see User} is load-bearing: `Gate::before` guards on
 * `$user instanceof HasPermissions`, which the HasRoles trait alone cannot
 * satisfy. Declaring the contract is how a consumer says "this package may
 * outrank my policies." Both shapes are covered so neither can drift.
 */
class GateUser extends Authenticatable implements HasPermissions
{
    use HasRoles;
    use HasTags;

    protected $table = 'users';

    protected $guarded = [];
}
