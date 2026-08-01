<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserSessionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'ip_address' => $this->ip_address,
            'user_agent' => $this->user_agent,
            'last_used_at' => $this->last_used_at,
            'created_at' => $this->created_at,
            'user' => $this->whenLoaded('user', function () {
                return [
                    'id' => $this->user->id,
                    'name' => $this->user->name,
                    'user' => $this->user->user,
                    'email' => $this->user->email,
                ];
            }),
            'client' => $this->whenLoaded('client', function () {
                return [
                    'id' => $this->client->id,
                    'name' => $this->client->name,
                    'client_id' => $this->client->client_id,
                ];
            }),
            'token_active' => $this->whenLoaded('token', function () {
                return $this->token &&
                    ($this->token->expires_at === null || $this->token->expires_at->isFuture());
            }) ?? false,
        ];
    }
}
