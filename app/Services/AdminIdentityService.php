<?php

namespace App\Services;

use App\Models\Permission;
use App\Models\PlatformRecord;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Keeps dashboard record IDs stable; authenticates only through real User/Role models. */
class AdminIdentityService
{
    public const MODULES = ['dashboard','orders','customers','families','stores','products','categories','drivers','vehicles','cities','zones','wallets','transactions','payments','commissions','coupons','notifications','support','content','reports','settings','roles','permissions','users','hr','governance','audit_logs','automation'];
    public const ACTIONS = ['view','create','update','delete','approve','export'];

    public static function safe(array $data): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            if (is_string($key) && preg_match('/password|token|secret|credential|auth_user_id|auth_role_id/i', $key)) {
                continue;
            }
            $result[$key] = is_array($value) ? self::safe($value) : $value;
        }
        return $result;
    }

    public function entity(PlatformRecord $record): User|Role|null
    {
        $id = DB::table('admin_identity_links')->where('record_id', $record->id)
            ->where('resource', $record->resource)->value('entity_id');
        if (!$id) return null;
        return $record->resource === 'users' ? User::withTrashed()->find($id) : Role::withTrashed()->find($id);
    }

    private function link(PlatformRecord $record, User|Role $entity): void
    {
        DB::table('admin_identity_links')->insert([
            'resource' => $record->resource, 'record_id' => $record->id,
            'entity_id' => $entity->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function authorize(User $actor, string $resource, string $action): void
    {
        abort_unless($actor->isActive() && $actor->isApproved(), 403, 'الحساب غير نشط أو غير معتمد.');
        if ($actor->isPlatformOwner()) return;
        $keys = [$resource.'.'.$action];
        if ($action === 'update') $keys[] = $resource.'.edit';
        // Existing explicitly assigned manage permissions remain supported.
        if ($action !== 'view') $keys[] = $resource.'.manage';
        if ($resource === 'roles' && $action === 'view') {
            $keys = [...$keys, 'users.create', 'users.update', 'users.edit'];
        }
        abort_unless($actor->hasAnyPermission($keys), 403, 'لا تملك صلاحية تنفيذ هذا الإجراء.');
    }

    public function protect(PlatformRecord $record, User $actor): void
    {
        $entity = $this->entity($record);
        if ($entity instanceof User) {
            abort_if($entity->isPlatformOwner() || $entity->is_protected || $entity->role_locked || $entity->permissions_locked, 403, 'الحساب محمي.');
            abort_if($entity->id === $actor->id, 403, 'استخدم الملف الشخصي لتعديل حسابك.');
            $this->grantCeiling($actor, $entity->effectivePermissions());
        }
        if ($entity instanceof Role) {
            abort_if($entity->is_system, 403, 'الدور الأساسي محمي.');
            $this->grantCeiling($actor, $entity->permissions()->pluck('key')->all());
        }
    }

    private function grantCeiling(User $actor, array $keys): void
    {
        if ($actor->isPlatformOwner()) return;
        foreach ($keys as $key) {
            abort_unless($actor->hasPermission($key), 403, 'لا يمكن إدارة حساب أو منح صلاحية أعلى من صلاحياتك.');
        }
    }

    public function permissionKeys(array $matrix): array
    {
        $keys = [];
        foreach ($matrix as $row) {
            $module = $row['module'];
            $canonical = ['wallets'=>'wallet', 'audit_logs'=>'logs', 'automation'=>'autonomous-operations'][$module] ?? $module;
            foreach ($row['actions'] as $action) {
                $keys[] = $module.'.'.$action;
                $keys[] = $canonical.'.'.$action;
                if ($action === 'update') {
                    $keys[] = $module.'.edit';
                    $keys[] = $canonical.'.edit';
                }
            }
        }
        return array_values(array_unique($keys));
    }

    public function saveRole(PlatformRecord $record, array $input, User $actor): void
    {
        $input['code'] = strtolower(trim((string) ($input['code'] ?? '')));
        $data = Validator::make($input, [
            'name_ar'=>'required|string|max:255', 'name_en'=>'nullable|string|max:255',
            'code'=>['required','string','max:80','regex:/^[a-z][a-z0-9_-]*$/'],
            'description_ar'=>'nullable|string|max:10000', 'description_en'=>'nullable|string|max:10000',
            'status'=>['required',Rule::in(['active','inactive'])],
            'type'=>['required',Rule::in(['system','custom'])],
            'employee_type'=>['required',Rule::in(['human','digital','mixed'])],
            'permissions'=>'present|array', 'permissions.*.module'=>['required',Rule::in(self::MODULES)],
            'permissions.*.actions'=>'present|array', 'permissions.*.actions.*'=>['required',Rule::in(self::ACTIONS)],
        ])->validate();
        if (in_array($data['code'], ['platform_owner','owner','super_admin','superadmin'], true)) {
            throw ValidationException::withMessages(['code'=>'هذا الرمز محجوز لحساب النظام.']);
        }
        if (PlatformRecord::where('resource','roles')->whereKeyNot($record->id)->where('payload->code',$data['code'])->exists()) {
            throw ValidationException::withMessages(['code'=>'رمز الدور مستخدم بالفعل.']);
        }
        $keys = $this->permissionKeys($data['permissions']);
        $this->grantCeiling($actor, $keys);
        $role = $this->entity($record);
        if ($role) $this->protect($record, $actor);
        $new = !$role;
        if ($new) {
            // A matching name/code is NOT proof of identity. Never overwrite seeded roles.
            $key = $data['code'];
            if (Role::withTrashed()->where('key',$key)->exists()) $key = 'zad_record_'.$record->id.'_'.$key;
            $role = new Role(['key'=>$key, 'is_system'=>false, 'priority'=>0]);
        }
        abort_unless($role instanceof Role && !$role->trashed(), 409, 'الدور المرتبط محذوف.');
        $role->fill([
            'name_ar'=>$data['name_ar'], 'name_en'=>($data['name_en'] ?? null) ?: $data['name_ar'],
            'description_ar'=>$data['description_ar'] ?? null, 'description_en'=>$data['description_en'] ?? null,
            'is_active'=>$data['status']==='active',
        ])->save();
        $ids = [];
        foreach ($keys as $key) {
            [$module,$action] = explode('.', $key, 2);
            $permission = Permission::firstOrCreate(['key'=>$key], [
                'module'=>$module, 'action'=>$action, 'name_ar'=>$key, 'name_en'=>$key,
                'is_active'=>true, 'is_sensitive'=>in_array($action,['delete','approve'],true),
                'requires_approval'=>false,
            ]);
            if (!$permission->is_active) throw ValidationException::withMessages(['permissions'=>'توجد صلاحية معطلة: '.$key]);
            $ids[] = $permission->id;
        }
        $role->permissions()->sync($ids);
        if ($new) $this->link($record, $role);
        // Revocation is immediate for existing sessions, including role deactivation.
        $role->users()->where('is_platform_owner',false)->each(fn (User $user) => $user->tokens()->delete());
        $data['type'] = 'custom';
        $record->payload = self::safe($data);
        $record->status = $data['status'];
        $record->save();
    }

    public function saveUser(PlatformRecord $record, array $input, User $actor, bool $legacy = false): void
    {
        $user = $this->entity($record);
        if ($user) $this->protect($record, $actor);
        $input['email'] = strtolower(trim((string) ($input['email'] ?? '')));
        $data = Validator::make($input, [
            'name'=>'required|string|max:255', 'email'=>'required|email|max:255', 'phone'=>'nullable|string|max:30',
            'status'=>['required',Rule::in(['active','inactive','suspended','pending'])],
            'user_type'=>['required',Rule::in(['human','digital'])], 'role_id'=>'nullable|integer',
            'job_title'=>'nullable|string|max:255', 'department'=>'nullable|string|max:255', 'city'=>'nullable|string|max:255',
            'password'=>array_values(array_filter([$user ? 'sometimes' : 'required', 'string','min:8','max:255', $legacy ? null : 'confirmed'])),
        ])->validate();
        if ($data['user_type'] !== 'human') {
            throw ValidationException::withMessages(['user_type'=>'هذا المسار للحسابات البشرية. أنشئ الموظف الرقمي من صفحة الموظفين الرقميين.']);
        }
        $duplicate = User::withTrashed()->whereRaw('LOWER(email) = ?',[$data['email']]);
        if ($user) $duplicate->whereKeyNot($user->id);
        if ($duplicate->exists()) throw ValidationException::withMessages(['email'=>'البريد مرتبط بحساب قائم؛ لن يتم تغيير حسابه أو كلمة مروره تلقائيًا.']);
        if (!empty($data['phone'])) {
            $phones = User::withTrashed()->where('phone',$data['phone']);
            if ($user) $phones->whereKeyNot($user->id);
            if ($phones->exists()) throw ValidationException::withMessages(['phone'=>'رقم الجوال مستخدم بالفعل.']);
        }
        $role = null;
        if (!empty($data['role_id'])) {
            $roleRecord = PlatformRecord::where('resource','roles')->find($data['role_id']);
            $role = $roleRecord ? $this->entity($roleRecord) : null;
            if (!$role instanceof Role || $role->trashed() || !$role->is_active) {
                throw ValidationException::withMessages(['role_id'=>'اختر دورًا نشطًا مرتبطًا بالنظام.']);
            }
            if (($roleRecord->payload['employee_type'] ?? 'human') === 'digital') {
                throw ValidationException::withMessages(['role_id'=>'هذا الدور مخصص للموظفين الرقميين.']);
            }
            $this->grantCeiling($actor, $role->permissions()->pluck('key')->all());
        }
        $new = !$user;
        if ($new) $user = new User();
        abort_unless($user instanceof User && !$user->trashed(), 409, 'الحساب المرتبط محذوف.');
        $user->fill([
            'name'=>$data['name'], 'name_ar'=>$data['name'], 'email'=>$data['email'], 'phone'=>$data['phone'] ?? null,
            'status'=>$data['status'], 'is_approved'=>$data['status'] !== 'pending',
            'approved_by'=>$actor->id, 'approved_at'=>$data['status'] !== 'pending' ? now() : null,
        ]);
        if (isset($data['password'])) {
            // Explicit hashing; the model's hashed cast does not hash a valid hash twice.
            $user->password = Hash::make($data['password']);
            $user->password_changed_at = now();
        }
        $user->save();
        $user->roles()->sync($role ? [$role->id=>['assigned_by'=>$actor->id,'expires_at'=>null]] : []);
        $user->tokens()->delete();
        if ($new) $this->link($record,$user);
        $record->payload = self::safe($data);
        $record->status = $data['status'];
        $record->save();
    }

    public function remove(PlatformRecord $record, User $actor): void
    {
        $this->protect($record,$actor);
        $entity = $this->entity($record);
        if ($entity instanceof Role) {
            abort_if($entity->users()->exists(),422,'الدور مسند لمستخدمين. غيّر أدوارهم قبل الحذف.');
            $entity->permissions()->detach();
        }
        if ($entity instanceof User) $entity->tokens()->delete();
        $entity?->delete();
        $record->delete();
    }

    public function row(PlatformRecord $record): array
    {
        $data = self::safe($record->payload ?? []);
        $entity = $this->entity($record);
        if ($entity instanceof User) {
            $data = [...$data,'name'=>$entity->name,'email'=>$entity->email,'phone'=>$entity->phone,'status'=>$entity->status,
                'last_login_at'=>$entity->last_login_at?->toISOString(),'avatar'=>$entity->profile_photo];
            $roleRecord = !empty($data['role_id']) ? PlatformRecord::where('resource','roles')->find($data['role_id']) : null;
            $data['role'] = $roleRecord ? [
                'id'=>$roleRecord->id, 'name'=>$roleRecord->payload['name_ar'] ?? '',
                'name_ar'=>$roleRecord->payload['name_ar'] ?? '', 'name_en'=>$roleRecord->payload['name_en'] ?? '',
            ] : null;
        }
        if ($entity instanceof Role) {
            $data['status'] = $entity->is_active ? 'active':'inactive';
            $data['users_count'] = $entity->users()->count();
            $data['permissions_count'] = collect($data['permissions'] ?? [])->sum(fn($r)=>count($r['actions'] ?? []));
        }
        if ($record->resource === 'roles') $data['name'] = $data['name_ar'] ?? $data['code'] ?? '';
        return [...$data, 'id'=>$record->id, 'created_at'=>$record->created_at?->toISOString(),
            'updated_at'=>$record->updated_at?->toISOString(), 'login_ready'=>$entity instanceof User && !$entity->trashed()];
    }
}
