<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ControlCenterActionRequest;
use App\Http\Requests\Admin\UpdatePlatformControlRequest;
use App\Http\Resources\PlatformControlCollection;
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
    /**
     * الإعدادات الافتراضية لمركز التحكم.
     */
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

    /**
     * عرض مركز التحكم كاملًا.
     */
    public function index(Request $request): JsonResponse
    {
        $this->ensurePlatformOwner($request);

        $this->ensureDefaultSectionsExist(
            $request->user()->id,
        );

        $controls = PlatformControl::query()
            ->with('updatedBy:id,name,email')
            ->orderBy('section')
            ->get();

        return response()->json([
            'data' => new PlatformControlCollection($controls),

            'health' => $this->getHealthStatus(),

            'availableActions' => [
                'maintenance-on',
                'maintenance-off',
                'registrations-on',
                'registrations-off',
                'orders-on',
                'orders-off',
                'cache-clear',
                'queue-restart',
                'backup',
            ],

            'meta' => [
                'isOwner' => true,
                'canManagePlatform' => true,
                'generatedAt' => now()->toISOString(),
            ],
        ]);
    }

    /**
     * تحديث قسم واحد.
     */
    public function update(
        UpdatePlatformControlRequest $request,
        string $section,
    ): JsonResponse {
        if (! array_key_exists($section, self::DEFAULT_SECTIONS)) {
            return response()->json([
                'message' => 'قسم التحكم المطلوب غير معروف.',
            ], 404);
        }

        $control = PlatformControl::query()
            ->where('section', $section)
            ->first();

        if (
            $control?->is_sensitive === true &&
            ! $request->boolean('confirmation')
        ) {
            return response()->json([
                'message' => 'يجب تأكيد تعديل هذا القسم الحساس.',
            ], 422);
        }

        $validated = $request->validated();

        $existingValue = $control?->value
            ?? self::DEFAULT_SECTIONS[$section];

        $mergedValue = array_replace_recursive(
            $existingValue,
            $validated['value'],
        );

        $control = DB::transaction(function () use (
            $request,
            $section,
            $validated,
            $mergedValue,
        ) {
            return PlatformControl::query()->updateOrCreate(
                [
                    'section' => $section,
                ],
                [
                    'value' => $mergedValue,

                    'description' => $validated['description']
                        ?? $this->sectionDescription($section),

                    'is_sensitive' => $validated['is_sensitive']
                        ?? $this->isSensitiveSection($section),

                    'updated_by' => $request->user()->id,
                ],
            );
        });

        $control->load('updatedBy:id,name,email');

        $this->recordAction(
            userId: $request->user()->id,
            action: 'section-updated',
            reason: $validated['reason'] ?? null,
            context: [
                'section' => $section,
            ],
        );

        Cache::forget('zad.platform-control');

        return response()->json([
            'message' => 'تم حفظ إعدادات القسم بنجاح.',

            'data' => new PlatformControlResource($control),
        ]);
    }

    /**
     * تنفيذ عملية حساسة من مركز التحكم.
     */
    public function action(
        ControlCenterActionRequest $request,
        string $action,
    ): JsonResponse {
        $allowedActions = [
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

        if (! in_array($action, $allowedActions, true)) {
            return response()->json([
                'message' => 'عملية مركز التحكم غير معروفة.',
            ], 404);
        }

        $result = match ($action) {
            'maintenance-on' => $this->updatePlatformFlag(
                key: 'maintenanceMode',
                value: true,
                userId: $request->user()->id,
            ),

            'maintenance-off' => $this->updatePlatformFlag(
                key: 'maintenanceMode',
                value: false,
                userId: $request->user()->id,
            ),

            'registrations-on' => $this->updatePlatformFlag(
                key: 'registrationsEnabled',
                value: true,
                userId: $request->user()->id,
            ),

            'registrations-off' => $this->updatePlatformFlag(
                key: 'registrationsEnabled',
                value: false,
                userId: $request->user()->id,
            ),

            'orders-on' => $this->updatePlatformFlag(
                key: 'newOrdersEnabled',
                value: true,
                userId: $request->user()->id,
            ),

            'orders-off' => $this->updatePlatformFlag(
                key: 'newOrdersEnabled',
                value: false,
                userId: $request->user()->id,
            ),

            'cache-clear' => $this->clearApplicationCache(),

            'queue-restart' => $this->restartQueue(),

            'backup' => $this->createControlCenterBackup(),

            default => null,
        };

        $this->recordAction(
            userId: $request->user()->id,
            action: $action,
            reason: $request->validated('reason'),
            context: [
                'result' => $result,
            ],
        );

        return response()->json([
            'message' => $this->actionSuccessMessage($action),

            'action' => $action,

            'result' => $result,

            'executedAt' => now()->toISOString(),
        ]);
    }

    private function ensureDefaultSectionsExist(
        int $userId,
    ): void {
        foreach (self::DEFAULT_SECTIONS as $section => $value) {
            PlatformControl::query()->firstOrCreate(
                [
                    'section' => $section,
                ],
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
        $control = PlatformControl::query()
            ->firstOrCreate(
                [
                    'section' => 'platform',
                ],
                [
                    'value' => self::DEFAULT_SECTIONS['platform'],
                    'description' => $this->sectionDescription('platform'),
                    'is_sensitive' => true,
                    'updated_by' => $userId,
                ],
            );

        $settings = $control->value
            ?? self::DEFAULT_SECTIONS['platform'];

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
            ->map(fn (PlatformControl $control) => [
                'section' => $control->section,
                'value' => $control->value,
                'description' => $control->description,
                'is_sensitive' => $control->is_sensitive,
                'updated_at' => $control->updated_at?->toISOString(),
            ])
            ->values()
            ->all();

        $fileName = sprintf(
            'backups/control-center/control-center-%s.json',
            now()->format('Y-m-d_H-i-s'),
        );

        Storage::disk('local')->put(
            $fileName,
            json_encode(
                [
                    'generatedAt' => now()->toISOString(),
                    'application' => config('app.name'),
                    'environment' => app()->environment(),
                    'sections' => $controls,
                ],
                JSON_PRETTY_PRINT |
                JSON_UNESCAPED_UNICODE |
                JSON_UNESCAPED_SLASHES,
            ),
        );

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
                'status' => 'unknown',
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
            return [
                'status' => 'offline',
                'message' => $exception->getMessage(),
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
                'status' => $healthy
                    ? 'healthy'
                    : 'warning',

                'store' => config('cache.default'),
            ];
        } catch (Throwable $exception) {
            return [
                'status' => 'offline',
                'message' => $exception->getMessage(),
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
                'status' => $healthy
                    ? 'healthy'
                    : 'warning',

                'disk' => 'local',
            ];
        } catch (Throwable $exception) {
            return [
                'status' => 'offline',
                'message' => $exception->getMessage(),
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
        ) {
            $historyControl = PlatformControl::query()
                ->lockForUpdate()
                ->firstOrCreate(
                    [
                        'section' => 'action_history',
                    ],
                    [
                        'value' => [
                            'items' => [],
                        ],

                        'description' => 'سجل عمليات مركز التحكم الرئيسي.',

                        'is_sensitive' => true,

                        'updated_by' => $userId,
                    ],
                );

            $history = $historyControl->value ?? [
                'items' => [],
            ];

            $items = $history['items'] ?? [];

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

    private function ensurePlatformOwner(
        Request $request,
    ): void {
        $user = $request->user();

        abort_unless(
            $user && (
                (bool) $user->getAttribute('is_platform_owner') === true ||
                $user->getAttribute('role') === 'platform_owner' ||
                (
                    method_exists($user, 'hasRole') &&
                    $user->hasRole('platform_owner')
                ) ||
                $user
                    ->roles()
                    ->where('key', 'platform_owner')
                    ->exists()
            ),
            403,
            'هذه الصفحة خاصة بمالك المنصة.',
        );
    }

    private function sectionDescription(
        string $section,
    ): string {
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

    private function isSensitiveSection(
        string $section,
    ): bool {
        return in_array($section, [
            'platform',
            'security',
            'artificial_intelligence',
            'operations',
            'governance',
        ], true);
    }

    private function actionSuccessMessage(
        string $action,
    ): string {
        return match ($action) {
            'maintenance-on' => 'تم تشغيل وضع الصيانة.',

            'maintenance-off' => 'تم إيقاف وضع الصيانة.',

            'registrations-on' => 'تم فتح التسجيل.',

            'registrations-off' => 'تم إيقاف التسجيل.',

            'orders-on' => 'تم استقبال الطلبات الجديدة.',

            'orders-off' => 'تم إيقاف استقبال الطلبات الجديدة.',

            'cache-clear' => 'تم مسح كاش التطبيق.',

            'queue-restart' => 'تم إرسال أمر إعادة تشغيل الطوابير.',

            'backup' => 'تم إنشاء نسخة احتياطية لمركز التحكم.',

            default => 'تم تنفيذ العملية بنجاح.',
        };
    }
}

Route::middleware('auth:sanctum')->prefix('admin')->group(function () {
    Route::get(
        '/control-center',
        [ControlCenterController::class, 'index'],
    );

    Route::patch(
        '/control-center/{section}',
        [ControlCenterController::class, 'update'],
    );

    Route::post(
        '/control-center/actions/{action}',
        [ControlCenterController::class, 'action'],
    );
});