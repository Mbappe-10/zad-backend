<?php

namespace Tests\Unit;

use App\Services\CouponService;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CouponServiceTest extends TestCase
{
    private CouponService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(CouponService::class);
    }

    public function test_percentage_discount(): void
    {
        $this->assertSame(
            10.0,
            $this->service->calculateDiscount('percentage', 10, 100)
        );
    }

    public function test_percentage_respects_max_discount(): void
    {
        $this->assertSame(
            15.0,
            $this->service->calculateDiscount('percentage', 20, 100, 15)
        );
    }

    public function test_fixed_discount(): void
    {
        $this->assertSame(
            15.0,
            $this->service->calculateDiscount('fixed', 15, 100)
        );
    }

    public function test_discount_never_exceeds_subtotal(): void
    {
        $this->assertSame(
            50.0,
            $this->service->calculateDiscount('fixed', 100, 50)
        );
    }

    public function test_percentage_never_exceeds_one_hundred_percent(): void
    {
        $this->assertSame(
            100.0,
            $this->service->calculateDiscount('percentage', 250, 100)
        );
    }

    public function test_negative_values_cannot_create_negative_discount(): void
    {
        $this->assertSame(
            0.0,
            $this->service->calculateDiscount('fixed', -20, 100)
        );
    }

    public function test_decimal_discount_is_rounded_to_two_places(): void
    {
        $this->assertSame(
            10.01,
            $this->service->calculateDiscount('percentage', 10, 100.05)
        );
    }

    public function test_unsupported_discount_type_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        $this->service->calculateDiscount(
            'free_delivery',
            0,
            100,
            20
        );
    }
}