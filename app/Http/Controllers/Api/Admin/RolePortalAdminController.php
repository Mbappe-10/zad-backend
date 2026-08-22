<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\RolePortalRecord;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class RolePortalAdminController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = RolePortalRecord::query()->with('user:id,name,email');

        foreach (['role', 'module', 'status'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->string($filter));
            }
        }

        if ($request->filled('search')) {
            $search = $request->string('search')->toString();
            $query->where(function ($nested) use ($search): void {
                $nested->where('title', 'like', "%{$search}%")
                    ->orWhere('reference', 'like', "%{$search}%")
                    ->orWhere('content', 'like', "%{$search}%");
            });
        }

        return response()->json(
            $query->latest()->paginate(min((int) $request->input('per_page', 25), 100)),
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'role' => ['required', Rule::in(['family', 'driver'])],
            'module' => ['required', Rule::in(['contract'])],
            'title' => ['required', 'string', 'min:3', 'max:180'],
            'content' => ['required', 'string', 'min:20'],
            'status' => ['required', Rule::in(['draft', 'published', 'archived'])],
            'effective_at' => ['nullable', 'date'],
            'requires_reacceptance' => ['nullable', 'boolean'],
            'logo_base64' => ['nullable', 'string', 'max:3000000'],
            'seal_base64' => ['nullable', 'string', 'max:3000000'],
        ]);

        $logoPath = $this->storeBrandImage($data['logo_base64'] ?? null, 'logo');
        $sealPath = $this->storeBrandImage($data['seal_base64'] ?? null, 'seal');

        $version = (int) RolePortalRecord::query()
            ->where('role', $data['role'])
            ->where('module', 'contract')
            ->max('version') + 1;

        if ($data['status'] === 'published') {
            RolePortalRecord::query()
                ->where('role', $data['role'])
                ->where('module', 'contract')
                ->where('status', 'published')
                ->update(['status' => 'archived']);
        }

        $record = RolePortalRecord::query()->create([
            'reference' => 'CONTRACT-'.Str::upper($data['role']).'-'.$version.'-'.Str::upper(Str::random(5)),
            'role' => $data['role'],
            'module' => 'contract',
            'user_id' => $request->user()->id,
            'title' => $data['title'],
            'status' => $data['status'],
            'version' => $version,
            'content' => $data['content'],
            'payload' => [
                'requires_reacceptance' => $data['requires_reacceptance'] ?? true,
                'logo_url' => $logoPath ? url(Storage::url($logoPath)) : null,
                'seal_url' => $sealPath ? url(Storage::url($sealPath)) : null,
                'seal_label' => 'ZAD PLATFORM',
            ],
            'effective_at' => $data['effective_at'] ?? now(),
        ]);

        return response()->json([
            'message' => 'تم حفظ إصدار العقد.',
            'data' => $record,
        ], 201);
    }

    private function storeBrandImage(?string $value, string $name): ?string
    {
        if (! $value) {
            return null;
        }

        if (! preg_match('/^data:image\/(png|jpeg|jpg|webp);base64,(.+)$/s', $value, $matches)) {
            abort(422, 'صيغة صورة الشعار أو الختم غير صحيحة.');
        }

        $bytes = base64_decode($matches[2], true);
        if ($bytes === false || strlen($bytes) > 2 * 1024 * 1024) {
            abort(422, 'حجم صورة الشعار أو الختم غير مقبول.');
        }

        $extension = $matches[1] === 'jpeg' ? 'jpg' : $matches[1];
        $path = 'contracts/branding/'.$name.'-'.Str::uuid().'.'.$extension;
        Storage::disk('public')->put($path, $bytes);

        return $path;
    }

    public function update(
        Request $request,
        RolePortalRecord $record,
    ): JsonResponse {
        $data = $request->validate([
            'status' => ['required', Rule::in([
                'pending', 'approved', 'rejected', 'resolved', 'closed',
                'active', 'draft', 'published', 'archived',
            ])],
            'admin_note' => ['nullable', 'string', 'max:2000'],
        ]);

        if ($record->module === 'contract' && $data['status'] === 'published') {
            RolePortalRecord::query()
                ->where('role', $record->role)
                ->where('module', 'contract')
                ->where('id', '!=', $record->id)
                ->where('status', 'published')
                ->update(['status' => 'archived']);
        }

        $payload = $record->payload ?? [];
        $payload['admin_note'] = $data['admin_note'] ?? null;

        $record->update([
            'status' => $data['status'],
            'payload' => $payload,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ]);

        return response()->json([
            'message' => 'تم تحديث السجل.',
            'data' => $record->fresh(),
        ]);
    }
}
