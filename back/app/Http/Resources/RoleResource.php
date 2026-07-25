<?php
// SPDX-License-Identifier: AGPL-3.0-only

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RoleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'is_protected' => $this->is_protected,
            'created_at' => $this->created_at,
            'users_count' => $this->users_count ?? $this->users()->count(),
        ];
    }
}
