<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\JobTitle;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Http\JsonResponse;

class SystemDictionaryController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['departments' => Department::where('is_active', true)->orderBy('sort_order')->get(), 'jobTitles' => JobTitle::where('is_active', true)->orderByDesc('level')->get(), 'roles' => Role::where('is_active', true)->orderByDesc('priority')->get(), 'permissions' => Permission::where('is_active', true)->orderBy('module')->orderBy('action')->get()]);
    }
}
