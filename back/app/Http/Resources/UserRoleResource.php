<?php
// SPDX-License-Identifier: AGPL-3.0-only

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserRoleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'sso_client_id' => $this->pivot->sso_client_id ?? null,
            'sso_client_name' => $this->when(
                $this->pivot->sso_client_id,
                fn() => \App\Models\SsoClient::find($this->pivot->sso_client_id)?->name
            ),
        ];
    }
}
