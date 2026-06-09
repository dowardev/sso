<?php

namespace App\Services;

use App\Models\Role;
use Illuminate\Database\Eloquent\Collection;

class RoleService
{
    public function all(): Collection
    {
        return Role::withCount('users')
            ->orderBy('is_protected', 'desc')
            ->orderBy('name')
            ->get();
    }

    public function publicList(): Collection
    {
        return Role::orderBy('is_protected', 'desc')
            ->orderBy('name', 'asc')
            ->get(['id', 'name', 'slug', 'is_protected']);
    }

    public function create(array $data): Role
    {
        return Role::create([
            'name' => $data['name'],
            'slug' => $data['slug'],
            'description' => $data['description'] ?? null,
            'is_protected' => false,
        ]);
    }

    public function update(Role $role, array $data): Role
    {
        $role->update($data);
        return $role;
    }

    public function delete(Role $role): void
    {
        $role->delete();
    }

    public function isProtected(Role $role): bool
    {
        return $role->is_protected;
    }

    public function hasUsers(Role $role): bool
    {
        return $role->users()->count() > 0;
    }
}
