<?php
// SPDX-License-Identifier: AGPL-3.0-only

namespace App\Http\Controllers;

use App\Http\Requests\UpdateUserRequest;
use App\Http\Resources\UserDetailResource;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\UserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserController extends Controller
{
    private UserService $userService;

    public function __construct(UserService $userService)
    {
        $this->userService = $userService;
    }

    public function index(Request $request): JsonResponse
    {
        $users = $this->userService->paginate($request);

        $users->through(fn(User $user) => [
            'id' => $user->id,
            'name' => $user->name,
            'user' => $user->user,
            'email' => $user->email,
            'is_admin' => $user->is_admin,
            'created_at' => $user->created_at,
            'sessions_count' => $user->sessions_count,
            'tokens_count' => $user->tokens_count,
            'roles' => $user->relationLoaded('roles')
                ? $user->roles->toArray()
                : [],
        ]);

        return response()->json($users);
    }

    public function show(User $user): JsonResponse
    {
        $user = $this->userService->findWithSessions($user);
        return response()->json(new UserDetailResource($user));
    }

    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $this->userService->update($user, $request->validated());
        return response()->json([
            'message' => 'Usuario actualizado correctamente',
            'user' => new UserResource($user),
        ]);
    }

    public function destroy(User $user, Request $request): JsonResponse
    {
        $authUser = $request->user();

        if ($user->id === $authUser->id) {
            return response()->json([
                'message' => 'No puedes eliminar tu propio usuario',
            ], 422);
        }

        $this->userService->delete($user);

        return response()->json([
            'message' => 'Usuario eliminado correctamente',
        ]);
    }

    public function toggleAdmin(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'action' => 'required|in:assign,remove',
            'sso_client_id' => 'nullable|integer',
        ]);

        $user = User::findOrFail($id);
        $this->userService->toggleAdmin(
            $user,
            $request->input('action'),
            $request->input('sso_client_id')
        );

        $user->refresh();

        return response()->json(['is_admin' => $user->is_admin]);
    }

    public function indexRoles(User $user): JsonResponse
    {
        $roles = $this->userService->getUserRoles($user);
        return response()->json($roles);
    }

    public function updateRoles(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'roles' => 'required|array',
            'roles.*.role_id' => 'required|exists:roles,id',
            'roles.*.sso_client_id' => 'nullable|exists:sso_clients,id',
        ]);

        $roles = $this->userService->updateUserRoles($user, $validated['roles']);

        return response()->json([
            'message' => 'Roles actualizados correctamente',
            'roles' => $roles,
        ]);
    }

    public function apiIndex(Request $request): JsonResponse
    {
        $users = $this->userService->searchForApi($request);
        return response()->json($users);
    }
}
