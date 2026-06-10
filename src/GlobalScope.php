<?php

namespace RobinsonRyan\Permixion;

use RobinsonRyan\Taxon\Contracts\Scope;

/**
 * Explicit "no scope" sentinel.
 *
 * The HasRoles trait treats a null scope argument as "resolve the current
 * scope" (route/session/callback resolver). That makes it impossible to
 * express "operate on the unscoped/global assignment" while a scope is
 * resolvable. Pass GlobalScope::instance() to target unscoped assignments
 * explicitly:
 *
 *     $user->assignRole('owner', GlobalScope::instance());   // global row
 *     $user->hasRole('owner', GlobalScope::instance());      // global rows only
 *
 * GlobalScope is never persisted; pivot rows it targets have NULL
 * scope_type / scope_id. Reads against it never apply the
 * teams.global_fallback widening (they are already global-only).
 */
final class GlobalScope implements Scope
{
    private static ?self $instance = null;

    private function __construct() {}

    public static function instance(): self
    {
        return self::$instance ??= new self;
    }

    public function getScopeType(): string
    {
        return '';
    }

    public function getScopeId(): string
    {
        return '';
    }
}
