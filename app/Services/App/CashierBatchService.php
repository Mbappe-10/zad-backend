<?php

namespace App\Services\App;

use App\Models\CashierBatch;
use App\Models\CashierMaterial;
use App\Models\CashierRecipeItem;
use App\Models\CashierStaff;
use App\Models\Product;
use App\Models\Store;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Packaging → (supervisor) → stock. A batch adds to the product's stock and takes
 * its raw materials by the recipe once it is approved. Approval is needed only when
 * the owner made a supervisor's answer required; otherwise batches go straight in.
 */
class CashierBatchService
{
    public const LOCATIONS = ['الثلاجة ١', 'الثلاجة ٢', 'الفريزر', 'الرف', 'المحل'];

    public const PHOTO_MONTHLY_LIMIT = 300;

    public function __construct(private readonly CashierInventoryService $inventory) {}

    public function ready(): bool
    {
        return Schema::hasTable('cashier_batches') && $this->inventory->ready();
    }

    public function settings(Store $store): array
    {
        $row = $this->ready() && Schema::hasTable('cashier_store_settings')
            ? DB::table('cashier_store_settings')->where('store_id', $store->id)->value('settings') : null;
        $saved = is_string($row) ? (json_decode($row, true) ?: []) : [];

        return [
            'photos_enabled' => (bool) ($saved['photos_enabled'] ?? false),
            'monthly_goal' => isset($saved['monthly_goal']) ? (float) $saved['monthly_goal'] : null,
            'photos_this_month' => $this->ready() ? CashierBatch::query()->where('store_id', $store->id)
                ->whereNotNull('photo_path')->where('created_at', '>=', now()->startOfMonth())->count() : 0,
            'photo_monthly_limit' => self::PHOTO_MONTHLY_LIMIT,
            'approval_required' => $this->approvalRequired($store),
            'media_retention_days' => app(CashierZadService::class)->settings()['media_retention_days'],
        ];
    }

    public function saveSettings(Store $store, array $data): array
    {
        $current = $this->settings($store);
        $settings = [
            'photos_enabled' => (bool) ($data['photos_enabled'] ?? $current['photos_enabled']),
            'monthly_goal' => array_key_exists('monthly_goal', $data) ? ($data['monthly_goal'] === null ? null : (float) $data['monthly_goal']) : $current['monthly_goal'],
        ];
        // Keep other sections (e.g. the receipt style) saved in the same row.
        $raw = DB::table('cashier_store_settings')->where('store_id', $store->id)->value('settings');
        $settings = array_replace(is_string($raw) ? (json_decode($raw, true) ?: []) : [], $settings);
        DB::table('cashier_store_settings')->updateOrInsert(
            ['store_id' => $store->id],
            ['settings' => json_encode($settings, JSON_UNESCAPED_UNICODE), 'updated_at' => now(), 'created_at' => now()],
        );

        return $this->settings($store);
    }

    /** A supervisor whose answer the owner made required holds new batches until approved. */
    public function approvalRequired(Store $store): bool
    {
        if (! Schema::hasColumn('cashier_staff', 'approval_required')) {
            return false;
        }

        return CashierStaff::query()->where('store_id', $store->id)->whereNull('removed_at')->where('active', true)
            ->where('approval_required', true)->get()
            ->contains(fn (CashierStaff $s) => in_array('approve', (array) $s->permissions, true));
    }

    public function create(CashierContext $ctx, Product $product, array $data, ?UploadedFile $photo): CashierBatch
    {
        $store = $ctx->store;
        abort_unless($product->store_id === $store->id, 404);
        $path = null;
        if ($photo) {
            $settings = $this->settings($store);
            abort_unless($settings['photos_enabled'], 422, 'الصور مقفلة. المالك يقدر يشغّلها من إعدادات التغليف.');
            abort_if($settings['photos_this_month'] >= self::PHOTO_MONTHLY_LIMIT, 422, 'وصلتم حد الصور لهذا الشهر.');
            $path = $photo->storeAs('cashier-batches/'.$store->id, Str::uuid().'.'.($photo->extension() ?: 'jpg'), $this->disk());
        }
        $direct = ! $this->approvalRequired($store) || $ctx->isOwner() || $ctx->can('approve');

        return DB::transaction(function () use ($ctx, $store, $product, $data, $path, $direct): CashierBatch {
            $batch = CashierBatch::query()->create([
                'store_id' => $store->id,
                'product_id' => $product->id,
                'quantity' => (int) $data['quantity'],
                'location' => $data['location'] ?? null,
                'ready_at' => now()->addMinutes((int) ($data['ready_in_minutes'] ?? 0)),
                'note' => $data['note'] ?? null,
                'photo_path' => $path,
                'status' => 'pending',
                'packed_by_staff_id' => $ctx->staff?->id,
                'packed_by_name' => $ctx->staff?->name ?? 'المالك',
                'device_id' => $ctx->device?->id,
            ]);

            return $direct ? $this->approve($ctx, $batch, null, auto: true) : $batch;
        });
    }

