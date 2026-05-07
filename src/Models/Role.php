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
        $strict = (bool) config('permixion.strict');

        $permissionTagIds = [];

        foreach ($permissions as $permission) {
            $permissionTag = app('permixion')->findPermission($permission)?->tag();

            if (! $permissionTag) {
                if ($strict) {
                    throw new \RobinsonRyan\Permixion\Exceptions\PermissionDoesNotExist($permission);
                }

                $permissionTag = app('permixion')->createPermission($permission)->tag();
            }

            $permissionTagIds[] = $permissionTag->id;
        }

        if (! empty($permissionTagIds)) {
            $this->tag->tags()->syncWithoutDetaching($permissionTagIds);
        }

        app('permixion')->clearCache();

        return $this;
    }

    public function revokePermissionTo(string|array $permissions): static
    {
        $permissions = is_array($permissions) ? $permissions : [$permissions];

        $tagIds = [];
        foreach ($permissions as $permission) {
            $permissionTag = app('permixion')->findPermission($permission)?->tag();
            if ($permissionTag) {
                $tagIds[] = $permissionTag->id;
            }
        }

        if (! empty($tagIds)) {
            $this->tag->tags()->detach($tagIds);
        }

        app('permixion')->clearCache();

        return $this;
    }

    public function syncPermissions(array $permissions): static
    {
        $permissionsCategoryId = app('permixion')->permissionsCategory()->id;

        $currentPermissionTagIds = $this->tag->tags()
            ->where('parent_id', $permissionsCategoryId)
            ->pluck('tags.id')
            ->all();

        if (! empty($currentPermissionTagIds)) {
            $this->tag->tags()->detach($currentPermissionTagIds);
        }

        $this->givePermissionTo($permissions);

        return $this;
    }

    public function hasPermissionTo(string $permission): bool
    {
        $permissions = app('permixion')->getPermissionsForRole($this->name);

        if (in_array($permission, $permissions, true)) {
            return true;
        }

        foreach ($permissions as $rolePermission) {
            if (app('permixion')->permissionMatches($permission, $rolePermission)) {
                return true;
            }
        }

        return false;
    }

    public function getPermissions(): Collection
    {
        $permissionsCategoryId = app('permixion')->permissionsCategory()->id;

        return $this->tag->tags()
            ->where('parent_id', $permissionsCategoryId)
            ->get()
            ->map(fn (Tag $tag) => new Permission($tag));
    }

    /**
     * @return array<int, string>
     */
    public function getPermissionNames(): array
    {
        return app('permixion')->getPermissionsForRole($this->name);
    }
}
