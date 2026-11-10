<?php

declare(strict_types=1);

namespace RobinsonRyan\Permixion\Tests\Fixtures;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use RobinsonRyan\Permixion\Contracts\CanBeInactive;
use RobinsonRyan\Permixion\Contracts\HasPermissions;
use RobinsonRyan\Permixion\Traits\HasRoles;
use RobinsonRyan\Taxon\HasTags;

/**
 * A non-user holder: an AI Agent acting for the person who owns it.
 *
 * It is not Authenticatable. It satisfies the framework's Authorizable contract
 * through the `can()` that HasRoles supplies, which is all `Gate::forUser()` and
 * permixion's engine need. Revoking it sets `revoked_at`.
 *
 * @property string $name
 * @property Carbon|null $revoked_at
 */
class Agent extends Model implements Authorizable, CanBeInactive, HasPermissions
{
    use HasRoles;
    use HasTags;

    protected $guarded = [];

    protected $casts = ['revoked_at' => 'datetime'];

    public function isActiveHolder(): bool
    {
        return $this->revoked_at === null;
    }
}
