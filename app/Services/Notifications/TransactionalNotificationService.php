<?php

namespace App\Services\Notifications;

use App\Models\InAppNotification;
use App\Models\NotificationTemplate;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class TransactionalNotificationService
{
    public function persistPhoneVerifiedWelcome(
        ?int $userId,
        ?string $guestSessionId,
        string $idempotencySource,
        string $locale = 'ar',
    ): InAppNotification {
        return $this->persistTemplate(
            'phone_verified_welcome',
            $userId,
            $guestSessionId,
            $idempotencySource,
            $locale,
        );
    }

    public function persistTemplate(
        string $templateKey,
        ?int $userId,
        ?string $guestSessionId,
        string $idempotencySource,
        string $locale = 'ar',
        array $data = [],
    ): InAppNotification {
        if (($userId === null) === ($guestSessionId === null)) {
            throw new InvalidArgumentException('Exactly one notification recipient is required.');
        }

        $locale = in_array($locale, ['ar', 'en'], true) ? $locale : 'ar';
        $template = NotificationTemplate::query()
            ->where('key', $templateKey)
            ->where('type', 'transactional')
            ->where('is_active', true)
            ->whereIn('locale', [$locale, 'ar'])
            ->orderByRaw('CASE WHEN locale = ? THEN 0 ELSE 1 END', [$locale])
            ->first();

        if (! $template) {
            throw new RuntimeException("Active transactional notification template [{$templateKey}] was not found.");
        }

        $actorKey = $userId !== null ? 'user:'.$userId : 'guest:'.$guestSessionId;
        $idempotencyKey = hash('sha256', $templateKey.'|'.$actorKey.'|'.$idempotencySource);
        $now = now();

        DB::table('in_app_notifications')->insertOrIgnore([
            'template_id' => $template->id,
            'user_id' => $userId,
            'guest_session_id' => $guestSessionId,
            'type' => 'transactional',
            'locale' => $template->locale,
            'title' => $template->title,
            'body' => $template->body,
            'data' => $data === [] ? null : json_encode($data, JSON_THROW_ON_ERROR),
            'idempotency_key' => $idempotencyKey,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return InAppNotification::query()
            ->where('idempotency_key', $idempotencyKey)
            ->firstOrFail();
    }
}
