<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class HeiGitRoutingService
{
    public function distanceKm(
        float $pickupLatitude,
        float $pickupLongitude,
        float $deliveryLatitude,
        float $deliveryLongitude
    ): float {
        foreach ([
            [$pickupLatitude, 90], [$deliveryLatitude, 90],
            [$pickupLongitude, 180], [$deliveryLongitude, 180],
        ] as [$coordinate, $limit]) {
            if (!is_finite($coordinate) || abs($coordinate) > $limit) {
                throw new HttpResponseException(response()->json([
                    'message' => 'إحداثيات التوصيل غير صالحة.',
                ], 422));
            }
        }

        $key = trim((string) config('zad_routing.api_key', ''));
        if ($key === '') {
            throw new HttpResponseException(response()->json([
                'message' => 'خدمة حساب المسافة غير مهيأة.',
            ], 503));
        }

        try {
            $result = Http::acceptJson()
                ->withHeaders(['Authorization' => $key])
                ->connectTimeout(4)
                ->timeout(12)
                ->post('https://api.heigit.org/openrouteservice/v2/directions/driving-car/json', [
                    'coordinates' => [
                        [$pickupLongitude, $pickupLatitude],
                        [$deliveryLongitude, $deliveryLatitude],
                    ],
                    'instructions' => false,
                    'geometry' => false,
                ]);
        } catch (ConnectionException $e) {
            Log::warning('ZAD routing provider connection failed');
            throw new HttpResponseException(response()->json([
                'message' => 'تعذر حساب مسافة التوصيل الآن. حاول مرة أخرى.',
            ], 503));
        }

        if (!$result->successful()) {
            Log::warning('ZAD routing provider returned error', [
                'http_status' => $result->status(),
            ]);
            throw new HttpResponseException(response()->json([
                'message' => 'تعذر حساب مسافة التوصيل الآن. حاول مرة أخرى.',
            ], 503));
        }

        $meters = data_get($result->json(), 'routes.0.summary.distance');
        if (!is_numeric($meters) || !is_finite((float) $meters)
            || (float) $meters < 0 || (float) $meters > 500000) {
            Log::warning('ZAD routing provider returned invalid distance');
            throw new HttpResponseException(response()->json([
                'message' => 'تعذر تحديد مسافة طريق صالحة للطلب.',
            ], 503));
        }

        // Pricing receives exact route distance in km, without early rounding.
        return (float) $meters / 1000.0;
    }
}
