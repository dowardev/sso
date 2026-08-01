<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CspReport extends Model
{
    protected $fillable = [
        'blocked_uri',
        'document_uri',
        'referrer',
        'violated_directive',
        'effective_directive',
        'original_policy',
        'source_file',
        'line_number',
        'script_sample',
        'resolved_at',
    ];

    protected $casts = [
        'line_number' => 'integer',
        'resolved_at' => 'datetime',
    ];
}
