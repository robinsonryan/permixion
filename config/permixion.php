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
    */
    'register_gate' => true,

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
