<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Notifications\NotificationAudienceService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class NotificationRecipientOptionController extends Controller
{
    public function __invoke(Request $request, NotificationAudienceService $audiences): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'role' => ['nullable', Rule::in(['all', 'customers', 'families', 'drivers', 'admins'])],
            'per_page' => ['nullable', 'integer', 'between:1,50'],
        ]);
        $role = $validated['role'] ?? 'all';
        $query = $audiences->userQuery($role)
            ->with(['appProfile:id,user_id,customer_id,productive_family_id,driver_id,active_mode', 'roles:id'])
            ->select(['id', 'name', 'name_ar', 'name_en', 'is_platform_owner'])
            ->orderBy('id');

        if ($search = trim((string) ($validated['search'] ?? ''))) {
            $query->where(fn (Builder $builder) => $builder
                ->where('name', 'like', "%{$search}%")
                ->orWhere('name_ar', 'like', "%{$search}%")
                ->orWhere('name_en', 'like', "%{$search}%"));
        }

        $paginator = $query->paginate((int) ($validated['per_page'] ?? 20));

        return response()->json([
            'data' => collect($paginator->items())->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->displayName('ar'),
                'role' => $audiences->roleForUser($user, $role),
            ])->values(),
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'total' => $paginator->total(),
        ]);
    }
}
