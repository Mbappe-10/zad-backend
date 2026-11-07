<?php

namespace Tests\Feature;

use App\Models\Order;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class SponsoredOperationalReleaseTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (app()->environment() !== 'testing'
            || DB::getDefaultConnection() !== 'sqlite'
            || DB::connection()->getDatabaseName() !== ':memory:') {
            throw new \RuntimeException('Only in-memory SQLite testing is permitted.');
        }

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('status');
            $table->string('payment_status');
            $table->string('contact_verification_state')->nullable();
            $table->softDeletes();
        });

        Schema::create('sponsored_order_fundings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->string('funding_source');
            $table->string('status');
        });
    }

    private function makeOrder(
        string $payment,
        ?string $verification,
        bool $funded
    ): Order {
        $id = DB::table('orders')->insertGetId([
            'status' => Order::STATUS_READY,
            'payment_status' => $payment,
            'contact_verification_state' => $verification,
        ]);

        if ($funded) {
            DB::table('sponsored_order_fundings')->insert([
                'order_id' => $id,
                'funding_source' => 'zad_platform',
                'status' => 'committed',
            ]);
        }

        return Order::query()->findOrFail($id);
    }

    private function assertReleased(Order $order, bool $expected): void
    {
        $this->assertSame(
            $expected,
            $order->isPaidAndContactVerified()
        );

        $this->assertSame(
            $expected,
            $order->isAssignable()
        );

        $this->assertSame(
            $expected,
            Order::query()
                ->operationallyReleased()
                ->whereKey($order->id)
                ->exists()
        );
    }

    public function test_normal_paid_verified_order_is_released(): void
    {
        $this->assertReleased(
            $this->makeOrder(Order::PAYMENT_PAID, 'verified', false),
            true
        );
    }

    public function test_normal_paid_pending_otp_is_blocked(): void
    {
        $this->assertReleased(
            $this->makeOrder(Order::PAYMENT_PAID, 'pending', false),
            false
        );
    }

    public function test_sponsored_pending_otp_is_released(): void
    {
        $this->assertReleased(
            $this->makeOrder(Order::PAYMENT_UNPAID, 'pending', true),
            true
        );
    }

    public function test_unpaid_unsponsored_order_is_blocked(): void
    {
        $this->assertReleased(
            $this->makeOrder(Order::PAYMENT_UNPAID, 'pending', false),
            false
        );
    }
}