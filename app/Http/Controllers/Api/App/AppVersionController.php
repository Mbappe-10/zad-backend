<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Models\PlatformControl;
use Illuminate\Http\JsonResponse;
use Throwable;

class AppVersionController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $settings = [];

        try {
            $control = PlatformControl::query()
                ->where('section', 'app_updates')
                ->first();

            if ($control && is_array($control->value)) {
                $settings = $control->value;
            }
        } catch (Throwable $exception) {
            report($exception);
        }

        $android = [
            'latest_version' => (string) (
                $settings['androidLatestVersion']
                ?? config('app_version.android.latest_version', '1.0.0')
            ),
            'latest_build' => (int) (
                $settings['androidLatestBuild']
                ?? config('app_version.android.latest_build', 1)
            ),
            'minimum_build' => (int) (
                $settings['androidMinimumBuild']
                ?? config('app_version.android.minimum_build', 1)
            ),
            'force_update' => (bool) (
                $settings['androidForceUpdate']
                ?? config('app_version.android.force_update', false)
            ),
            'store_url' => (string) (
                $settings['androidStoreUrl']
                ?? config(
                    'app_version.android.store_url',
                    'https://play.google.com/store/apps/details?id=com.zadsync.app'
                )
            ),
        ];

        $ios = [
            'latest_version' => (string) (
                $settings['iosLatestVersion']
                ?? config('app_version.ios.latest_version', '1.0.0')
            ),
            'latest_build' => (int) (
                $settings['iosLatestBuild']
                ?? config('app_version.ios.latest_build', 1)
            ),
            'minimum_build' => (int) (
                $settings['iosMinimumBuild']
                ?? config('app_version.ios.minimum_build', 1)
            ),
            'force_update' => (bool) (
                $settings['iosForceUpdate']
                ?? config('app_version.ios.force_update', false)
            ),
            'store_url' => (string) (
                $settings['iosStoreUrl']
                ?? config('app_version.ios.store_url', '')
            ),
        ];

        return response()->json([
            'android' => $android,
            'ios' => $ios,
        ])->header(
            'Cache-Control',
            'no-store, no-cache, must-revalidate'
        );
    }
}