<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Models\SsoClient;
use App\Models\User;
use App\Models\UserSession;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;

use App\Http\Requests\Admin\UpdateUserRequest;
use App\Http\Requests\Admin\StoreClientRequest;
use App\Http\Requests\Admin\UpdateClientRequest;
use App\Http\Requests\Admin\StoreRoleRequest;
use App\Http\Requests\Admin\UpdateRoleRequest;
use App\Http\Requests\Admin\UpdateUserRolesRequest;
use App\Http\Requests\Admin\ToggleUserRequest;

class AdminController extends Controller
{
    // ==================== USUARIOS ====================

    public function indexUsers(Request $request)
    {
        $query = User::withCount(['sessions', 'tokens']);

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('user', 'like', "%{$search}%");
            });
        }

        $users = $query->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 15));

        return response()->json($users);
    }

    public function showUser(User $user)
    {
        $user->load(['sessions.client', 'sessions.token']);

        $user->tokens_count = $user->tokens()->count();
        $user->active_tokens_count = $user->activeTokens()->count();

        return response()->json($user);
    }

    public function updateUser(UpdateUserRequest $request, User $user)
    {
        $validated = $request->validated();

        $data = [];
        if (isset($validated['name'])) $data['name'] = $validated['name'];
        if (isset($validated['user'])) $data['user'] = $validated['user'];
        if (isset($validated['email'])) $data['email'] = $validated['email'];
        if (isset($validated['password'])) {
            $data['password'] = Hash::make($validated['password']);
        }

        $user->update($data);

        return response()->json([
            'message' => 'User updated successfully',
            'user' => $user,
        ]);
    }

    public function destroyUser(User $user, Request $request)
    {
        if ($user->id === $request->user()->id) {
            return response()->json([
                'message' => 'You cannot delete your own user',
            ], 422);
        }

        $user->tokens()->delete();
        $user->sessions()->delete();
        $user->delete();

        return response()->json([
            'message' => 'User deleted successfully',
        ]);
    }

    public function toggle(ToggleUserRequest $request, int $id): Response
    {
        $validated = $request->validated();

        $user = User::findOrFail($id);
        $action = $validated['action'];
        $ssoClientId = $validated['sso_client_id'] ?? null;
        $adminRole = Role::where('slug', 'admin')->firstOrFail();

        if ($action === 'assign') {
            $user->roles()->attach($adminRole->id, ['sso_client_id' => $ssoClientId]);
        } else {
            $user->roles()->where('role_id', $adminRole->id)
                ->where('sso_client_id', $ssoClientId)
                ->detach();
        }

        return response()->json(['message' => 'Role updated successfully']);
    }

    // ==================== SESIONES ====================

    public function indexSessions(Request $request)
    {
        $query = UserSession::with(['user:id,name,user,email', 'client:id,name,client_id', 'token']);

        if ($request->has('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        if ($request->has('client_id')) {
            $query->where('sso_client_id', $request->client_id);
        }

        if ($request->has('active') && $request->active) {
            $query->active();
        }

        $sessions = $query->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 15));

        $sessions->getCollection()->transform(function ($session) {
            $session->token_active = $session->token &&
                ($session->token->expires_at === null || $session->token->expires_at->isFuture());

            return $session;
        });

        return response()->json($sessions);
    }

    public function userSessions(User $user)
    {
        $sessions = $user->sessions()
            ->with(['client:id,name,client_id', 'token'])
            ->orderBy('created_at', 'desc')
            ->get();

        $sessions->transform(function ($session) {
            $session->token_active = $session->token &&
                ($session->token->expires_at === null || $session->token->expires_at->isFuture());

            return $session;
        });

        return response()->json([
            'user' => $user->only(['id', 'name', 'user', 'email']),
            'sessions' => $sessions,
            'total' => $sessions->count(),
            'active' => $sessions->where('token_active', true)->count(),
        ]);
    }

    public function destroySession(UserSession $session)
    {
        if ($session->token) {
            $session->token->delete();
        }

        $session->delete();

        return response()->json([
            'message' => 'Session revoked successfully',
        ]);
    }

    public function destroyUserSessions(User $user, Request $request)
    {
        if ($user->id === $request->user()->id) {
            return response()->json([
                'message' => 'You cannot revoke your own sessions from admin',
            ], 422);
        }

        $user->sessions()->with('token')->get()->each(function ($session) {
            if ($session->token) {
                $session->token->delete();
            }
        });

        $user->sessions()->delete();

        return response()->json([
            'message' => 'All user sessions have been revoked',
        ]);
    }

    // ==================== CLIENTES SSO ====================

    public function indexClients()
    {
        $clients = SsoClient::withCount('sessions')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($clients);
    }

    public function storeClient(StoreClientRequest $request)
    {
        $data = $request->validated();

        if (!isset($data['grant_types'])) {
            $data['grant_types'] = ['authorization_code'];
        }

        $client = SsoClient::create($data);

        return response()->json([
            'message' => 'SSO client created successfully',
            'client' => $client,
        ], 201);
    }

    public function updateClient(UpdateClientRequest $request, SsoClient $client)
    {
        $client->update($request->validated());

        return response()->json([
            'message' => 'SSO client updated successfully',
            'client' => $client,
        ]);
    }

    public function destroyClient(SsoClient $client)
    {
        $client->sessions()->update(['sso_client_id' => null]);
        $client->delete();

        return response()->json([
            'message' => 'SSO client deleted successfully',
        ]);
    }

    // ==================== ROLES ====================

    public function indexRoles()
    {
        $roles = Role::withCount('users')
            ->orderBy('is_protected', 'desc')
            ->orderBy('name')
            ->get();

        return response()->json($roles);
    }

    public function storeRole(StoreRoleRequest $request)
    {
        $validated = $request->validated();

        $role = Role::create([
            'name' => $validated['name'],
            'slug' => $validated['slug'],
            'description' => $validated['description'] ?? null,
            'is_protected' => false,
        ]);

        return response()->json([
            'message' => 'Role created successfully',
            'role' => $role,
        ], 201);
    }

    public function updateRole(UpdateRoleRequest $request, Role $role)
    {
        if ($role->is_protected) {
            return response()->json([
                'message' => 'You cannot modify a protected role',
            ], 422);
        }

        $role->update($request->validated());

        return response()->json([
            'message' => 'Role updated successfully',
            'role' => $role,
        ]);
    }

    public function destroyRole(Role $role)
    {
        if ($role->is_protected) {
            return response()->json([
                'message' => 'You cannot delete a protected role',
            ], 422);
        }

        if ($role->users()->count() > 0) {
            return response()->json([
                'message' => 'You cannot delete a role that has assigned users',
            ], 422);
        }

        $role->delete();

        return response()->json([
            'message' => 'Role deleted successfully',
        ]);
    }

    public function indexUserRoles(User $user)
    {
        $roles = $user->roles()
            ->withPivot('sso_client_id')
            ->get()
            ->map(function ($role) {
                return [
                    'id' => $role->id,
                    'name' => $role->name,
                    'slug' => $role->slug,
                    'description' => $role->description,
                    'sso_client_id' => $role->pivot->sso_client_id,
                    'sso_client_name' => $role->pivot->sso_client_id 
                        ? SsoClient::find($role->pivot->sso_client_id)?->name 
                        : null,
                ];
            });

        return response()->json($roles);
    }

    public function updateUserRoles(UpdateUserRolesRequest $request, User $user)
    {
        $validated = $request->validated();

        $user->roles()->detach();

        foreach ($validated['roles'] as $roleData) {
            $user->roles()->attach($roleData['role_id'], [
                'sso_client_id' => $roleData['sso_client_id'] ?? null,
            ]);
        }

        return response()->json([
            'message' => 'Roles updated successfully',
            'roles' => $this->indexUserRoles($user)->getData(true),
        ]);
    }
}
