<?php

namespace App\Services;

use App\Models\Role;
use App\Models\SsoClient;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class UserService
{
    public function paginate(Request $request): LengthAwarePaginator
    {
        $query = User::withCount(['sessions', 'tokens'])
            ->with('roles');

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('user', 'like', "%{$search}%");
            });
        }

        if ($request->has('sso_client_id')) {
            $clientId = $request->get('sso_client_id');

            $resolvedId = is_numeric($clientId)
                ? (int) $clientId
                : SsoClient::where('client_id', $clientId)->value('id');

            if ($resolvedId) {
                $query->whereHas('oauthClientAuthorizations', function ($q) use ($resolvedId) {
                    $q->where('sso_client_id', $resolvedId)
                        ->where('status', 'authorized');
                });
            } else {
                $query->whereRaw('1 = 0');
            }
        }

        return $query->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 15));
    }

    public function findWithSessions(User $user): User
    {
        $user->load(['sessions.client', 'sessions.token']);
        $user->tokens_count = $user->tokens()->count();
        $user->active_tokens_count = $user->activeTokens()->count();
        return $user;
    }

    public function update(User $user, array $data): User
    {
        if (isset($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        }

        $user->update($data);
        return $user;
    }

    public function delete(User $user): void
    {
        $user->tokens()->delete();
        $user->sessions()->delete();
        $user->delete();
    }

    public function toggleAdmin(User $user, string $action, ?int $ssoClientId = null): void
    {
        $adminRole = Role::where('slug', 'admin')->firstOrFail();

        if ($action === 'assign') {
            $user->roles()->attach($adminRole->id, ['sso_client_id' => $ssoClientId]);
        } else {
            $user->roles()->where('role_id', $adminRole->id)
                ->where('sso_client_id', $ssoClientId ?? null)
                ->detach();
        }
    }

    public function searchForApi(Request $request): LengthAwarePaginator
    {
        $query = User::select('id', 'name', 'user', 'email');

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('user', 'like', "%{$search}%");
            });
        }

        return $query->orderBy('name', 'asc')
            ->paginate($request->get('per_page', 50));
    }

    public function getUserRoles(User $user): array
    {
        return $user->roles()
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
            })
            ->toArray();
    }

    public function updateUserRoles(User $user, array $roles): array
    {
        $user->roles()->detach();

        foreach ($roles as $roleData) {
            $user->roles()->attach($roleData['role_id'], [
                'sso_client_id' => $roleData['sso_client_id'] ?? null,
            ]);
        }

        return $this->getUserRoles($user);
    }
}
