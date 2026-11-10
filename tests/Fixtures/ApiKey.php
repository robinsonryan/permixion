<?php

declare(strict_types=1);

namespace RobinsonRyan\Permixion\Tests\Fixtures;

use Illuminate\Contracts\Auth\Access\Authorizable as AuthorizableContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\Access\Authorizable;
use Illuminate\Support\Carbon;
use RobinsonRyan\Permixion\Contracts\CanBeInactive;
use RobinsonRyan\Permixion\Contracts\HasPermissions;
use RobinsonRyan\Permixion\Traits\HasRoles;
use RobinsonRyan\Taxon\HasTags;

/**
 * A non-user holder: an app's API key, acting for the app it belongs to.
 *
 * Unlike {@see Agent} it also mixes in the framework's Authorizable trait, the
 * shape a consumer reaches for first. Both traits define `can()`, so the
 * consumer must say which wins; HasRoles' must, or the acting-for ceiling is
 * bypassed by every `$key->can()`.
 *
 * @property string $name
 * @property Carbon|null $revoked_at
 */
class ApiKey extends Model implements AuthorizableContract, CanBeInactive, HasPermissions
{
    use Authorizable, HasRoles {
        HasRoles::can insteadof Authorizable;
    }
    use HasTags;

    protected $guarded = [];

    protected $casts = ['revoked_at' => 'datetime'];

    public function isActiveHolder(): bool
    {
        return $this->revoked_at === null;
    }
}
