<?php

declare(strict_types=1);

namespace RobinsonRyan\Permixion\Tests\Fixtures;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use RobinsonRyan\Permixion\Contracts\CanBeInactive;
use RobinsonRyan\Permixion\Traits\HasRoles;
use RobinsonRyan\Taxon\HasTags;

/**
 * An accounting firm's grant on a client: the holder a firm staffer's assignment acts through, carrying the granted role.
 *
 * @property string $name
 * @property Carbon|null $revoked_at
 */
class AccessGrant extends Model implements Authorizable, CanBeInactive
{
    use HasRoles;
    use HasTags;

    protected $table = 'access_grants';

    protected $guarded = [];

    protected $casts = ['revoked_at' => 'datetime'];

    public function isActiveHolder(): bool
    {
        return $this->revoked_at === null;
    }
}
