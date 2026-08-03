<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ControlCenterActionRequest;
use App\Http\Requests\Admin\UpdatePlatformControlRequest;
use App\Http\Resources\PlatformControlResource;
use App\Models\PlatformControl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ControlCenterController extends Controller
{
    private const DEFAULT_SECTIONS = [
        'platform' => [
            'platformEnabled' => true,
            'maintenanceMode' => false,
            'registrationsEnabled' => true,
            'newOrdersEnabled' => true,
            'guestCheckoutEnabled' => true,
            'defaultLanguage' => 'ar',
            'timezone' => 'Asia/Riyadh',
        ],
        'modules' => [
            'salesEnabled' => true,
            'deliveryEnabled' => true,
            'financeEnabled' => true,
            'humanResourcesEnabled' => true,
            'digitalEmployeesEnabled' => true,
            'advertisementsEnabled' => true,
            'liveStreamingEnabled' => true,
            'governanceEnabled' => true,
        ],
        'security' => [
            'twoFactorForOwner' => true,
            'twoFactorForAdmins' => false,
            'sessionTimeoutMinutes' => 120,
            'maximumLoginAttempts' => 5,
            'accountLockMinutes' => 30,
            'allowMultipleSessions' => false,
            'requireSensitiveActionConfirmation' => true,
        ],
        'artificial_intelligence' => [
            'enabled' => true,
            'requireOwnerApproval' => true,
            'confidenceThreshold' => 85,
            'dailyTaskLimit' => 1000,
            'monthlyBudget' => 0,
            'maximumAutomaticActionValue' => 500,
            'recordAllDecisions' => true,
        ],
        'services' => [
            'mailEnabled' => true,
            'smsEnabled' => false,
            'firebaseEnabled' => false,
            'mapsEnabled' => true,
            'paymentGatewayEnabled' => false,
            'cloudStorageEnabled' => false,
        ],
        'operations' => [
            'queueEnabled' => true,
            'schedulerEnabled' => true,
            'cacheEnabled' => true,
            'automaticBackupEnabled' => false,
            'backupRetentionDays' => 30,
            'logRetentionDays' => 365,
        ],
        'governance' => [
            'auditLogsEnabled' => true,
            'preventAuditLogDeletion' => true,
            'fourEyesPrincipleEnabled' => true,
            'requireReasonForSensitiveChanges' => true,
            'requireOwnerApprovalForDeletion' => true,
        ],
    ];

    private const AVAILABLE_ACTIONS = [
        'maintenance-on',
        'maintenance-off',
        'registrations-on',
        'registrations-off',
        'orders-on',
        'orders-off',
        'cache-clear',
        'queue-restart',
        'backup',
    ];

    public function index(Request $request): JsonResponse
    {
        $this->ensurePlatformOwner($request);

        $userId = (int) $request->user()->getKey();

        $this->ensureDefaultSectionsExist($userId);

        $controls = PlatformControl::query()
            ->with('updatedBy:id,name,email')
            ->whereIn('section', array_keys(self::DEFAULT_SECTIONS))
            ->orderBy('section')
            ->get();

        $history = PlatformControl::query()
            ->where('section', 'action_history')
            ->value('value');

        return response()->json([
            'data' => PlatformControlResource::collection($controls),
            'history' => array_values(
                is_array($history) ? ($history['items'] ?? []) : [],
            ),
            'health' => $this->getHealthStatus(),
            'availableActions' => self::AVAILABLE_ACTIONS,
            'meta' => [
                'isOwner' => true,
                'canManagePlatform' => true,
                'sectionsCount' => $controls->count(),
                'generatedAt' => now()->toISOString(),
            ],
        ]);
    }

    public function update(
        UpdatePlatformControlRequest $request,
        string $section,
    ): JsonResponse {
        if (! array_key_exists($section, self::DEFAULT_SECTIONS)) {
            return response()->json([
                'message' => 'قسم التحكم المطلوب غير معروف.',
            ], 404);
        }

        $validated = $request->validated();

        $control = PlatformControl::query()
            ->where('section', $section)
            ->first();

        $isSensitive = $control?->is_sensitive
            ?? $this->isSensitiveSection($section);

        if ($isSensitive && ! $request->boolean('confirmation')) {
            return response()->json([
                'message' => 'يجب تأكيد تعديل هذا القسم الحساس.',
                'errors' => [
                    'confirmation' => [
                        'يجب تأكيد تعديل هذا القسم الحساس.',
                    ],
                ],
            ], 422);
        }

        $existingValue = is_array($control?->value)
            ? $control->value
            : self::DEFAULT_SECTIONS[$section];

        $incomingValue = $validated['value'] ?? [];

        $mergedValue = array_replace_recursive(
            $existingValue,
            $incomingValue,
        );

        $control = DB::transaction(function () use (
            $request,
            $section,
            $validated,
            $mergedValue,
            $isSensitive,
        ): PlatformControl {
            return PlatformControl::query()->updateOrCreate(
                ['section' => $section],
                [
                    'value' => $mergedValue,
                    'description' => $validated['description']
                        ?? $this->sectionDescription($section),
                    'is_sensitive' => $validated['is_sensitive']
                        ?? $isSensitive,
                    'updated_by' => $request->user()->getKey(),
                ],
            );
        });

        $control->load('updatedBy:id,name,email');

        $this->recordAction(
            userId: (int) $request->user()->getKey(),
            action: 'section-updated',
            reason: $validated['reason'] ?? null,
            context: [
                'section' => $section,
                'changedKeys' => array_keys($incomingValue),
            ],
        );

        Cache::forget('zad.platform-control');

        return response()->json([
            'message' => 'تم حفظ إعدادات القسم بنجاح.',
            'data' => new PlatformControlResource($control),
        ]);
    }

    public function action(
        ControlCenterActionRequest $request,
        string $action,
    ): JsonResponse {
        if (! in_array($action, self::AVAILABLE_ACTIONS, true)) {
            return response()->json([
                'message' => 'عملية مركز التحكم غير معروفة.',
            ], 404);
        }

        $userId = (int) $request->user()->getKey();

        $result = match ($action) {
            'maintenance-on' => $this->updatePlatformFlag(
                key: 'maintenanceMode',
                value: true,
                userId: $userId,
            ),
            'maintenance-off' => $this->updatePlatformFlag(
                key: 'maintenanceMode',
                value: false,
                userId: $userId,
            ),
            'registrations-on' => $this->updatePlatformFlag(
                key: 'registrationsEnabled',
                value: true,
                userId: $userId,
            ),
            'registrations-off' => $this->updatePlatformFlag(
                key: 'registrationsEnabled',
                value: false,
                userId: $userId,
            ),
            'orders-on' => $this->updatePlatformFlag(
                key: 'newOrdersEnabled',
                value: true,
                userId: $userId,
            ),
            'orders-off' => $this->updatePlatformFlag(
                key: 'newOrdersEnabled',
                value: false,
                userId: $userId,
            ),
            'cache-clear' => $this->clearApplicationCache(),
            'queue-restart' => $this->restartQueue(),
            'backup' => $this->createControlCenterBackup(),
            default => [],
        };

        $validated = $request->validated();

        $this->recordAction(
            userId: $userId,
            action: $action,
            reason: $validated['reason'] ?? null,
            context: ['result' => $result],
        );

        return response()->json([
            'message' => $this->actionSuccessMessage($action),
            'action' => $action,
            'result' => $result,
            'executedAt' => now()->toISOString(),
        ]);
    }

    private function ensureDefaultSectionsExist(int $userId): void
    {
        foreach (self::DEFAULT_SECTIONS as $section => $value) {
            PlatformControl::query()->firstOrCreate(
                ['section' => $section],
                [
                    'value' => $value,
                    'description' => $this->sectionDescription($section),
                    'is_sensitive' => $this->isSensitiveSection($section),
                    'updated_by' => $userId,
                ],
            );
        }
    }

    private function updatePlatformFlag(
        string $key,
        bool $value,
        int $userId,
    ): array {
        $control = PlatformControl::query()->firstOrCreate(
            ['section' => 'platform'],
            [
                'value' => self::DEFAULT_SECTIONS['platform'],
                'description' => $this->sectionDescription('platform'),
                'is_sensitive' => true,
                'updated_by' => $userId,
            ],
        );

        $settings = is_array($control->value)
            ? $control->value
            : self::DEFAULT_SECTIONS['platform'];

        Arr::set($settings, $key, $value);

        $control->forceFill([
            'value' => $settings,
            'updated_by' => $userId,
        ])->save();

        Cache::forget('zad.platform-control');

        return [
            'section' => 'platform',
            'key' => $key,
            'value' => $value,
        ];
    }

    private function clearApplicationCache(): array
    {
        Artisan::call('optimize:clear');

        return [
            'cleared' => true,
            'output' => trim(Artisan::output()),
        ];
    }

    private function restartQueue(): array
    {
        Artisan::call('queue:restart');

        return [
            'restarted' => true,
            'output' => trim(Artisan::output()),
        ];
    }

    private function createControlCenterBackup(): array
    {
        $controls = PlatformControl::query()
            ->orderBy('section')
            ->get()
            ->map(fn (PlatformControl $control): array => [
                'section' => $control->section,
                'value' => $control->value,
                'description' => $control->description,
                'is_sensitive' => (bool) $control->is_sensitive,
                'updated_at' => $control->updated_at?->toISOString(),
            ])
            ->values()
            ->all();

        $fileName = sprintf(
            'backups/control-center/control-center-%s.json',
            now()->format('Y-m-d_H-i-s'),
        );

        $payload = json_encode(
            [
                'generatedAt' => now()->toISOString(),
                'application' => config('app.name'),
                'environment' => app()->environment(),
                'sections' => $controls,
            ],
            JSON_PRETTY_PRINT
                | JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES,
        );

        if ($payload === false) {
            throw new \RuntimeException(
                'تعذر تجهيز بيانات النسخة الاحتياطية.',
            );
        }

        Storage::disk('local')->put($fileName, $payload);

        return [
            'created' => true,
            'file' => $fileName,
            'disk' => 'local',
        ];
    }

    private function getHealthStatus(): array
    {
        return [
            'application' => [
                'status' => 'healthy',
                'environment' => app()->environment(),
                'debug' => (bool) config('app.debug'),
            ],
            'database' => $this->databaseHealth(),
            'cache' => $this->cacheHealth(),
            'storage' => $this->storageHealth(),
            'queue' => [
                'status' => 'configured',
                'connection' => config('queue.default'),
            ],
            'scheduler' => [
                'status' => 'configured',
                'timezone' => config('app.timezone'),
            ],
        ];
    }

    private function databaseHealth(): array
    {
        try {
            DB::select('select 1');

            return [
                'status' => 'healthy',
                'connection' => DB::getDefaultConnection(),
            ];
        } catch (Throwable $exception) {
            report($exception);

            return [
                'status' => 'offline',
                'message' => 'تعذر الاتصال بقاعدة البيانات.',
            ];
        }
    }

    private function cacheHealth(): array
    {
        try {
            $key = 'zad-control-center-health';

            Cache::put($key, 'ok', 10);

            $healthy = Cache::get($key) === 'ok';

            Cache::forget($key);

            return [
                'status' => $healthy ? 'healthy' : 'warning',
                'store' => config('cache.default'),
            ];
        } catch (Throwable $exception) {
            report($exception);

            return [
                'status' => 'offline',
                'message' => 'تعذر فحص خدمة الكاش.',
            ];
        }
    }

    private function storageHealth(): array
    {
        try {
            $file = 'health/control-center-test.txt';

            Storage::disk('local')->put($file, 'ok');

            $healthy = Storage::disk('local')->exists($file);

            Storage::disk('local')->delete($file);

            return [
                'status' => $healthy ? 'healthy' : 'warning',
                'disk' => 'local',
            ];
        } catch (Throwable $exception) {
            report($exception);

            return [
                'status' => 'offline',
                'message' => 'تعذر فحص التخزين المحلي.',
            ];
        }
    }

    private function recordAction(
        int $userId,
        string $action,
        ?string $reason,
        array $context = [],
    ): void {
        DB::transaction(function () use (
            $userId,
            $action,
            $reason,
            $context,
        ): void {
            $historyControl = PlatformControl::query()
                ->where('section', 'action_history')
                ->lockForUpdate()
                ->first();

            if (! $historyControl) {
                $historyControl = PlatformControl::query()->create([
                    'section' => 'action_history',
                    'value' => ['items' => []],
                    'description' => 'سجل عمليات مركز التحكم الرئيسي.',
                    'is_sensitive' => true,
                    'updated_by' => $userId,
                ]);
            }

            $history = is_array($historyControl->value)
                ? $historyControl->value
                : ['items' => []];

            $items = is_array($history['items'] ?? null)
                ? $history['items']
                : [];

            array_unshift($items, [
                'action' => $action,
                'reason' => $reason,
                'context' => $context,
                'userId' => $userId,
                'ip' => request()->ip(),
                'userAgent' => request()->userAgent(),
                'executedAt' => now()->toISOString(),
            ]);

            $historyControl->forceFill([
                'value' => [
                    'items' => array_slice($items, 0, 100),
                ],
                'updated_by' => $userId,
            ])->save();
        });
    }

    private function ensurePlatformOwner(Request $request): void
    {
        $user = $request->user();

        abort_unless(
            $user && $this->isPlatformOwner($user),
            403,
            'هذه الصفحة خاصة بمالك المنصة.',
        );
    }

    private function isPlatformOwner(object $user): bool
    {
        if (
            (bool) $user->getAttribute('is_platform_owner') === true
            || $user->getAttribute('role') === 'platform_owner'
        ) {
            return true;
        }

        if (
            method_exists($user, 'hasRole')
            && $user->hasRole('platform_owner')
        ) {
            return true;
        }

        return method_exists($user, 'roles')
            && $user->roles()
                ->where('key', 'platform_owner')
                ->exists();
    }

    private function sectionDescription(string $section): string
    {
        return match ($section) {
            'platform' => 'حالة المنصة والتسجيل والطلبات ووضع الصيانة.',
            'modules' => 'تشغيل وإيقاف وحدات منصة زاد.',
            'security' => 'إعدادات الحماية والجلسات والتحقق.',
            'artificial_intelligence' => 'ضوابط الذكاء الاصطناعي والموظفين الرقميين.',
            'services' => 'الخدمات الخارجية والبريد والرسائل والتخزين.',
            'operations' => 'التشغيل التقني والنسخ الاحتياطي والكاش والطوابير.',
            'governance' => 'الحوكمة والاعتمادات وسجل التغييرات.',
            default => 'إعدادات مركز التحكم الرئيسي.',
        };
    }

    private function isSensitiveSection(string $section): bool
    {
        return in_array($section, [
            'platform',
            'security',
            'artificial_intelligence',
            'operations',
            'governance',
        ], true);
    }

    private function actionSuccessMessage(string $action): string
    {
        return match ($action) {
            'maintenance-on' => 'تم تشغيل وضع الصيانة.',
            'maintenance-off' => 'تم إيقاف وضع الصيانة.',
            'registrations-on' => 'تم فتح التسجيل.',
            'registrations-off' => 'تم إيقاف التسجيل.',
            'orders-on' => 'تم تشغيل استقبال الطلبات الجديدة.',
            'orders-off' => 'تم إيقاف استقبال الطلبات الجديدة.',
            'cache-clear' => 'تم مسح كاش التطبيق.',
            'queue-restart' => 'تم إرسال أمر إعادة تشغيل الطوابير.',
            'backup' => 'تم إنشاء نسخة احتياطية لمركز التحكم.',
            default => 'تم تنفيذ العملية بنجاح.',
        };
    }
}