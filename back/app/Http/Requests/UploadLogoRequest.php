<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UploadLogoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'image' => ['required', 'file', 'mimes:png,jpg,jpeg,svg,webp', 'max:2048'],
        ];
    }
}
