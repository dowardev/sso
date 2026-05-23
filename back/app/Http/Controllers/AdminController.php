<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Models\SsoClient;
use App\Models\User;
use App\Models\UserSession;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

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

    public function updateUser(Request $request, User $user)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'user' => 'sometimes|string|max:255|unique:users,user,'.$user->id,
            'email' => 'sometimes|string|email|max:255|unique:users,email,'.$user->id,
            'password' => 'sometimes|string|min:6|confirmed',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Error de validación',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $request->only(['name', 'user', 'email']);

        if ($request->has('password')) {
            $data['password'] = Hash::make($request->password);
        }

        $user->update($data);

        return response()->json([
            'message' => 'Usuario actualizado correctamente',
            'user' => $user,
        ]);
    }

    public function destroyUser(User $user, Request $request)
    {
        if ($user->id === $request->user()->id) {
            return response()->json([
                'message' => 'No puedes eliminar tu propio usuario',
            ], 422);
        }

        $user->tokens()->delete();
        $user->sessions()->delete();
        $user->delete();

        return response()->json([
            'message' => 'Usuario eliminado correctamente',
        ]);
    }

    public function toggle(Request $request, int $id): Response
    {
        $request->validate([
            'action' => 'required|in:assign,remove',
            'sso_client_id' => 'nullable|integer',
        ]);

        $user = User::findOrFail($id);
        $action = $request->input('action');
        $ssoClientId = $request->input('sso_client_id');
        $adminRole = Role::where('slug', 'admin')->firstOrFail();

        if ($action === 'assign') {
            $user->roles()->attach($adminRole->id, ['sso_client_id' => $ssoClientId]);
        } else {
            $user->roles()->where('role_id', $adminRole->id)
                ->where('sso_client_id', $ssoClientId ?? null)
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
            'message' => 'Sesión revocada correctamente',
        ]);
    }

    public function destroyUserSessions(User $user, Request $request)
    {
        if ($user->id === $request->user()->id) {
            return response()->json([
                'message' => 'No puedes revocar tus propias sesiones desde admin',
            ], 422);
        }

        $user->sessions()->with('token')->get()->each(function ($session) {
            if ($session->token) {
                $session->token->delete();
            }
        });

        $user->sessions()->delete();

        return response()->json([
            'message' => 'Todas las sesiones del usuario han sido revocadas',
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

    public function storeClient(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'client_id' => 'required|string|max:255|unique:sso_clients',
            'client_secret' => 'nullable|string|max:255',
            'redirect_url' => 'nullable|string|max:255|url',
            'allowed_origins' => 'nullable|array',
            'allowed_origins.*' => ['regex:/^https?:\/\/[^\/:]+(:\d+)?$/'],
            'grant_types' => 'nullable|array',
            'grant_types.*' => 'string|in:authorization_code,client_credentials',
            'active' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Error de validación',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();

        if (!isset($data['grant_types'])) {
            $data['grant_types'] = ['authorization_code'];
        }

        $client = SsoClient::create($data);

        return response()->json([
            'message' => 'Cliente SSO creado correctamente',
            'client' => $client,
        ], 201);
    }

    public function updateClient(Request $request, SsoClient $client)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'client_id' => 'sometimes|string|max:255|unique:sso_clients,client_id,'.$client->id,
            'client_secret' => 'nullable|string|max:255',
            'redirect_url' => 'nullable|string|max:255|url',
            'allowed_origins' => 'nullable|array',
            'allowed_origins.*' => ['regex:/^https?:\/\/[^\/:]+(:\d+)?$/'],
            'grant_types' => 'nullable|array',
            'grant_types.*' => 'string|in:authorization_code,client_credentials',
            'active' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Error de validación',
                'errors' => $validator->errors(),
            ], 422);
        }

        $client->update($validator->validated());

        return response()->json([
            'message' => 'Cliente SSO actualizado correctamente',
            'client' => $client,
        ]);
    }

    public function destroyClient(SsoClient $client)
    {
        $client->sessions()->update(['sso_client_id' => null]);
        $client->delete();

        return response()->json([
            'message' => 'Cliente SSO eliminado correctamente',
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

    public function storeRole(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'slug' => 'required|string|max:255|unique:roles',
            'description' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Error de validación',
                'errors' => $validator->errors(),
            ], 422);
        }

        $role = Role::create([
            'name' => $request->name,
            'slug' => $request->slug,
            'description' => $request->description,
            'is_protected' => false,
        ]);

        return response()->json([
            'message' => 'Rol creado correctamente',
            'role' => $role,
        ], 201);
    }

    public function updateRole(Request $request, Role $role)
    {
        if ($role->is_protected) {
            return response()->json([
                'message' => 'No puedes modificar un rol protegido',
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Error de validación',
                'errors' => $validator->errors(),
            ], 422);
        }

        $role->update($request->only(['name', 'description']));

        return response()->json([
            'message' => 'Rol actualizado correctamente',
            'role' => $role,
        ]);
    }

    public function destroyRole(Role $role)
    {
        if ($role->is_protected) {
            return response()->json([
                'message' => 'No puedes eliminar un rol protegido',
            ], 422);
        }

        if ($role->users()->count() > 0) {
            return response()->json([
                'message' => 'No puedes eliminar un rol que tiene usuarios asignados',
            ], 422);
        }

        $role->delete();

        return response()->json([
            'message' => 'Rol eliminado correctamente',
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

    public function updateUserRoles(Request $request, User $user)
    {
        $validator = Validator::make($request->all(), [
            'roles' => 'required|array',
            'roles.*.role_id' => 'required|exists:roles,id',
            'roles.*.sso_client_id' => 'nullable|exists:sso_clients,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Error de validación',
                'errors' => $validator->errors(),
            ], 422);
        }

        $user->roles()->detach();

        foreach ($request->roles as $roleData) {
            $user->roles()->attach($roleData['role_id'], [
                'sso_client_id' => $roleData['sso_client_id'] ?? null,
            ]);
        }

        return response()->json([
            'message' => 'Roles actualizados correctamente',
            'roles' => $this->indexUserRoles($user)->getData(true),
        ]);
    }
}
