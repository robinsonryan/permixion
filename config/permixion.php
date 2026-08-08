<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Tag Categories
    |--------------------------------------------------------------------------
    |
    | The slugs used for roles and permissions categories in Taxon.
    |
    */
    'categories' => [
        'roles' => 'roles',
        'permissions' => 'permissions',
    ],

    /*
    |--------------------------------------------------------------------------
    | Teams / Scoping
    |--------------------------------------------------------------------------
    |
    | Enable team-based permissions where users can have different roles
    | on different teams.
    |
    */
    'teams' => [
        'enabled' => false,

        // If using a Team model as scope
        'model' => null, // e.g., App\Models\Team::class

        // If using tags as teams (set to category slug)
        'tag_category' => null, // e.g., 'teams'

        // How to resolve current team context
        // Options: 'route', 'session', 'callback'
        'resolver' => 'route',

        // Route parameter name (when resolver is 'route')
        'route_parameter' => 'team',

        // Session key (when resolver is 'session')
        'session_key' => 'current_team_id',

        // Custom resolver callback (when resolver is 'callback')
        'callback' => null, // e.g., fn() => app('current.team')

        // When true, role/permission READ checks against a specific scope
        // also match unscoped (global) assignments — i.e. a role assigned
        // with no scope applies everywhere, matching spatie/laravel-permission
        // teams semantics. Writes (assign/remove/sync) always target the
        // exact scope. Pass GlobalScope::instance() to read global rows only.
        'global_fallback' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Super Admin
    |--------------------------------------------------------------------------
    |
    | If enabled, users with this role bypass all permission checks.
    |
    */
    'super_admin' => [
        'enabled' => true,
        'role' => 'super-admin',
    ],

    /*
    |--------------------------------------------------------------------------
    | Wildcard Permissions
    |--------------------------------------------------------------------------
    |
    | Enable wildcard permission matching (e.g., 'posts.*' matches 'posts.create')
    |
    */
    'wildcards' => [
        'enabled' => true,
        'delimiter' => '.',
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache
    |--------------------------------------------------------------------------
    |
    | Cache role-permission mappings for performance.
    |
    */
    'cache' => [
        'enabled' => true,
        'ttl' => 3600, // seconds
        'key_prefix' => 'permixion',
        'store' => null, // null uses default cache store
    ],

    /*
    |--------------------------------------------------------------------------
    | Register Gate Permissions
    |--------------------------------------------------------------------------
    |
    | Automatically register permissions with Laravel's Gate.
    |
    | NOTE: this installs a Gate::before hook, which Laravel runs AHEAD of every
    | policy. The hook only acts on users that explicitly
    | `implements RobinsonRyan\Permixion\Contracts\HasPermissions` — using the
    | HasRoles trait alone is not enough, and is not an oversight: declaring the
    | contract is how a model consents to Permixion outranking its policies.
    |
    */
    'register_gate' => true,

    /*
    |--------------------------------------------------------------------------
    | Gate Behaviour
    |--------------------------------------------------------------------------
    |
    | defer_to_policies
    |
    | A permission is a NAME. It can express "may update posts at all"; it can
    | never express "may update *this* post". When a caller passes a subject —
    | `can('update', $post)`, `Gate::authorize('update', $post)` — the question
    | belongs to a policy, because only the policy can see which record it is.
    |
    | false (default) matches spatie/laravel-permission: holding a permission
    |       whose name equals the ability short-circuits the policy entirely,
    |       including any ownership or tenancy clause it contains.
    |
    | true  declines those questions and lets the policy answer. Turn this on if
    |       your policies discriminate between instances of the same model.
    |
    | This can only ever REMOVE grants, never add them, so the failure mode is a
    | visible 403 rather than silent over-permission. Before enabling it, confirm
    | every model you authorize actually has a policy — abilities that relied on
    | the short-circuit will now be decided by the policy, or denied if none
    | exists. Super admin is unaffected either way.
    |
    */
    'gate' => [
        'defer_to_policies' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | User Model
    |--------------------------------------------------------------------------
    */
    'user_model' => 'App\\Models\\User',

    /*
    |--------------------------------------------------------------------------
    | Exceptions
    |--------------------------------------------------------------------------
    |
    | Throw exceptions when roles/permissions don't exist.
    | If false, methods return false/null instead.
    |
    */
    'strict' => true,
];
