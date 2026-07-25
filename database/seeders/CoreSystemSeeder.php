<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\JobTitle;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

class CoreSystemSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $departments = $this->seedDepartments();
            $this->seedJobTitles($departments);

            $permissions = $this->seedPermissions();
            $roles = $this->seedRoles();

            $this->syncRolePermissions(
                roles: $roles,
                permissions: $permissions,
            );

            $this->seedPlatformOwner(
                roles: $roles,
                departments: $departments,
            );
        });
    }

    /**
     * إنشاء أقسام منصة زاد.
     *
     * @return array<string, Department>
     */
    private function seedDepartments(): array
    {
        $items = [
            [
                'code' => 'GENERAL_MANAGEMENT',
                'name_ar' => 'الإدارة العامة',
                'name_en' => 'General Management',
                'description_ar' => 'الإدارة العليا والإشراف العام على المنصة.',
                'description_en' => 'Executive management and overall platform supervision.',
                'sort_order' => 1,
            ],
            [
                'code' => 'OPERATIONS',
                'name_ar' => 'العمليات',
                'name_en' => 'Operations',
                'description_ar' => 'إدارة الطلبات والتوصيل والتشغيل اليومي.',
                'description_en' => 'Orders, delivery, and daily operations.',
                'sort_order' => 2,
            ],
            [
                'code' => 'HUMAN_RESOURCES',
                'name_ar' => 'الموارد البشرية',
                'name_en' => 'Human Resources',
                'description_ar' => 'إدارة الموظفين البشريين والمسميات والتوظيف.',
                'description_en' => 'Human employees, job titles, and recruitment.',
                'sort_order' => 3,
            ],
            [
                'code' => 'FINANCE',
                'name_ar' => 'المالية',
                'name_en' => 'Finance',
                'description_ar' => 'إدارة المحافظ والمدفوعات والمعاملات والعمولات.',
                'description_en' => 'Wallets, payments, transactions, and commissions.',
                'sort_order' => 4,
            ],
            [
                'code' => 'MARKETING',
                'name_ar' => 'التسويق',
                'name_en' => 'Marketing',
                'description_ar' => 'إدارة الحملات والعروض والكوبونات والمحتوى التسويقي.',
                'description_en' => 'Campaigns, offers, coupons, and marketing content.',
                'sort_order' => 5,
            ],
            [
                'code' => 'TECHNOLOGY',
                'name_ar' => 'التقنية',
                'name_en' => 'Technology',
                'description_ar' => 'إدارة الأنظمة والتطوير والتكاملات التقنية.',
                'description_en' => 'Systems, development, and technical integrations.',
                'sort_order' => 6,
            ],
            [
                'code' => 'CUSTOMER_SUPPORT',
                'name_ar' => 'الدعم الفني',
                'name_en' => 'Customer Support',
                'description_ar' => 'إدارة البلاغات وخدمة العملاء.',
                'description_en' => 'Tickets and customer service.',
                'sort_order' => 7,
            ],
            [
                'code' => 'GOVERNANCE',
                'name_ar' => 'الحوكمة والاعتمادات',
                'name_en' => 'Governance and Approvals',
                'description_ar' => 'إدارة الالتزام والاعتمادات وسير الموافقات.',
                'description_en' => 'Compliance, approvals, and approval workflows.',
                'sort_order' => 8,
            ],
            [
                'code' => 'DECISION_CENTER',
                'name_ar' => 'مركز القرارات',
                'name_en' => 'Decision Center',
                'description_ar' => 'تحليل القرارات والتوصيات ومتابعة آثارها.',
                'description_en' => 'Decision analysis, recommendations, and impact tracking.',
                'sort_order' => 9,
            ],
            [
                'code' => 'AUTONOMOUS_OPERATIONS',
                'name_ar' => 'مركز التشغيل الذاتي',
                'name_en' => 'Autonomous Operations Center',
                'description_ar' => 'إدارة الموظفين الرقميين وسير العمل الذاتي.',
                'description_en' => 'Digital employees and autonomous workflows.',
                'sort_order' => 10,
            ],
        ];

        $departments = [];

        foreach ($items as $item) {
            $department = Department::query()->updateOrCreate(
                ['code' => $item['code']],
                [
                    ...$item,
                    'parent_id' => null,
                    'is_active' => true,
                ],
            );

            $departments[$item['code']] = $department;
        }

        return $departments;
    }

    /**
     * إنشاء المسميات الوظيفية الأساسية.
     *
     * @param array<string, Department> $departments
     */
    private function seedJobTitles(array $departments): void
    {
        $items = [
            [
                'department' => 'GENERAL_MANAGEMENT',
                'code' => 'PLATFORM_OWNER',
                'name_ar' => 'مالك المنصة',
                'name_en' => 'Platform Owner',
                'level' => 10,
                'can_be_digital' => false,
            ],
            [
                'department' => 'GENERAL_MANAGEMENT',
                'code' => 'GENERAL_MANAGER',
                'name_ar' => 'المدير العام',
                'name_en' => 'General Manager',
                'level' => 9,
                'can_be_digital' => false,
            ],
            [
                'department' => 'OPERATIONS',
                'code' => 'OPERATIONS_MANAGER',
                'name_ar' => 'مدير العمليات',
                'name_en' => 'Operations Manager',
                'level' => 8,
                'can_be_digital' => true,
            ],
            [
                'department' => 'HUMAN_RESOURCES',
                'code' => 'HR_MANAGER',
                'name_ar' => 'مدير الموارد البشرية',
                'name_en' => 'HR Manager',
                'level' => 8,
                'can_be_digital' => true,
            ],
            [
                'department' => 'FINANCE',
                'code' => 'FINANCE_MANAGER',
                'name_ar' => 'مدير المالية',
                'name_en' => 'Finance Manager',
                'level' => 8,
                'can_be_digital' => true,
            ],
            [
                'department' => 'MARKETING',
                'code' => 'MARKETING_MANAGER',
                'name_ar' => 'مدير التسويق',
                'name_en' => 'Marketing Manager',
                'level' => 8,
                'can_be_digital' => true,
            ],
            [
                'department' => 'TECHNOLOGY',
                'code' => 'TECHNICAL_MANAGER',
                'name_ar' => 'مدير التقنية',
                'name_en' => 'Technical Manager',
                'level' => 8,
                'can_be_digital' => false,
            ],
            [
                'department' => 'CUSTOMER_SUPPORT',
                'code' => 'SUPPORT_AGENT',
                'name_ar' => 'موظف دعم فني',
                'name_en' => 'Support Agent',
                'level' => 4,
                'can_be_digital' => true,
            ],
            [
                'department' => 'AUTONOMOUS_OPERATIONS',
                'code' => 'AI_SUPERVISOR',
                'name_ar' => 'مشرف الموظفين الرقميين',
                'name_en' => 'AI Supervisor',
                'level' => 8,
                'can_be_digital' => false,
            ],
            [
                'department' => 'AUTONOMOUS_OPERATIONS',
                'code' => 'DIGITAL_EMPLOYEE',
                'name_ar' => 'موظف رقمي',
                'name_en' => 'Digital Employee',
                'level' => 5,
                'can_be_digital' => true,
            ],
            [
                'department' => 'GENERAL_MANAGEMENT',
                'code' => 'EMPLOYEE',
                'name_ar' => 'موظف',
                'name_en' => 'Employee',
                'level' => 3,
                'can_be_digital' => false,
            ],
            [
                'department' => 'HUMAN_RESOURCES',
                'code' => 'JOB_CANDIDATE',
                'name_ar' => 'مرشح وظيفي',
                'name_en' => 'Job Candidate',
                'level' => 1,
                'can_be_digital' => false,
            ],
        ];

        foreach ($items as $item) {
            $department = $departments[$item['department']] ?? null;

            if (!$department) {
                throw new RuntimeException(
                    "Department {$item['department']} was not found.",
                );
            }

            JobTitle::query()->updateOrCreate(
                ['code' => $item['code']],
                [
                    'department_id' => $department->id,
                    'name_ar' => $item['name_ar'],
                    'name_en' => $item['name_en'],
                    'description_ar' => null,
                    'description_en' => null,
                    'level' => $item['level'],
                    'can_be_digital' => $item['can_be_digital'],
                    'is_active' => true,
                ],
            );
        }
    }

    /**
     * إنشاء الصلاحيات المتوافقة مع لوحة React.
     *
     * @return array<string, Permission>
     */
    private function seedPermissions(): array
    {
        $keys = [
            'autonomous-operations.view',
            'autonomous-operations.manage',
            'autonomous-operations.approve',
            'autonomous-operations.emergency-stop',

            'decision-center.view',
            'decision-center.manage',

            'dashboard.view',
            'analytics.view',

            'orders.view',
            'orders.create',
            'orders.edit',
            'orders.approve',

            'customers.view',
            'customers.create',
            'customers.edit',

            'families.view',
            'families.create',
            'families.edit',

            'stores.view',
            'stores.create',
            'stores.edit',

            'products.view',
            'products.create',
            'products.edit',

            'categories.view',
            'categories.create',
            'categories.edit',

            'drivers.view',
            'drivers.create',
            'drivers.edit',

            'vehicles.view',
            'vehicles.create',
            'vehicles.edit',

            'cities.view',
            'cities.create',
            'cities.edit',

            'zones.view',
            'zones.create',
            'zones.edit',

            'wallet.view',
            'wallet.manage',

            'transactions.view',
            'transactions.approve',

            'payments.view',
            'payments.manage',

            'commissions.view',
            'commissions.manage',

            'coupons.view',
            'coupons.manage',

            'subscriptions.view',
            'subscriptions.manage',

            'notifications.view',
            'notifications.manage',

            'support.view',
            'support.manage',

            'content.view',
            'content.manage',

            'reports.view',
            'reports.export',

            'users.view',
            'users.create',
            'users.edit',
            'users.approve',
            'users.suspend',

            'hr.view',
            'hr.create',
            'hr.edit',
            'hr.approve',

            'governance.view',
            'governance.approve',

            'settings.view',
            'settings.manage',

            'roles.view',
            'roles.manage',

            'permissions.view',
            'permissions.manage',

            'logs.view',
            'logs.export',

            'profile.view',
            'profile.edit',

            'jobs.view',
            'jobs.apply',

            'applications.view',

            'contracts.view',
            'contracts.sign',

            'documents.view',
            'documents.upload',
        ];

        $moduleNames = [
            'autonomous-operations' => [
                'ar' => 'مركز التشغيل الذاتي',
                'en' => 'Autonomous Operations',
            ],
            'decision-center' => [
                'ar' => 'مركز القرارات',
                'en' => 'Decision Center',
            ],
            'dashboard' => ['ar' => 'لوحة التحكم', 'en' => 'Dashboard'],
            'analytics' => ['ar' => 'الإحصائيات', 'en' => 'Analytics'],
            'orders' => ['ar' => 'الطلبات', 'en' => 'Orders'],
            'customers' => ['ar' => 'العملاء', 'en' => 'Customers'],
            'families' => ['ar' => 'الأسر المنتجة', 'en' => 'Productive Families'],
            'stores' => ['ar' => 'المتاجر', 'en' => 'Stores'],
            'products' => ['ar' => 'المنتجات', 'en' => 'Products'],
            'categories' => ['ar' => 'التصنيفات', 'en' => 'Categories'],
            'drivers' => ['ar' => 'مناديب التوصيل', 'en' => 'Drivers'],
            'vehicles' => ['ar' => 'المركبات', 'en' => 'Vehicles'],
            'cities' => ['ar' => 'المدن', 'en' => 'Cities'],
            'zones' => ['ar' => 'مناطق التوصيل', 'en' => 'Delivery Zones'],
            'wallet' => ['ar' => 'المحافظ', 'en' => 'Wallets'],
            'transactions' => ['ar' => 'المعاملات', 'en' => 'Transactions'],
            'payments' => ['ar' => 'المدفوعات', 'en' => 'Payments'],
            'commissions' => ['ar' => 'العمولات', 'en' => 'Commissions'],
            'coupons' => ['ar' => 'الكوبونات والعروض', 'en' => 'Coupons and Offers'],
            'subscriptions' => ['ar' => 'الاشتراكات', 'en' => 'Subscriptions'],
            'notifications' => ['ar' => 'الإشعارات', 'en' => 'Notifications'],
            'support' => ['ar' => 'الدعم الفني', 'en' => 'Support'],
            'content' => ['ar' => 'إدارة المحتوى', 'en' => 'Content'],
            'reports' => ['ar' => 'التقارير', 'en' => 'Reports'],
            'users' => ['ar' => 'المستخدمون', 'en' => 'Users'],
            'hr' => ['ar' => 'الموارد البشرية', 'en' => 'Human Resources'],
            'governance' => ['ar' => 'الحوكمة', 'en' => 'Governance'],
            'settings' => ['ar' => 'الإعدادات', 'en' => 'Settings'],
            'roles' => ['ar' => 'الأدوار', 'en' => 'Roles'],
            'permissions' => ['ar' => 'الصلاحيات', 'en' => 'Permissions'],
            'logs' => ['ar' => 'سجل العمليات', 'en' => 'Audit Logs'],
            'profile' => ['ar' => 'الملف الشخصي', 'en' => 'Profile'],
            'jobs' => ['ar' => 'الوظائف', 'en' => 'Jobs'],
            'applications' => ['ar' => 'طلبات التوظيف', 'en' => 'Applications'],
            'contracts' => ['ar' => 'العقود', 'en' => 'Contracts'],
            'documents' => ['ar' => 'المستندات', 'en' => 'Documents'],
        ];

        $actionNames = [
            'view' => ['ar' => 'عرض', 'en' => 'View'],
            'create' => ['ar' => 'إنشاء', 'en' => 'Create'],
            'edit' => ['ar' => 'تعديل', 'en' => 'Edit'],
            'manage' => ['ar' => 'إدارة', 'en' => 'Manage'],
            'approve' => ['ar' => 'اعتماد', 'en' => 'Approve'],
            'suspend' => ['ar' => 'تعليق', 'en' => 'Suspend'],
            'export' => ['ar' => 'تصدير', 'en' => 'Export'],
            'apply' => ['ar' => 'تقديم', 'en' => 'Apply'],
            'sign' => ['ar' => 'توقيع', 'en' => 'Sign'],
            'upload' => ['ar' => 'رفع', 'en' => 'Upload'],
            'emergency-stop' => [
                'ar' => 'إيقاف طارئ',
                'en' => 'Emergency Stop',
            ],
        ];

        $sensitiveKeys = [
            'autonomous-operations.approve',
            'autonomous-operations.emergency-stop',
            'transactions.approve',
            'payments.manage',
            'wallet.manage',
            'users.approve',
            'users.suspend',
            'hr.approve',
            'governance.approve',
            'settings.manage',
            'roles.manage',
            'permissions.manage',
        ];

        $approvalKeys = [
            'autonomous-operations.approve',
            'autonomous-operations.emergency-stop',
            'transactions.approve',
            'users.approve',
            'hr.approve',
            'governance.approve',
        ];

        $permissions = [];

        foreach ($keys as $key) {
            [$module, $action] = explode('.', $key, 2);

            $moduleLabel = $moduleNames[$module] ?? [
                'ar' => Str::headline($module),
                'en' => Str::headline($module),
            ];

            $actionLabel = $actionNames[$action] ?? [
                'ar' => Str::headline($action),
                'en' => Str::headline($action),
            ];

            $permission = Permission::query()->updateOrCreate(
                ['key' => $key],
                [
                    'module' => $module,
                    'action' => $action,
                    'name_ar' => "{$actionLabel['ar']} {$moduleLabel['ar']}",
                    'name_en' => "{$actionLabel['en']} {$moduleLabel['en']}",
                    'description_ar' => null,
                    'description_en' => null,
                    'is_sensitive' => in_array(
                        $key,
                        $sensitiveKeys,
                        true,
                    ),
                    'requires_approval' => in_array(
                        $key,
                        $approvalKeys,
                        true,
                    ),
                    'is_active' => true,
                ],
            );

            $permissions[$key] = $permission;
        }

        return $permissions;
    }

    /**
     * إنشاء الأدوار.
     *
     * @return array<string, Role>
     */
    private function seedRoles(): array
    {
        $items = [
            'platform_owner' => [
                'name_ar' => 'مالك المنصة',
                'name_en' => 'Platform Owner',
                'priority' => 1000,
            ],
            'general_manager' => [
                'name_ar' => 'المدير العام',
                'name_en' => 'General Manager',
                'priority' => 900,
            ],
            'operations_manager' => [
                'name_ar' => 'مدير العمليات',
                'name_en' => 'Operations Manager',
                'priority' => 800,
            ],
            'hr_manager' => [
                'name_ar' => 'مدير الموارد البشرية',
                'name_en' => 'HR Manager',
                'priority' => 800,
            ],
            'finance_manager' => [
                'name_ar' => 'مدير المالية',
                'name_en' => 'Finance Manager',
                'priority' => 800,
            ],
            'ai_supervisor' => [
                'name_ar' => 'مشرف الموظفين الرقميين',
                'name_en' => 'AI Supervisor',
                'priority' => 750,
            ],
            'support_agent' => [
                'name_ar' => 'موظف دعم فني',
                'name_en' => 'Support Agent',
                'priority' => 400,
            ],
            'employee' => [
                'name_ar' => 'موظف',
                'name_en' => 'Employee',
                'priority' => 300,
            ],
            'candidate' => [
                'name_ar' => 'مرشح وظيفي',
                'name_en' => 'Job Candidate',
                'priority' => 100,
            ],
        ];

        $roles = [];

        foreach ($items as $key => $item) {
            $roles[$key] = Role::query()->updateOrCreate(
                ['key' => $key],
                [
                    ...$item,
                    'description_ar' => null,
                    'description_en' => null,
                    'is_system' => true,
                    'is_active' => true,
                ],
            );
        }

        return $roles;
    }

    /**
     * ربط كل دور بصلاحياته.
     *
     * @param array<string, Role> $roles
     * @param array<string, Permission> $permissions
     */
    private function syncRolePermissions(
        array $roles,
        array $permissions,
    ): void {
        $all = array_keys($permissions);

        $rolePermissions = [
            'platform_owner' => $all,

            'general_manager' => $all,

            'operations_manager' => [
                'autonomous-operations.view',
                'autonomous-operations.manage',
                'autonomous-operations.approve',
                'decision-center.view',
                'dashboard.view',
                'analytics.view',
                'orders.view',
                'orders.create',
                'orders.edit',
                'orders.approve',
                'customers.view',
                'customers.edit',
                'families.view',
                'families.edit',
                'stores.view',
                'stores.edit',
                'products.view',
                'products.edit',
                'categories.view',
                'drivers.view',
                'drivers.create',
                'drivers.edit',
                'vehicles.view',
                'vehicles.create',
                'vehicles.edit',
                'cities.view',
                'cities.edit',
                'zones.view',
                'zones.edit',
                'reports.view',
                'reports.export',
                'notifications.view',
                'profile.view',
                'profile.edit',
                'documents.view',
                'documents.upload',
            ],

            'hr_manager' => [
                'autonomous-operations.view',
                'decision-center.view',
                'dashboard.view',
                'analytics.view',
                'users.view',
                'users.create',
                'users.edit',
                'users.approve',
                'users.suspend',
                'hr.view',
                'hr.create',
                'hr.edit',
                'hr.approve',
                'governance.view',
                'governance.approve',
                'reports.view',
                'reports.export',
                'notifications.view',
                'notifications.manage',
                'profile.view',
                'profile.edit',
                'jobs.view',
                'jobs.apply',
                'applications.view',
                'contracts.view',
                'contracts.sign',
                'documents.view',
                'documents.upload',
            ],

            'finance_manager' => [
                'autonomous-operations.view',
                'decision-center.view',
                'dashboard.view',
                'analytics.view',
                'wallet.view',
                'wallet.manage',
                'transactions.view',
                'transactions.approve',
                'payments.view',
                'payments.manage',
                'commissions.view',
                'commissions.manage',
                'reports.view',
                'reports.export',
                'notifications.view',
                'profile.view',
                'profile.edit',
                'documents.view',
                'documents.upload',
            ],

            'ai_supervisor' => [
                'autonomous-operations.view',
                'autonomous-operations.manage',
                'decision-center.view',
                'dashboard.view',
                'analytics.view',
                'reports.view',
                'reports.export',
                'logs.view',
                'logs.export',
                'notifications.view',
                'profile.view',
                'profile.edit',
                'documents.view',
                'documents.upload',
            ],

            'support_agent' => [
                'dashboard.view',
                'support.view',
                'support.manage',
                'notifications.view',
                'customers.view',
                'orders.view',
                'profile.view',
                'profile.edit',
                'documents.view',
                'documents.upload',
            ],

            'employee' => [
                'profile.view',
                'profile.edit',
                'notifications.view',
                'documents.view',
                'documents.upload',
                'contracts.view',
                'contracts.sign',
            ],

            'candidate' => [
                'profile.view',
                'profile.edit',
                'jobs.view',
                'jobs.apply',
                'applications.view',
                'contracts.view',
                'contracts.sign',
                'documents.view',
                'documents.upload',
                'notifications.view',
            ],
        ];

        foreach ($rolePermissions as $roleKey => $permissionKeys) {
            $role = $roles[$roleKey] ?? null;

            if (!$role) {
                continue;
            }

            $permissionIds = collect($permissionKeys)
                ->map(
                    fn (string $permissionKey): ?int =>
                        $permissions[$permissionKey]->id ?? null,
                )
                ->filter()
                ->values()
                ->all();

            $role->permissions()->sync($permissionIds);
        }
    }

    /**
     * إنشاء حساب مالك المنصة المحلي.
     *
     * يمكن تغيير بياناته من ملف .env.
     *
     * @param array<string, Role> $roles
     * @param array<string, Department> $departments
     */
    private function seedPlatformOwner(
        array $roles,
        array $departments,
    ): void {
        $department = $departments['GENERAL_MANAGEMENT'];

        $jobTitle = JobTitle::query()
            ->where('code', 'PLATFORM_OWNER')
            ->firstOrFail();

        $email = env(
            'ZAD_OWNER_EMAIL',
            'owner@zad.local',
        );

        $password = env(
            'ZAD_OWNER_PASSWORD',
            'ChangeMe123!',
        );

        $owner = User::query()->firstOrNew([
            'email' => $email,
        ]);
        $isNewOwner = !$owner->exists;

        $owner->fill([
            'name' => 'Platform Owner',
            'name_ar' => env(
                'ZAD_OWNER_NAME_AR',
                'مالك منصة زاد',
            ),
            'name_en' => env(
                'ZAD_OWNER_NAME_EN',
                'ZAD Platform Owner',
            ),
            'phone' => $owner->phone,
            'profile_photo' => $owner->profile_photo,
            'department_id' => $department->id,
            'job_title_id' => $jobTitle->id,
            'manager_id' => null,
            'status' => 'active',
            'is_approved' => true,
            'approved_by' => $owner->approved_by,
            'approved_at' => $owner->approved_at ?? now(),
            'locale' => $owner->locale ?: 'ar',
            'timezone' => $owner->timezone ?: 'Asia/Riyadh',
            'mfa_enabled' => $owner->mfa_enabled ?? false,
        ]);

        if ($isNewOwner) {
            $owner->password = Hash::make($password);
            $owner->password_changed_at = now();
            $owner->email_verified_at = now();
        }

        $owner->save();

        $platformOwnerRole = $roles['platform_owner'];

        $owner->roles()->syncWithoutDetaching([
            $platformOwnerRole->id => [
                'assigned_by' => null,
                'expires_at' => null,
            ],
        ]);
    }
}
