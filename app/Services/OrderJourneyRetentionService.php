<?php

namespace App\Services;

use App\Models\JourneyRetentionSetting;
use App\Models\Order;
use App\Models\OrderJourneyProof;
use App\Models\OrderLiveSession;
use App\Models\OrderStatusHistory;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class OrderJourneyRetentionService
{
    public function schedule(Order $order, bool $forceRecalculate = false): Order
    {
        if ($order->media_retention_hold) {
            if ($order->media_retention_status !== 'held') {
                $order->forceFill(['media_retention_status' => 'held'])->save();
            }

            return $order->fresh();
        }

        if ($order->media_delete_at && ! $forceRecalculate) {
            return $order;
        }

        $settings = JourneyRetentionSetting::current();
        $base = $this->completionTime($order);

        if ($base === null) {
            return $order;
        }

        $hours = $order->isCancelled()
            ? $settings->cancelled_retention_hours
            : $settings->completed_retention_hours;

        $order->forceFill([
            'media_retention_status' => 'scheduled',
            'media_delete_at' => $base->copy()->addHours($hours),
            'media_purge_last_error' => null,
        ])->save();

        return $order->fresh();
    }

    public function hold(
        Order $order,
        string $reason,
        ?CarbonInterface $until = null,
        ?int $userId = null,
    ): Order {
        $order->forceFill([
            'media_retention_status' => 'held',
            'media_retention_hold' => true,
            'media_hold_until' => $until,
            'media_retention_reason' => $reason,
        ])->save();

        $this->audit($order, 'تم تجميد حذف وسائط رحلة الطلب: '.$reason, $userId, [
            'retention_action' => 'hold',
            'hold_until' => $until?->toIso8601String(),
        ]);

        return $order->fresh();
    }

    public function release(Order $order, string $reason, ?int $userId = null): Order
    {
        $order->forceFill([
            'media_retention_hold' => false,
            'media_hold_until' => null,
            'media_retention_reason' => $reason,
            'media_retention_status' => 'pending',
            'media_delete_at' => null,
        ])->save();

        $this->schedule($order->fresh(), true);
        $this->audit($order, 'تم إلغاء تجميد حذف وسائط الرحلة: '.$reason, $userId, [
            'retention_action' => 'release',
        ]);

        return $order->fresh();
    }

    public function extend(Order $order, int $hours, string $reason, ?int $userId = null): Order
    {
        if ($hours < 1 || $hours > 8760) {
            throw new RuntimeException('مدة التمديد يجب أن تكون بين ساعة وسنة.');
        }

        $currentDeleteAt = $order->media_delete_at
            ? Carbon::parse($order->media_delete_at)
            : null;

        $base = $currentDeleteAt?->isFuture()
            ? $currentDeleteAt
            : now();

        $order->forceFill([
            'media_retention_status' => $order->media_retention_hold ? 'held' : 'scheduled',
            'media_delete_at' => $base->copy()->addHours($hours),
            'media_retention_reason' => $reason,
            'media_purge_last_error' => null,
        ])->save();

        $this->audit($order, "تم تمديد الاحتفاظ بوسائط الرحلة {$hours} ساعة: {$reason}", $userId, [
            'retention_action' => 'extend',
            'hours' => $hours,
            'delete_at' => Carbon::parse($order->media_delete_at)->toIso8601String(),
        ]);

        return $order->fresh();
    }

    public function purge(Order $order, ?int $userId = null, string $reason = 'انتهاء مدة الاحتفاظ'): Order
    {
        $order->refresh();

        if ($order->media_retention_hold) {
            throw new RuntimeException('لا يمكن حذف وسائط طلب مجمد.');
        }

        if ($order->media_retention_status === 'purged') {
            return $order;
        }

        $order->forceFill([
            'media_retention_status' => 'purging',
            'media_purge_attempts' => ((int) $order->media_purge_attempts) + 1,
            'media_purge_last_error' => null,
        ])->save();

        try {
            $proofs = OrderJourneyProof::query()->where('order_id', $order->id)->get();
            $sessions = OrderLiveSession::query()->where('order_id', $order->id)->get();

            foreach ($proofs as $proof) {
                $metadata = $this->deletePublicFile($proof->photo_path);

                $proof->forceFill([
                    'photo_path' => null,
                    'photo_checksum' => $proof->photo_checksum ?: $metadata['checksum'],
                    'photo_size_bytes' => $proof->photo_size_bytes ?: $metadata['size'],
                    'photo_mime_type' => $proof->photo_mime_type ?: $metadata['mime'],
                    'photo_purged_at' => now(),
                ])->save();
            }

            foreach ($sessions as $session) {
                $metadata = $this->deletePublicFile($session->final_photo_path);

                $session->forceFill([
                    'final_photo_path' => null,
                    'final_photo_checksum' => $session->final_photo_checksum ?: $metadata['checksum'],
                    'final_photo_size_bytes' => $session->final_photo_size_bytes ?: $metadata['size'],
                    'final_photo_mime_type' => $session->final_photo_mime_type ?: $metadata['mime'],
                    'final_photo_purged_at' => now(),
                ])->save();
            }

            DB::transaction(function () use ($order, $userId, $reason): void {
                $order->forceFill([
                    'media_retention_status' => 'purged',
                    'media_purged_at' => now(),
                    'media_purged_by_user_id' => $userId,
                    'media_retention_reason' => $reason,
                    'media_purge_last_error' => null,
                ])->save();

                $this->audit($order, 'تم حذف وسائط رحلة الطلب مع إبقاء سجل التدقيق.', $userId, [
                    'retention_action' => 'purge',
                    'reason' => $reason,
                ]);
            });

            return $order->fresh();
        } catch (Throwable $exception) {
            $order->forceFill([
                'media_retention_status' => 'failed',
                'media_purge_last_error' => mb_substr($exception->getMessage(), 0, 2000),
            ])->save();

            throw $exception;
        }
    }

    private function completionTime(Order $order): ?CarbonInterface
    {
        if ($order->isCancelled()) {
            return $order->cancelled_at ?? $order->updated_at;
        }

        if ($order->isCompleted()) {
            return $order->delivered_at ?? $order->updated_at;
        }

        return null;
    }

    private function deletePublicFile(?string $originalPath): array
    {
        $path = $this->normalisePublicPath($originalPath);
        $empty = ['checksum' => null, 'size' => null, 'mime' => null];

        if ($path === null) {
            return $empty;
        }

        $disk = Storage::disk('public');

        if (! $disk->exists($path)) {
            return $empty;
        }

        $absolutePath = $disk->path($path);
        $metadata = [
            'checksum' => is_file($absolutePath) ? hash_file('sha256', $absolutePath) : null,
            'size' => $disk->size($path),
            'mime' => $disk->mimeType($path) ?: null,
        ];

        if (! $disk->delete($path)) {
            throw new RuntimeException("تعذر حذف الملف {$path} من التخزين.");
        }

        return $metadata;
    }

    private function normalisePublicPath(?string $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        $urlPath = parse_url($value, PHP_URL_PATH);
        $path = rawurldecode(is_string($urlPath) ? $urlPath : $value);
        $path = str_replace('\\', '/', $path);

        if (str_contains($path, '/storage/')) {
            $path = explode('/storage/', $path, 2)[1];
        }

        $path = ltrim($path, '/');

        if ($path === '' || str_contains($path, '..')) {
            throw new RuntimeException('مسار ملف التوثيق غير آمن.');
        }

        return $path;
    }

    private function audit(Order $order, string $note, ?int $userId, array $metadata): void
    {
        OrderStatusHistory::query()->create([
            'order_id' => $order->id,
            'from_status' => $order->status,
            'to_status' => $order->status,
            'note' => $note,
            'metadata' => $metadata,
            'changed_by' => $userId,
        ]);
    }
}