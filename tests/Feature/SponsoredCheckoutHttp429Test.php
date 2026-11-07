<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class SponsoredCheckoutHttp429Test extends TestCase
{
    public function test_sixth_sponsored_attempt_returns_429(): void
    {
        Cache::flush();

        Route::post('/api/__test_sponsored_checkout_limit', function () {
            return response()->json(['ok' => true]);
        })->middleware('throttle:sponsored-checkout');

        for ($attempt = 1; $attempt <= 6; $attempt++) {
            $response = $this->postJson(
                '/api/__test_sponsored_checkout_limit',
                [
                    'sponsored_coupon_code' => 'ZAD620',
                    'guest_session_id' => 'test-guest-http-429',
                ],
                ['REMOTE_ADDR' => '192.0.2.25']
            );

            if ($attempt <= 5) {
                $response->assertOk();
            } else {
                $response->assertStatus(429)
                    ->assertJsonPath(
                        'code',
                        'sponsored_checkout_rate_limited'
                    );
            }
        }
    }

    public function test_normal_requests_are_not_subject_to_sponsored_limit(): void
    {
        Cache::flush();

        Route::post('/api/__test_normal_checkout_limit', function () {
            return response()->json(['ok' => true]);
        })->middleware('throttle:sponsored-checkout');

        for ($attempt = 1; $attempt <= 7; $attempt++) {
            $this->postJson(
                '/api/__test_normal_checkout_limit',
                ['guest_session_id' => 'normal-test-guest']
            )->assertOk();
        }
    }
}