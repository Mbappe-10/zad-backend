<?php

namespace App\Services;

use App\Models\LaunchAttributionSession;
use App\Models\LaunchCampaign;
use App\Models\LaunchCampaignCreator;
use App\Models\LaunchCampaignFamily;
use App\Models\LaunchCreator;
use App\Models\LaunchEvent;
use App\Models\LaunchOrderAttribution;
use App\Models\Order;
use App\Models\ProductiveFamily;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LaunchAttributionService
{
    public const EVENT_TYPES = [
        'visit',
        'qr_scan',
        'store_view',
        'product_view',
        'add_to_cart',
        'checkout',
        'paid_order',
        'signup',
    ];

    /**
     * Resolve a public tracking code without exposing any sensitive fields.
     *
     * @return array<string, mixed>|null
     */
    public function resolveTrackingCode(string $code): ?array
    {
        $code = trim($code);

        $creatorAssignment = LaunchCampaignCreator::query()
            ->with(['campaign.city', 'creator'])
            ->where('tracking_code', $code)
            ->where('status', 'active')
            ->first();

        if (
            $creatorAssignment !== null
            && $creatorAssignment->contractIsActive()
            && $this->campaignCanTrack($creatorAssignment->campaign)
        ) {
            return [
                'kind' => 'creator',
                'campaign_id' => $creatorAssignment->campaign_id,
                'campaign_code' => $creatorAssignment->campaign->code,
                'creator_id' => $creatorAssignment->creator_id,
                'creator_code' => $creatorAssignment->creator->code,
                'family_id' => null,
                'booth' => null,
                'source' => 'launch',
                'medium' => 'referral',
                'landing_path' => $creatorAssignment->campaign->landing_path,
                'tracking_code' => $code,
            ];
        }

        $familyAssignment = LaunchCampaignFamily::query()
            ->with(['campaign.city', 'family.store'])
            ->where('tracking_code', $code)
            ->where('status', 'active')
            ->first();

        if ($familyAssignment !== null && $this->campaignCanTrack($familyAssignment->campaign)) {
            $storeId = $familyAssignment->family?->store?->id;

            return [
                'kind' => filled($familyAssignment->booth_code) ? 'booth' : 'family',
                'campaign_id' => $familyAssignment->campaign_id,
                'campaign_code' => $familyAssignment->campaign->code,
                'creator_id' => null,
                'creator_code' => null,
                'family_id' => $familyAssignment->family_id,
                'booth' => $familyAssignment->booth_code,
                'source' => 'launch',
                'medium' => 'qr',
                'landing_path' => $storeId !== null
                    ? '/stores/'.$storeId
                    : $familyAssignment->campaign->landing_path,
                'tracking_code' => $code,
            ];
        }

        $campaign = LaunchCampaign::query()
            ->where('tracking_code', $code)
            ->first();

        if ($campaign === null || ! $this->campaignCanTrack($campaign)) {
            return null;
        }

        return [
            'kind' => 'campaign',
            'campaign_id' => $campaign->id,
            'campaign_code' => $campaign->code,
            'creator_id' => null,
            'creator_code' => null,
            'family_id' => null,
            'booth' => null,
            'source' => 'launch',
            'medium' => 'qr',
            'landing_path' => $campaign->landing_path,
            'tracking_code' => $code,
        ];
    }

    /** @return array<string, mixed> */
    public function resolveParameters(array $parameters): array
    {
        if (filled($parameters['tracking_code'] ?? null)) {
            $resolved = $this->resolveTrackingCode((string) $parameters['tracking_code']);

            if ($resolved !== null) {
                return $this->mergeSafeParameters($resolved, $parameters);
            }
        }

        $campaign = null;
        $campaignValue = trim((string) ($parameters['campaign'] ?? ''));

        if ($campaignValue !== '') {
            $campaign = LaunchCampaign::query()
                ->where(function ($query) use ($campaignValue): void {
                    $query->where('code', $campaignValue)
                        ->orWhere('tracking_code', $campaignValue);
                })
                ->first();
        }

        $creator = null;
        $creatorValue = trim((string) ($parameters['creator'] ?? ''));

        if ($creatorValue !== '') {
            $creator = LaunchCreator::query()
                ->where(function ($query) use ($creatorValue): void {
                    $query->where('code', $creatorValue)
                        ->orWhere('handle', $creatorValue);
                })
                ->first();
        }

        $family = null;
        $familyValue = trim((string) ($parameters['family'] ?? ''));

        if ($familyValue !== '') {
            $family = ProductiveFamily::query()
                ->where(function ($query) use ($familyValue): void {
                    if (ctype_digit($familyValue)) {
                        $query->whereKey((int) $familyValue)->orWhere('code', $familyValue);
                    } else {
                        $query->where('code', $familyValue);
                    }
                })
                ->first();
        }

        return [
            'campaign_id' => $campaign?->id,
            'campaign_code' => $campaign?->code,
            'creator_id' => $creator?->id,
            'creator_code' => $creator?->code,
            'family_id' => $family?->id,
            'booth' => $this->cleanLabel($parameters['booth'] ?? null),
            'source' => $this->cleanLabel($parameters['source'] ?? 'launch'),
            'medium' => $this->cleanLabel($parameters['medium'] ?? 'referral'),
            'tracking_code' => null,
        ];
    }

    /**
     * @return array{session: LaunchAttributionSession, visitor_token: string, session_token: string}
     */
    public function touchSession(
        ?string $visitorToken,
        ?string $sessionToken,
        array $touch,
        ?string $appGuestSessionId = null,
        ?int $userId = null,
    ): array {
        $visitorToken = $this->validToken($visitorToken) ?: $this->newToken();
        $sessionToken = $this->validToken($sessionToken) ?: $this->newToken();
        $visitorHash = $this->hashToken($visitorToken);
        $sessionHash = $this->hashToken($sessionToken);
        $now = now();

        $session = LaunchAttributionSession::query()
            ->where('session_hash', $sessionHash)
            ->first();

        if ($session === null) {
            $session = LaunchAttributionSession::query()->create([
                'id' => (string) Str::uuid(),
                'visitor_hash' => $visitorHash,
                'session_hash' => $sessionHash,
                'app_guest_session_id' => $appGuestSessionId,
                'user_id' => $userId,
                'first_campaign_id' => $touch['campaign_id'] ?? null,
                'first_creator_id' => $touch['creator_id'] ?? null,
                'first_family_id' => $touch['family_id'] ?? null,
                'first_source' => $touch['source'] ?? null,
                'first_medium' => $touch['medium'] ?? null,
                'first_booth' => $touch['booth'] ?? null,
                'last_campaign_id' => $touch['campaign_id'] ?? null,
                'last_creator_id' => $touch['creator_id'] ?? null,
                'last_family_id' => $touch['family_id'] ?? null,
                'last_source' => $touch['source'] ?? null,
                'last_medium' => $touch['medium'] ?? null,
                'last_booth' => $touch['booth'] ?? null,
                'first_seen_at' => $now,
                'last_seen_at' => $now,
                'expires_at' => $now->copy()->addDays(30),
            ]);
        } else {
            $session->forceFill([
                'app_guest_session_id' => $appGuestSessionId ?: $session->app_guest_session_id,
                'user_id' => $userId ?: $session->user_id,
                'last_campaign_id' => $touch['campaign_id'] ?? $session->last_campaign_id,
                'last_creator_id' => $touch['creator_id'] ?? $session->last_creator_id,
                'last_family_id' => $touch['family_id'] ?? $session->last_family_id,
                'last_source' => $touch['source'] ?? $session->last_source,
                'last_medium' => $touch['medium'] ?? $session->last_medium,
                'last_booth' => $touch['booth'] ?? $session->last_booth,
                'last_seen_at' => $now,
                'expires_at' => $now->copy()->addDays(30),
            ])->save();
        }

        return [
            'session' => $session,
            'visitor_token' => $visitorToken,
            'session_token' => $sessionToken,
        ];
    }

    public function recordEvent(
        LaunchAttributionSession $session,
        string $eventType,
        array $attributes = [],
        ?string $clientEventId = null,
    ): bool {
        if (! in_array($eventType, self::EVENT_TYPES, true)) {
            return false;
        }

        $occurredAt = now();
        $dedupeDiscriminator = $clientEventId ?: implode('|', [
            $session->id,
            $eventType,
            $attributes['product_id'] ?? '',
            $attributes['order_id'] ?? '',
            $attributes['tracking_code'] ?? '',
            $occurredAt->copy()->startOfMinute()->timestamp,
        ]);

        return LaunchEvent::query()->insertOrIgnore([
            'event_key' => hash('sha256', $dedupeDiscriminator),
            'session_id' => $session->id,
            'visitor_hash' => $session->visitor_hash,
            'user_id' => $session->user_id,
            'campaign_id' => $attributes['campaign_id'] ?? $session->last_campaign_id,
            'creator_id' => $attributes['creator_id'] ?? $session->last_creator_id,
            'family_id' => $attributes['family_id'] ?? $session->last_family_id,
            'store_id' => $attributes['store_id'] ?? null,
            'product_id' => $attributes['product_id'] ?? null,
            'order_id' => $attributes['order_id'] ?? null,
            'event_type' => $eventType,
            'source' => $attributes['source'] ?? $session->last_source,
            'medium' => $attributes['medium'] ?? $session->last_medium,
            'booth' => $attributes['booth'] ?? $session->last_booth,
            'occurred_at' => $occurredAt,
            'metadata' => filled($attributes['metadata'] ?? null)
                ? json_encode($attributes['metadata'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : null,
            'created_at' => $occurredAt,
            'updated_at' => $occurredAt,
        ]) === 1;
    }

    public function claim(
        string $sessionToken,
        int $userId,
        ?string $appGuestSessionId = null,
    ): ?LaunchAttributionSession {
        $session = $this->sessionFromToken($sessionToken);

        if ($session === null) {
            return null;
        }

        $session->forceFill([
            'user_id' => $userId,
            'app_guest_session_id' => $appGuestSessionId ?: $session->app_guest_session_id,
            'last_seen_at' => now(),
        ])->save();

        $this->recordEvent($session, 'signup', [], 'signup|'.$session->id.'|'.$userId);

        return $session;
    }

    public function attributeOrder(
        Order $order,
        ?string $sessionToken = null,
        ?string $appGuestSessionId = null,
    ): ?LaunchOrderAttribution {
        $session = $this->sessionFromToken($sessionToken);

        if ($session === null && filled($appGuestSessionId)) {
            $session = LaunchAttributionSession::query()
                ->where('app_guest_session_id', $appGuestSessionId)
                ->latest('last_seen_at')
                ->first();
        }

        if ($session === null) {
            return null;
        }

        $familyId = DB::table('stores')
            ->where('id', $order->store_id)
            ->value('productive_family_id');
        [$commissionType, $commissionRate] = $this->creatorCommissionSnapshot($session);
        $commissionAmount = 0.0;

        $attribution = LaunchOrderAttribution::query()->firstOrCreate(
            ['order_id' => $order->id],
            [
                'session_id' => $session->id,
                'user_id' => $session->user_id,
                'family_id' => $familyId ?: $session->last_family_id,
                'first_campaign_id' => $session->first_campaign_id,
                'first_creator_id' => $session->first_creator_id,
                'first_family_id' => $session->first_family_id,
                'first_source' => $session->first_source,
                'first_medium' => $session->first_medium,
                'first_booth' => $session->first_booth,
                'last_campaign_id' => $session->last_campaign_id,
                'last_creator_id' => $session->last_creator_id,
                'last_family_id' => $session->last_family_id,
                'last_source' => $session->last_source,
                'last_medium' => $session->last_medium,
                'last_booth' => $session->last_booth,
                'creator_commission_type' => $commissionType,
                'creator_commission_rate' => $commissionRate,
                'creator_commission_amount' => $commissionAmount,
                'creator_commission_status' => 'pending',
                'attributed_at' => now(),
            ],
        );

        $session->forceFill([
            'app_guest_session_id' => $appGuestSessionId ?: $session->app_guest_session_id,
            'last_seen_at' => now(),
        ])->save();

        $this->recordEvent($session, 'checkout', [
            'order_id' => $order->id,
            'store_id' => $order->store_id,
            'family_id' => $familyId,
        ], 'checkout|'.$order->id);

        return $attribution;
    }

    public function markOrderPaid(Order $order): void
    {
        $attribution = LaunchOrderAttribution::query()
            ->where('order_id', $order->id)
            ->first();

        if ($attribution === null) {
            return;
        }
        $attribution->forceFill([
            'creator_commission_amount' => 0.0,
            'creator_commission_status' => $attribution->last_creator_id === null
                ? 'not_applicable'
                : 'pending',
        ])->save();

        if ($attribution->session_id === null) {
            return;
        }

        $session = LaunchAttributionSession::query()->find($attribution->session_id);

        if ($session !== null) {
            $this->recordEvent($session, 'paid_order', [
                'order_id' => $order->id,
                'store_id' => $order->store_id,
                'family_id' => $attribution->family_id,
            ], 'paid_order|'.$order->id);
        }
    }

    public function sessionFromToken(?string $sessionToken): ?LaunchAttributionSession
    {
        $sessionToken = $this->validToken($sessionToken);

        if ($sessionToken === null) {
            return null;
        }

        return LaunchAttributionSession::query()
            ->where('session_hash', $this->hashToken($sessionToken))
            ->first();
    }

    /**
     * Snapshot the creator commission rule at attribution time.
     *
     * Historical orders keep the commission rule that was active
     * when the order was attributed.
     *
     * @return array{0: ?string, 1: float}
     */
    private function creatorCommissionSnapshot(
        LaunchAttributionSession $session,
    ): array {
        if ($session->last_campaign_id === null || $session->last_creator_id === null) {
            return [null, 0.0];
        }

        $assignment = LaunchCampaignCreator::query()
            ->where('campaign_id', $session->last_campaign_id)
            ->where('creator_id', $session->last_creator_id)
            ->where('status', 'active')
            ->first();

        if ($assignment === null) {
            return [null, 0.0];
        }

        return [
            $assignment->commission_type,
            max(0, (float) $assignment->commission_value),
        ];
    }

    /**
     * Revenue belonging to ZAD and eligible for creator commission.
     *
     * Creator commission is deliberately excluded from this calculation
     * to avoid a circular calculation.
     */
    public function finalizeCreatorCommission(Order $order): void
    {
        $attribution = LaunchOrderAttribution::query()
            ->where('order_id', $order->id)
            ->first();

        if ($attribution === null || $attribution->last_creator_id === null) {
            return;
        }

        $eligibleZadRevenue = $this->eligibleZadRevenue($order);

        $creatorCommissionAmount = $this->creatorCommissionAmount(
            (string) ($attribution->creator_commission_type ?? ''),
            (float) ($attribution->creator_commission_rate ?? 0),
            $eligibleZadRevenue,
        );

        $attribution->forceFill([
            'creator_commission_amount' => $creatorCommissionAmount,
            'creator_commission_status' => 'accrued',
        ])->save();
    }

    private function eligibleZadRevenue(Order $order): float
    {
        $platformRevenue = (float) DB::table('order_settlements')
            ->where('order_id', $order->id)
            ->whereNotIn('status', [
                'reversed',
                'cancelled',
                'rejected',
            ])
            ->value('platform_total');

        $providerFees = (float) DB::table('payments')
            ->where('order_id', $order->id)
            ->whereNotIn('status', [
                'failed',
                'cancelled',
                'canceled',
                'rejected',
            ])
            ->sum('provider_fee');

        $refundedAmount = (float) DB::table('refunds')
            ->where('order_id', $order->id)
            ->whereIn('status', [
                'approved',
                'completed',
            ])
            ->sum('amount');

        $orderTotal = max(0, (float) $order->total);

        $refundRatio = $orderTotal > 0
            ? min(1, max(0, $refundedAmount / $orderTotal))
            : 0.0;

        $platformRevenueAfterRefunds = $platformRevenue * (1 - $refundRatio);

        return round(max(
            0,
            $platformRevenueAfterRefunds - $providerFees
        ), 2);
    }

    private function creatorCommissionAmount(
        string $commissionType,
        float $commissionRate,
        float $eligibleZadRevenue,
    ): float {
        if ($eligibleZadRevenue <= 0 || $commissionRate <= 0) {
            return 0.0;
        }

        $amount = $commissionType === 'fixed'
            ? $commissionRate
            : $eligibleZadRevenue * ($commissionRate / 100);

        return round(
            min($eligibleZadRevenue, max(0, $amount)),
            2
        );
    }

    private function campaignCanTrack(?LaunchCampaign $campaign): bool
    {
        if ($campaign === null || ! in_array($campaign->status, ['active', 'live', 'scheduled'], true)) {
            return false;
        }

        return ($campaign->starts_at === null || $campaign->starts_at->lte(now()))
            && ($campaign->ends_at === null || $campaign->ends_at->gte(now()));
    }

    private function mergeSafeParameters(array $resolved, array $parameters): array
    {
        $resolved['source'] = $this->cleanLabel($parameters['source'] ?? $resolved['source']);
        $resolved['medium'] = $this->cleanLabel($parameters['medium'] ?? $resolved['medium']);
        $resolved['booth'] = $this->cleanLabel($parameters['booth'] ?? $resolved['booth']);

        return $resolved;
    }

    private function newToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private function validToken(?string $token): ?string
    {
        if (! is_string($token)) {
            return null;
        }

        $token = trim($token);

        return preg_match('/^[A-Za-z0-9_-]{32,128}$/', $token) === 1
            ? $token
            : null;
    }

    private function hashToken(string $token): string
    {
        return hash_hmac('sha256', $token, (string) config('app.key'));
    }

    private function cleanLabel(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        return Str::limit(preg_replace('/[^\pL\pN_.-]+/u', '-', $value) ?: '', 80, '');
    }
}
