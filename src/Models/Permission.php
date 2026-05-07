<?php

namespace RobinsonRyan\Permixion\Models;

use Illuminate\Support\Collection;
use RobinsonRyan\Taxon\Models\Tag;

class Permission
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
    | Role Management
    |--------------------------------------------------------------------------
    */

    public function assignToRole(string|Role $role): static
    {
        $roleObj = $role instanceof Role ? $role : app('permixion')->findRoleOrFail($role);
        $roleObj->givePermissionTo($this->name);

        return $this;
    }

    public function removeFromRole(string|Role $role): static
    {
        $roleObj = $role instanceof Role ? $role : app('permixion')->findRoleOrFail($role);
        $roleObj->revokePermissionTo($this->name);

        return $this;
    }

    public function getRoles(): Collection
    {
        $rolesCategory = app('permixion')->rolesCategory();

        // Find all role tags that have this permission tagged
        return Tag::where('parent_id', $rolesCategory->id)
            ->whereHas('tags', fn ($q) => $q->where('id', $this->tag->id))
            ->get()
            ->map(fn (Tag $tag) => new Role($tag));
    }
}
