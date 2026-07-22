<?php
// SPDX-License-Identifier: AGPL-3.0-only

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'user' => $this->user,
            'email' => $this->email,
            'is_admin' => $this->is_admin,
            'created_at' => $this->created_at,
            'sessions_count' => $this->sessions_count ?? $this->sessions()->count(),
            'tokens_count' => $this->tokens_count ?? $this->tokens()->count(),
            'roles' => RoleResource::collection($this->whenLoaded('roles')),
        ];
    }
}
