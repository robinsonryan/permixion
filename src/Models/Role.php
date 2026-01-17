<?php

namespace RobinsonRyan\Permixion\Models;

use Illuminate\Support\Collection;
use RobinsonRyan\Taxon\Models\Tag;

class Role
{
    public readonly string $name;

    public readonly string $slug;

    public function __construct(
        protected Tag $tag
    ) {
        $this->name = $tag->name;
        $this->slug = $tag->slug;
    }

    public function tag(): Tag
    {
        return $this->tag;
    }

    /*
    |--------------------------------------------------------------------------
    | Permission Management
    |--------------------------------------------------------------------------
    */

    public function givePermissionTo(string|array $permissions): static
    {
        $permissions = is_array($permissions) ? $permissions : [$permissions];

        foreach ($permissions as $permission) {
            $permissionTag = app('permixion')->findPermission($permission)?->tag();

            if ($permissionTag) {
                $this->tag->tag($permissionTag->slug);
            } else {
                // Auto-create permission if strict mode is off
                if (! config('permixion.strict')) {
                    $permissionObj = app('permixion')->createPermission($permission);
                    $this->tag->tag($permissionObj->slug);
                }
            }
        }

        app('permixion')->clearCache();

        return $this;
    }

    public function revokePermissionTo(string|array $permissions): static
    {
        $permissions = is_array($permissions) ? $permissions : [$permissions];

        foreach ($permissions as $permission) {
            $this->tag->untag($permission);
        }

        app('permixion')->clearCache();

        return $this;
    }

    public function syncPermissions(array $permissions): static
    {
        // Remove all current permissions
        $permissionsCategory = app('permixion')->permissionsCategory();
        $currentPermissions = $this->tag->tags()
            ->where('parent_id', $permissionsCategory->id)
            ->pluck('slug')
            ->all();

        $this->tag->untag($currentPermissions);

        // Add new permissions
        $this->givePermissionTo($permissions);

        return $this;
    }

    public function hasPermissionTo(string $permission): bool
    {
        $permissions = app('permixion')->getPermissionsForRole($this->slug);

        // Exact match
        if (in_array($permission, $permissions, true)) {
            return true;
        }

        // Wildcard match
        foreach ($permissions as $rolePermission) {
            if (app('permixion')->permissionMatches($permission, $rolePermission)) {
                return true;
            }
        }

        return false;
    }

    public function getPermissions(): Collection
    {
        $permissionsCategory = app('permixion')->permissionsCategory();

        return $this->tag->tags()
            ->where('parent_id', $permissionsCategory->id)
            ->get()
            ->map(fn (Tag $tag) => new Permission($tag));
    }

    public function getPermissionNames(): array
    {
        return app('permixion')->getPermissionsForRole($this->slug);
    }
}
