<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace App\Services;

use App\Models\User;
use App\Models\UserActivityLog;
use Illuminate\Http\Request;

class ActivityLogService
{
    public function log(
        User $user,
        string $action,
        ?string $description = null,
        ?array $metadata = [],
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): void {
        UserActivityLog::create([
            'user_id' => $user->id,
            'action' => $action,
            'description' => $description,
            'metadata' => $metadata,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
        ]);
    }

    public function logFromRequest(
        User $user,
        string $action,
        ?string $description = null,
        ?array $metadata = [],
        ?Request $request = null,
    ): void {
        $this->log(
            $user,
            $action,
            $description,
            $metadata,
            $request?->ip(),
            $request?->userAgent(),
        );
    }
}
