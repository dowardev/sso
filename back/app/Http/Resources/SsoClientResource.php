<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SsoClientResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'client_id' => $this->client_id,
            'redirect_url' => $this->redirect_url,
            'allowed_origins' => $this->allowed_origins,
            'grant_types' => $this->grant_types,
            'active' => $this->active,
            'created_at' => $this->created_at,
            'sessions_count' => $this->sessions_count ?? $this->sessions()->count(),
        ];
    }
}
