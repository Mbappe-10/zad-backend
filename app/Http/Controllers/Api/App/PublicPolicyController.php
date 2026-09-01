<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Models\PlatformSetting;
use App\Models\PolicyDocument;
use App\Models\PolicyVersion;
use Illuminate\Http\JsonResponse;

class PublicPolicyController extends Controller
{
    public function index(): JsonResponse
    {
        $documents = PolicyDocument::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get()
            ->map(function (PolicyDocument $document): ?array {
                $version = $document->versions()
                    ->where('status', 'published')
                    ->latest('published_at')
                    ->first();

                return $version ? $this->payload($document, $version) : null;
            })
            ->filter()
            ->values();

        return response()->json([
            'data' => $documents,
            'contact' => $this->contactSettings(),
            'generated_at' => now()->toISOString(),
        ]);
    }

    public function show(string $key): JsonResponse
    {
        $document = PolicyDocument::query()->where('key', $key)->where('is_active', true)->firstOrFail();
        $version = $document->versions()->where('status', 'published')->latest('published_at')->firstOrFail();

        return response()->json(['data' => $this->payload($document, $version)]);
    }

    private function payload(PolicyDocument $document, PolicyVersion $version): array
    {
        return [
            'key' => $document->key,
            'version' => $version->version,
            'title_ar' => $version->title_ar,
            'title_en' => $version->title_en,
            'content_ar' => $version->content_ar,
            'content_en' => $version->content_en,
            'requires_acceptance' => $version->requires_acceptance,
            'effective_at' => $version->effective_at?->toISOString(),
            'published_at' => $version->published_at?->toISOString(),
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