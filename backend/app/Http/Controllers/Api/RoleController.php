<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleController extends Controller
{
    public function index()
    {
        return response()->json(Role::with('permissions')->get());
    }

    public function store(Request $request)
    {
        $data = $request->validate(['name' => 'required|string', 'slug' => 'required|string', 'description' => 'nullable|string']);
        $data['organization_id'] = $request->user()->organization_id;
        return response()->json(Role::create($data), 201);
    }

    public function updatePermissions(Request $request, Role $role)
    {
        $data = $request->validate(['permissions' => 'required|array', 'permissions.*' => 'exists:permissions,id']);
        $role->syncPermissions($data['permissions']);
        return response()->json($role->load('permissions'));
    }

    public function allPermissions()
    {
        return response()->json(Permission::all());
    }
}
