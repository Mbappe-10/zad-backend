<?php

namespace App\Services\App;

use App\Models\CashierDevice;
use App\Models\Store;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * «ربط جهاز»: the owner picks what a device will be and shows a one-time QR/code
 * (10 minutes). The other device scans it from حسابي and becomes that station.
 * The owner's account is never signed in on the linked device.
 */
class DeviceLinkService
{
    public const TTL = 600;

    /** Station => label. menu is a screen; the rest are shared cashier devices opened by PIN. */
    public const STATIONS = [
        'menu' => 'شاشة منيو',
        'cashier' => 'كاشير',
        'inventory' => 'المخزون',
        'packaging' => 'التغليف',
        'supervisor' => 'المشرف',
    ];

    /** Shared devices per station type (menu screens use screens_limit). */
    public const STATION_LIMIT = 6;

    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function __construct(
        private readonly CashierStaffService $staff,
        private readonly CashierScreenService $screens,
    ) {}

    public function create(Store $store, string $station, string $name, ?int $userId): array
    {
        do {
            $code = '';
            for ($i = 0; $i < 8; $i++) {
                $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
        } while (Cache::has('device_link:'.$code));
        Cache::put('device_link:'.$code, [
            'store_id' => $store->id, 'station' => $station, 'name' => $name, 'user_id' => $userId,
        ], self::TTL);

        return [
            'code' => $code,
            'qr' => 'ZADLINK:'.$code,
            'station' => $station,
            'station_label' => self::STATIONS[$station],
            'expires_in' => self::TTL,
            'expires_at' => now()->addSeconds(self::TTL)->toISOString(),
        ];
    }

    /** For the owner's waiting screen: pending, linked (with the device) or expired. */
    public function status(Store $store, string $code): array
    {
        $code = $this->clean($code);
        $linked = Cache::get('device_link_done:'.$code);
        if (is_array($linked) && (int) $linked['store_id'] === (int) $store->id) {
            return ['state' => 'linked', 'device' => $linked['device']];
        }
        $pending = Cache::get('device_link:'.$code);

        return ['state' => is_array($pending) && (int) $pending['store_id'] === (int) $store->id ? 'pending' : 'expired'];
    }

    /**
     * The device side: uses the code once.
     *
     * @return array{station: string, token: string, store_name: ?string, device_name: string}
     */
    public function link(string $code, ?string $deviceName): array
    {
        $code = $this->clean($code);
        $data = Cache::get('device_link:'.$code);
        abort_unless(is_array($data) && Cache::add('device_link_used:'.$code, 1, self::TTL), 422,
            'الكود غير صحيح أو انتهى. اطلب من صاحب المتجر كود جديد.');
        Cache::forget('device_link:'.$code);
        $store = Store::query()->findOrFail($data['store_id']);
        $name = trim((string) $deviceName) !== '' ? trim((string) $deviceName) : ($data['name'] ?: self::STATIONS[$data['station']]);

        try {
            if ($data['station'] === 'menu') {
                abort_unless($this->screens->enabled($store), 403, 'شاشات المنيو من مزايا كاشير زاد برو.');
                [$device, $token] = $this->screens->createScreen($store, 'menu', $name, $data['user_id']);
            } else {
                [$device, $token] = $this->station($store, $data['station'], $name, $data['user_id']);
            }
        } catch (\Throwable $e) {
            Cache::forget('device_link_used:'.$code);
            Cache::put('device_link:'.$code, $data, self::TTL);
            throw $e;
        }
        Cache::put('device_link_done:'.$code, [
            'store_id' => $store->id,
            'device' => ['id' => $device->id, 'name' => $device->name, 'station' => $data['station'], 'station_label' => self::STATIONS[$data['station']]],
        ], self::TTL);

        return ['station' => $data['station'], 'token' => $token, 'store_name' => $store->name_ar, 'device_name' => $device->name];
    }

    public static function stationOf(CashierDevice $device): string
    {
        if ($device->kind !== 'cashier') {
            return $device->kind;
        }
        $station = $device->settings['station'] ?? 'cashier';

        return array_key_exists($station, self::STATIONS) ? $station : 'cashier';
    }

    /** @return array{0: CashierDevice, 1: string} */
    private function station(Store $store, string $station, string $name, ?int $userId): array
    {
        abort_unless($this->staff->enabled($store), 403, 'الأجهزة والموظفين من مزايا كاشير زاد برو.');

        return DB::transaction(function () use ($store, $station, $name, $userId): array {
            Store::query()->whereKey($store->id)->lockForUpdate()->first();
            $same = CashierDevice::query()->where('store_id', $store->id)->where('kind', 'cashier')
                ->whereNull('personal_staff_id')->whereNull('revoked_at')->get()
                ->filter(fn (CashierDevice $d) => self::stationOf($d) === $station)->count();
            $limit = $station === 'cashier' ? CashierStaffService::CASHIER_DEVICE_LIMIT : self::STATION_LIMIT;
            abort_if($same >= $limit, 422, "وصلت للحد: {$limit} أجهزة ".self::STATIONS[$station].'.');
            $token = Str::random(64);
            $device = CashierDevice::query()->create([
                'store_id' => $store->id,
                'kind' => 'cashier',
                'name' => $name,
                'settings' => ['station' => $station],
                'token_hash' => hash('sha256', $token),
                'created_by_user_id' => $userId,
                'last_seen_at' => now(),
            ]);

            return [$device, $token];
        });
    }

    private function clean(string $code): string
    {
        $code = strtoupper(trim($code));
        if (str_starts_with($code, 'ZADLINK:')) {
            $code = substr($code, 8);
        }

        return preg_replace('/[^A-Z0-9]/', '', $code);
    }
}
