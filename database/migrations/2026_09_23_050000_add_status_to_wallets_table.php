<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallets', function (Blueprint $table): void {
            $table->string('status', 20)
                ->default('active')
                ->after('is_frozen')
                ->index();
        });

        DB::table('wallets')
            ->where('is_frozen', true)
            ->update(['status' => 'frozen']);

        $now = now();

        DB::table('productive_families')
            ->select(['id', 'metadata'])
            ->orderBy('id')
            ->chunkById(200, function ($families) use ($now): void {
                foreach ($families as $family) {
                    $metadata = is_string($family->metadata)
                        ? json_decode($family->metadata, true)
                        : (array) $family->metadata;

                    $this->ensureWallet(
                        [
                            App\Models\ProductiveFamily::class,
                            'family',
                            'productive_family',
                        ],
                        App\Models\ProductiveFamily::class,
                        (int) $family->id,
                        round((float) ($metadata['wallet_balance'] ?? 0), 2),
                        'LEGACY-FAMILY-'.$family->id,
                        $now,
                    );
                }
            });

        DB::table('drivers')
            ->select(['id', 'metadata'])
            ->orderBy('id')
            ->chunkById(200, function ($drivers) use ($now): void {
                foreach ($drivers as $driver) {
                    $metadata = is_string($driver->metadata)
                        ? json_decode($driver->metadata, true)
                        : (array) $driver->metadata;

                    $this->ensureWallet(
                        [
                            App\Models\Driver::class,
                            'driver',
                            'courier',
                        ],
                        App\Models\Driver::class,
                        (int) $driver->id,
                        round((float) ($metadata['wallet_balance'] ?? 0), 2),
                        'LEGACY-DRIVER-'.$driver->id,
                        $now,
                    );
                }
            });
    }

    public function down(): void
    {
        Schema::table('wallets', function (Blueprint $table): void {
            $table->dropIndex(['status']);
            $table->dropColumn('status');
        });
    }

    private function ensureWallet(
        array $ownerTypes,
        string $canonicalOwnerType,
        int $ownerId,
        float $legacyBalance,
        string $legacyReference,
        mixed $now,
    ): void {
        $wallet = DB::table('wallets')
            ->whereIn('owner_type', $ownerTypes)
            ->where('owner_id', $ownerId)
            ->where('currency', 'SAR')
            ->oldest('id')
            ->first();

        if ($wallet === null) {
            $walletId = DB::table('wallets')->insertGetId([
                'owner_type' => $canonicalOwnerType,
                'owner_id' => $ownerId,
                'currency' => 'SAR',
                'available_balance' => $legacyBalance,
                'pending_balance' => 0,
                'is_frozen' => false,
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            if ($legacyBalance > 0) {
                $this->recordLegacyBalance(
                    $walletId,
                    $legacyBalance,
                    $legacyReference,
                    $now,
                );
            }

            return;
        }

        $hasTransactions = DB::table('wallet_transactions')
            ->where('wallet_id', $wallet->id)
            ->exists();

        if (
            $legacyBalance <= 0 ||
            (float) $wallet->available_balance !== 0.0 ||
            (float) $wallet->pending_balance !== 0.0 ||
            $hasTransactions
        ) {
            return;
        }

        DB::table('wallets')
            ->where('id', $wallet->id)
            ->update([
                'available_balance' => $legacyBalance,
                'updated_at' => $now,
            ]);

        $this->recordLegacyBalance(
            (int) $wallet->id,
            $legacyBalance,
            $legacyReference,
            $now,
        );
    }

    private function recordLegacyBalance(
        int $walletId,
        float $balance,
        string $reference,
        mixed $now,
    ): void {
        DB::table('wallet_transactions')->insert([
            'wallet_id' => $walletId,
            'reference' => $reference,
            'type' => 'legacy_import',
            'amount' => $balance,
            'balance_after' => $balance,
            'status' => 'completed',
            'related_type' => null,
            'related_id' => null,
            'description' => 'ترحيل رصيد المحفظة السابق إلى النظام المالي الموحد.',
            'created_by' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
};
