<?php

declare(strict_types=1);

namespace RobinsonRyan\Permixion\Contracts;

/**
 * A holder that can be switched off without deleting its assignments — a
 * revoked API key, a revoked Agent, a suspended app.
 *
 * An inactive holder has no permissions at all, whatever it is assigned (super
 * admin included), and anything assigned *through* it is capped to nothing.
 * Its role assignments stay in place, so re-activating it restores them.
 */
interface CanBeInactive
{
    public function isActiveHolder(): bool;
}
