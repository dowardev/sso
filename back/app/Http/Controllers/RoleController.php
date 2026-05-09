<?php

namespace App\Http\Controllers;

use App\Models\Role;
use Illuminate\Http\JsonResponse;

class RoleController extends Controller
{
    public function index(): JsonResponse
    {
        $roles = Role::orderBy('is_protected', 'desc')
            ->orderBy('name', 'asc')
            ->get(['id', 'name', 'slug', 'is_protected']);

        return response()->json(['data' => $roles]);
    }
}