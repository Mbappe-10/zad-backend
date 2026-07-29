<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdatePlatformSettingsRequest;
use App\Models\PlatformSetting;
use App\Models\PlatformSettingAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PlatformSettingsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $defaults = config('zad-settings.defaults', []);

        $stored = PlatformSetting::query()
            ->get()
            ->groupBy('group')
            ->map(fn ($items) => $items->mapWithKeys(
                fn (PlatformSetting $item) => [$item->key => $item->value['value'] ?? null]
            )->all())
            ->all();

        return response()->json([
            'data' => array_replace_recursive($defaults, $stored),
        ]);
    }

    public function meta(Request $request): JsonResponse
    {
        $user = $request->user();
        $permissions = method_exists($user, 'getAllPermissions')
            ? $user->getAllPermissions()->pluck('name')->values()->all()
            : [];

        $isOwner = (bool) ($user->is_owner ?? false);

        return response()->json([
            'meta' => [
                'isOwner' => $isOwner,
                'permissions' => $permissions,
                'canAccessMasterCenter' => $isOwner
                    || in_array('master_settings.access', $permissions, true),
            ],
        ]);
    }

    public function update(UpdatePlatformSettingsRequest $request): JsonResponse
    {
        $settings = $request->validated('settings');
        $user = $request->user();

        DB::transaction(function () use ($settings, $user, $request): void {
            foreach ($settings as $group => $values) {
                foreach ($values as $key => $value) {
                    $existing = PlatformSetting::query()
                        ->where('group', $group)
                        ->where('key', $key)
                        ->first();

                    $oldValue = $existing?->value['value'] ?? null;

                    $record = PlatformSetting::query()->updateOrCreate(
                        ['group' => $group, 'key' => $key],
                        [
                            'value' => ['value' => $value],
                            'updated_by' => $user?->getKey(),
                            'is_sensitive' => str_contains(strtolower($key), 'secret')
                                || str_contains(strtolower($key), 'token')
                                || str_contains(strtolower($key), 'password'),
                        ],
                    );

                    if ($oldValue !== $value) {
                        PlatformSettingAudit::query()->create([
                            'user_id' => $user?->getKey(),
                            'group' => $group,
                            'key' => $key,
                            'old_value' => ['value' => $oldValue],
                            'new_value' => ['value' => $value],
                            'ip_address' => $request->ip(),
                            'user_agent' => mb_substr((string) $request->userAgent(), 0, 1000),
                        ]);
                    }
                }
            }
        });

        return $this->index($request)->setData([
            'data' => $this->currentSettings(),
            'message' => 'تم حفظ إعدادات المنصة وربطها بقاعدة البيانات بنجاح.',
        ]);
    }

    private function currentSettings(): array
    {
        $defaults = config('zad-settings.defaults', []);

        $stored = PlatformSetting::query()
            ->get()
            ->groupBy('group')
            ->map(fn ($items) => $items->mapWithKeys(
                fn (PlatformSetting $item) => [$item->key => $item->value['value'] ?? null]
            )->all())
            ->all();

        return array_replace_recursive($defaults, $stored);
    }
}
