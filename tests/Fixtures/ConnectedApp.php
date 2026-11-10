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
 * A connected app: the holder an API key acts for, carrying the app's ceiling role.
 *
 * @property string $name
 * @property Carbon|null $revoked_at
 */
class ConnectedApp extends Model implements Authorizable, CanBeInactive
{
    use HasRoles;
    use HasTags;

    protected $table = 'connected_apps';

    protected $guarded = [];

    protected $casts = ['revoked_at' => 'datetime'];

    public function isActiveHolder(): bool
    {
        return $this->revoked_at === null;
    }
}
