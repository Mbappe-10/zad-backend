<?php

namespace App\Services;

use App\Models\LaunchCampaignCreator;
use App\Models\PlatformRecord;

class CreatorContractCouponSyncService
{
    public function sync(
        LaunchCampaignCreator $assignment,
        ?string $previousCouponCode = null
    ): void {
        $assignment->refresh();

        $currentCode = $this->normalize(
            $assignment->coupon_code
        );

        $previousCode = $this->normalize(
            $previousCouponCode
        );

        /*
         * If the contract changed from OLD coupon to NEW coupon,
         * OLD must never remain active as a normal ZAD coupon.
         */
        if (
            $previousCode !== null
            && $previousCode !== $currentCode
        ) {
            $this->disableCoupon($previousCode, $assignment);
        }

        if ($currentCode === null) {
            return;
        }

        $record = $this->findCoupon($currentCode);

        /*
         * Existence/kind are validated by the controller before
         * linking. Fail closed here if data changes unexpectedly.
         */
        if (! $record) {
            return;
        }

        $payload = (array) ($record->payload ?? []);

        if (
            strtolower(
                trim((string) ($payload['kind'] ?? ''))
            ) !== 'coupon'
        ) {
            return;
        }

        $startsAt = $assignment->contract_starts_at;
        $endsAt = $assignment->contract_ends_at;

        $contractIsCurrentlyActive =
            $assignment->status === 'active'
            && $assignment->contract_status === 'active'
            && $startsAt !== null
            && $endsAt !== null
            && now()->betweenIncluded(
                $startsAt,
                $endsAt
            );

        /*
         * Contract owns the validity window of an influencer coupon.
         */
        $payload['code'] = $currentCode;
        $payload['creatorContractManaged'] = true;
        $payload['creatorAssignmentId'] = $assignment->id;
        $payload['creatorContractVersion'] = (int) $assignment->contract_version;

        $payload['startDate'] = $startsAt
            ?->toDateString();

        $payload['endDate'] = $endsAt
            ?->toDateString();

        $payload['status'] =
            $contractIsCurrentlyActive
                ? 'active'
                : 'inactive';

        $record->forceFill([
            'status' =>
                $contractIsCurrentlyActive
                    ? 'active'
                    : 'inactive',

            'payload' => $payload,
        ])->save();
    }

    public function disableForAssignment(
        LaunchCampaignCreator $assignment
    ): void {
        $code = $this->normalize(
            $assignment->coupon_code
        );

        if ($code !== null) {
            $this->disableCoupon($code, $assignment);
        }
    }

    private function disableCoupon(string $code, LaunchCampaignCreator $assignment): void
    {
        $record = $this->findCoupon($code);

        if (! $record) {
            return;
        }

        $payload = (array) ($record->payload ?? []);

        /*
         * Never mutate a non-coupon record.
         */
        if (
            strtolower(
                trim((string) ($payload['kind'] ?? ''))
            ) !== 'coupon'
        ) {
            return;
        }

        $payload['creatorContractManaged'] = true;
        $payload['creatorAssignmentId'] = $assignment->id;
        $payload['creatorContractVersion'] = (int) $assignment->contract_version;
        $payload['status'] = 'inactive';

        $record->forceFill([
            'status' => 'inactive',
            'payload' => $payload,
        ])->save();
    }

    private function findCoupon(
        string $normalizedCode
    ): ?PlatformRecord {
        $record = PlatformRecord::query()
            ->where('resource', 'coupons-offers')
            ->whereRaw(
                'UPPER(external_key) = ?',
                [$normalizedCode]
            )
            ->first();

        if ($record) {
            return $record;
        }

        return PlatformRecord::query()
            ->where('resource', 'coupons-offers')
            ->get()
            ->first(
                static function (
                    PlatformRecord $candidate
                ) use ($normalizedCode): bool {
                    $payload = (array) (
                        $candidate->payload ?? []
                    );

                    return strtoupper(
                        trim(
                            (string) (
                                $payload['code'] ?? ''
                            )
                        )
                    ) === $normalizedCode;
                }
            );
    }

    private function normalize(
        mixed $code
    ): ?string {
        if (! filled($code)) {
            return null;
        }

        return strtoupper(
            trim((string) $code)
        );
    }
}



