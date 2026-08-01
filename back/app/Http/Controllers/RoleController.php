<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace App\Http\Controllers;

use App\Http\Requests\StoreRoleRequest;
use App\Http\Requests\UpdateRoleRequest;
use App\Http\Resources\RoleResource;
use App\Models\Role;
use App\Services\RoleService;
use Illuminate\Http\JsonResponse;

class RoleController extends Controller
{
    private RoleService $roleService;

    public function __construct(RoleService $roleService)
    {
        $this->roleService = $roleService;
    }

    public function index(): JsonResponse
    {
        $roles = $this->roleService->publicList();

        return response()->json(['data' => $roles]);
    }

    public function adminIndex(): JsonResponse
    {
        $roles = $this->roleService->all();

        return response()->json(RoleResource::collection($roles));
    }

    public function show(Role $role): JsonResponse
    {
        return response()->json(new RoleResource($role));
    }

    public function store(StoreRoleRequest $request): JsonResponse
    {
        $role = $this->roleService->create($request->validated());

        return response()->json([
            'message' => 'Rol creado correctamente',
            'role' => new RoleResource($role),
        ], 201);
    }

    public function update(UpdateRoleRequest $request, Role $role): JsonResponse
    {
        if ($this->roleService->isProtected($role)) {
            return response()->json([
                'message' => 'No puedes modificar un rol protegido',
            ], 422);
        }

        $role = $this->roleService->update($role, $request->validated());

        return response()->json([
            'message' => 'Rol actualizado correctamente',
            'role' => new RoleResource($role),
        ]);
    }

    public function destroy(Role $role): JsonResponse
    {
        if ($this->roleService->isProtected($role)) {
            return response()->json([
                'message' => 'No puedes eliminar un rol protegido',
            ], 422);
        }

        if ($this->roleService->hasUsers($role)) {
            return response()->json([
                'message' => 'No puedes eliminar un rol que tiene usuarios asignados',
            ], 422);
        }

        $this->roleService->delete($role);

        return response()->json([
            'message' => 'Rol eliminado correctamente',
        ]);
    }
}
