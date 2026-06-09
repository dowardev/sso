<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'user' => $this->user,
            'email' => $this->email,
            'is_admin' => $this->is_admin ?? false,
            'created_at' => $this->created_at,
            'sessions_count' => $this->tokens()->count(),
            'active_tokens_count' => $this->activeTokens()->count(),
            'sessions' => UserSessionResource::collection($this->whenLoaded('sessions')),
        ];
    }
}
