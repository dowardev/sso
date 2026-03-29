<?php

namespace App\Http\Controllers;

use App\Models\SsoClient;
use App\Models\User;
use App\Models\UserSession;
use Illuminate\Http\Request;
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
            'is_admin' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Error de validación',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $request->only(['name', 'user', 'email', 'is_admin']);

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

    public function toggleUser(User $user, Request $request)
    {
        if ($user->id === $request->user()->id) {
            return response()->json([
                'message' => 'No puedes desactivar tu propio usuario',
            ], 422);
        }

        $user->update(['is_admin' => ! $user->is_admin]);

        return response()->json([
            'message' => $user->is_admin ? 'Usuario promovido a admin' : 'Permisos de admin revocados',
            'user' => $user,
        ]);
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
            'active' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Error de validación',
                'errors' => $validator->errors(),
            ], 422);
        }

        $client = SsoClient::create($request->all());

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
            'active' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Error de validación',
                'errors' => $validator->errors(),
            ], 422);
        }

        $client->update($request->all());

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
}
