<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;

class ApiUsersController extends Controller
{
    public function index(Request $request)
    {
        $query = User::select('id', 'name', 'user', 'email');

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('user', 'like', "%{$search}%");
            });
        }

        $users = $query->orderBy('name', 'asc')
            ->paginate($request->get('per_page', 50));

        return response()->json($users);
    }
}