    public function approve(CashierContext $ctx, CashierBatch $batch, ?int $quantity, bool $auto = false): CashierBatch
    {
        return DB::transaction(function () use ($ctx, $batch, $quantity, $auto): CashierBatch {
            $batch = CashierBatch::query()->whereKey($batch->id)->lockForUpdate()->firstOrFail();
            abort_unless($batch->status === 'pending', 422, 'هذه الدفعة انراجعت قبل.');
            $qty = $quantity ?? $batch->quantity;
            $product = Product::query()->findOrFail($batch->product_id);
            $production = $qty > 0 ? $this->inventory->produce($ctx->store, $product, $qty, $ctx->staff?->id) : null;
            $batch->forceFill([
                'status' => 'approved',
                'approved_quantity' => $qty,
                'production_id' => $production?->id,
                'reviewed_by_staff_id' => $auto ? null : $ctx->staff?->id,
                'reviewed_by_name' => $auto ? null : ($ctx->staff?->name ?? 'المالك'),
                'reviewed_at' => now(),
            ])->save();

            return $batch;
        });
    }

    public function reject(CashierContext $ctx, CashierBatch $batch, string $reason): CashierBatch
    {
        $batch = CashierBatch::query()->whereKey($batch->id)->lockForUpdate()->firstOrFail();
        abort_unless($batch->status === 'pending', 422, 'هذه الدفعة انراجعت قبل.');
        $batch->forceFill([
            'status' => 'rejected', 'reject_reason' => $reason,
            'reviewed_by_staff_id' => $ctx->staff?->id, 'reviewed_by_name' => $ctx->staff?->name ?? 'المالك', 'reviewed_at' => now(),
        ])->save();

        return $batch;
    }

    /** How many more of a product the raw materials allow (null = no recipe). */
    public function canMake(Product $product): ?int
    {
        $items = CashierRecipeItem::query()->where('product_id', $product->id)->get();
        if ($items->isEmpty()) {
            return null;
        }
        $materials = CashierMaterial::query()->whereIn('id', $items->pluck('material_id'))->get()->keyBy('id');

        return (int) $items->map(fn ($i) => $i->per_unit > 0 ? floor(((float) ($materials[$i->material_id]->quantity ?? 0)) / $i->per_unit) : PHP_INT_MAX)->min();
    }

    public function payload(CashierBatch $b, ?Product $product = null): array
    {
        $product ??= $b->product;
        $used = $b->production_id ? (array) (\App\Models\CashierProduction::query()->find($b->production_id)?->materials ?? []) : [];

        return [
            'id' => $b->id,
            'product' => ['id' => $product?->id, 'name' => $product?->name_ar,
                'image' => is_array($product?->images) ? ($product->images[0] ?? null) : null,
                'stock' => $product?->track_stock ? (int) $product->stock_quantity : null],
            'quantity' => $b->quantity,
            'approved_quantity' => $b->approved_quantity,
            'location' => $b->location,
            'ready_at' => $b->ready_at?->toISOString(),
            'ready_in_seconds' => $b->ready_at && $b->ready_at->isFuture() ? (int) now()->diffInSeconds($b->ready_at) : 0,
            'note' => $b->note,
            'has_photo' => $b->photo_path !== null && $b->photo_deleted_at === null,
            'status' => $b->status,
            'status_label' => ['pending' => 'بانتظار المشرف', 'approved' => 'معتمد', 'rejected' => 'مرفوض'][$b->status] ?? $b->status,
            'packed_by' => $b->packed_by_name,
            'reviewed_by' => $b->reviewed_by_name,
            'reject_reason' => $b->reject_reason,
            'materials_used' => array_map(fn ($m) => ['name' => $m['name'] ?? '', 'amount' => $m['amount'] ?? 0, 'unit' => $m['unit'] ?? ''], $used),
            'created_at' => $b->created_at?->toISOString(),
        ];
    }

    public function disk(): string
    {
        return config('filesystems.disks.r2_contracts.bucket') ? 'r2_contracts' : 'public';
    }

    /** Deletes batch photos older than the given days (all stores, or one). Records stay. */
    public function purgePhotos(int $olderThanDays, ?int $storeId = null): int
    {
        if (! $this->ready()) {
            return 0;
        }
        $count = 0;
        CashierBatch::query()->whereNotNull('photo_path')->whereNull('photo_deleted_at')
            ->where('created_at', '<', now()->subDays(max(0, $olderThanDays)))
            ->when($storeId, fn ($q) => $q->where('store_id', $storeId))
            ->orderBy('id')->chunkById(200, function ($batches) use (&$count): void {
                foreach ($batches as $batch) {
                    try {
                        Storage::disk($this->disk())->delete($batch->photo_path);
                    } catch (\Throwable) {
                        // Already gone: still mark it deleted.
                    }
                    $batch->forceFill(['photo_path' => null, 'photo_deleted_at' => now()])->save();
                    $count++;
                }
            });

        return $count;
    }
}
