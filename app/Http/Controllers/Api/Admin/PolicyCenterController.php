<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\PlatformSetting;
use App\Models\PlatformSettingAudit;
use App\Models\PolicyDocument;
use App\Models\PolicyVersion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PolicyCenterController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->ensureOwner($request);

        $documents = PolicyDocument::query()
            ->with(['versions' => fn ($query) => $query->latest('id')])
            ->orderBy('sort_order')
            ->get()
            ->map(fn (PolicyDocument $document): array => $this->documentPayload($document));

        return response()->json([
            'data' => $documents,
            'contact' => $this->contactSettings(),
        ]);
    }

    public function saveDraft(Request $request, PolicyDocument $document): JsonResponse
    {
        $this->ensureOwner($request);

        $data = $request->validate([
            'title_ar' => ['required', 'string', 'max:180'],
            'title_en' => ['required', 'string', 'max:180'],
            'content_ar' => ['required', 'string', 'max:100000'],
            'content_en' => ['required', 'string', 'max:100000'],
            'requires_acceptance' => ['required', 'boolean'],
            'change_summary' => ['nullable', 'string', 'max:2000'],
            'effective_at' => ['nullable', 'date'],
        ]);

        $draft = DB::transaction(function () use ($request, $document, $data): PolicyVersion {
            $existing = $document->versions()->where('status', 'draft')->latest('id')->lockForUpdate()->first();

            if ($existing) {
                $existing->update($data);
                return $existing->refresh();
            }

            return $document->versions()->create([
                ...$data,
                'version' => $this->nextVersion($document),
                'status' => 'draft',
                'created_by' => $request->user()?->getKey(),
            ]);
        });

        return response()->json([
            'message' => 'تم حفظ مسودة السياسة دون نشرها في التطبيق.',
            'data' => $this->versionPayload($draft),
        ]);
    }

    public function publish(Request $request, PolicyDocument $document, PolicyVersion $version): JsonResponse
    {
        $this->ensureOwner($request);
        abort_unless($version->policy_document_id === $document->id, 404);

        DB::transaction(function () use ($request, $document, $version): void {
            $document->versions()->where('status', 'published')->update(['status' => 'archived']);

            $version->update([
                'status' => 'published',
                'published_at' => now(),
                'published_by' => $request->user()?->getKey(),
                'effective_at' => $version->effective_at ?? now(),
            ]);
        });

        return response()->json([
            'message' => 'تم نشر السياسة وأصبحت ظاهرة في التطبيق.',
            'data' => $this->documentPayload($document->fresh()->load(['versions' => fn ($query) => $query->latest('id')])),
        ]);
    }

    public function updateContact(Request $request): JsonResponse
    {
        $this->ensureOwner($request);

        $data = $request->validate([
            'phone' => ['nullable', 'string', 'max:30'],
            'whatsapp' => ['nullable', 'string', 'max:30'],
            'support_email' => ['nullable', 'email', 'max:180'],
            'working_hours_ar' => ['nullable', 'string', 'max:500'],
            'working_hours_en' => ['nullable', 'string', 'max:500'],
            'address_ar' => ['nullable', 'string', 'max:1000'],
            'address_en' => ['nullable', 'string', 'max:1000'],
            'after_hours_ar' => ['nullable', 'string', 'max:1000'],
            'after_hours_en' => ['nullable', 'string', 'max:1000'],
            'phone_enabled' => ['required', 'boolean'],
            'whatsapp_enabled' => ['required', 'boolean'],
            'email_enabled' => ['required', 'boolean'],
        ]);

        DB::transaction(function () use ($request, $data): void {
            foreach ($data as $key => $value) {
                $existing = PlatformSetting::query()
                    ->where('group', 'contactCenter')
                    ->where('key', $key)
                    ->lockForUpdate()
                    ->first();

                $oldValue = $existing?->value['value'] ?? null;

                PlatformSetting::query()->updateOrCreate(
                    ['group' => 'contactCenter', 'key' => $key],
                    ['value' => ['value' => $value], 'is_sensitive' => false, 'updated_by' => $request->user()?->getKey()],
                );

                PlatformSettingAudit::query()->create([
                    'user_id' => $request->user()?->getKey(),
                    'group' => 'contactCenter',
                    'key' => $key,
                    'old_value' => ['value' => $oldValue],
                    'new_value' => ['value' => $value],
                    'status' => 'published',
                    'action' => $existing ? 'updated' : 'created',
                    'reason' => 'تحديث بيانات اتصل بنا من مركز السياسات.',
                    'ip_address' => $request->ip(),
                    'user_agent' => mb_substr((string) $request->userAgent(), 0, 1000),
                    'approved_at' => now(),
                    'published_at' => now(),
                ]);
            }
        });

        return response()->json([
            'message' => 'تم حفظ بيانات التواصل ونشرها في التطبيق.',
            'data' => $this->contactSettings(),
        ]);
    }

    private function ensureOwner(Request $request): void
    {
        $user = $request->user();
        $isOwner = $user && (
            (string) $user->getAttribute('role') === 'platform_owner'
            || (bool) $user->getAttribute('is_platform_owner')
            || (method_exists($user, 'hasRole') && $user->hasRole('platform_owner'))
        );

        abort_unless($isOwner, 403, 'هذه الصفحة خاصة بمالك المنصة.');
    }

    private function nextVersion(PolicyDocument $document): string
    {
        return '1.'.(string) $document->versions()->count();
    }

    private function documentPayload(PolicyDocument $document): array
    {
        $versions = $document->versions->map(fn (PolicyVersion $version): array => $this->versionPayload($version))->values();

        return [
            'id' => $document->id,
            'key' => $document->key,
            'sort_order' => $document->sort_order,
            'is_active' => $document->is_active,
            'published_version' => $versions->firstWhere('status', 'published'),
            'draft_version' => $versions->firstWhere('status', 'draft'),
            'versions' => $versions,
        ];
    }

    private function versionPayload(PolicyVersion $version): array
    {
        return [
            'id' => $version->id,
            'version' => $version->version,
            'title_ar' => $version->title_ar,
            'title_en' => $version->title_en,
            'content_ar' => $version->content_ar,
            'content_en' => $version->content_en,
            'status' => $version->status,
            'requires_acceptance' => $version->requires_acceptance,
            'change_summary' => $version->change_summary,
            'effective_at' => $version->effective_at?->toISOString(),
            'published_at' => $version->published_at?->toISOString(),
            'created_at' => $version->created_at?->toISOString(),
        ];
    }

    private function contactSettings(): array
    {
        $defaults = [
            'phone' => '',
            'whatsapp' => '',
            'support_email' => 'support@zad.sa',
            'working_hours_ar' => 'يوميًا من 9 صباحًا إلى 10 مساءً',
            'working_hours_en' => 'Daily from 9 AM to 10 PM',
            'address_ar' => 'مكة المكرمة، المملكة العربية السعودية',
            'address_en' => 'Makkah, Kingdom of Saudi Arabia',
            'after_hours_ar' => 'تم استلام رسالتك وسيتم الرد خلال ساعات العمل.',
            'after_hours_en' => 'Your message was received and will be answered during working hours.',
            'phone_enabled' => false,
            'whatsapp_enabled' => false,
            'email_enabled' => true,
        ];

        $stored = PlatformSetting::query()
            ->where('group', 'contactCenter')
            ->get()
            ->mapWithKeys(fn (PlatformSetting $setting): array => [$setting->key => $setting->value['value'] ?? null])
            ->all();

        return array_replace($defaults, $stored);
    }
}