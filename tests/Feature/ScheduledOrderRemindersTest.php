<?php

namespace Tests\Feature;

use App\Models\AppProfile;
use App\Models\Order;
use App\Models\PlatformControl;
use App\Models\ProductiveFamily;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ScheduledOrderRemindersTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_offsets_fire_once_at_the_boundary_and_stop_after_decline(): void
    {
        $this->freezeTime();
        $family = ProductiveFamily::create(['code'=>'F1', 'owner_name'=>'Family', 'phone'=>'0500000000', 'status'=>'active']);
        $store = Store::create(['productive_family_id'=>$family->id, 'name_ar'=>'متجر', 'slug'=>'reminders', 'status'=>'active']);
        $user = User::factory()->create();
        AppProfile::create(['user_id'=>$user->id, 'productive_family_id'=>$family->id]);
        $order = Order::create(['number'=>'TEST-SCHED', 'store_id'=>$store->id, 'status'=>'pending', 'payment_status'=>'paid', 'total'=>10]);
        $schedule = DB::table('order_schedules')->where('order_id', $order->id);
        DB::table('order_schedules')->insert(['order_id'=>$order->id, 'family_status'=>'confirmed', 'delivery_at'=>now()->addHours(49), 'occasion'=>'مناسبة']);
        PlatformControl::create(['section'=>'orders_sales', 'value'=>['scheduleReminderHours'=>[48, 6]]]);

        $this->artisan('orders:remind-scheduled')->assertSuccessful();
        $this->assertDatabaseCount('in_app_notifications', 0);
        $this->travel(1)->hours();
        $this->artisan('orders:remind-scheduled')->assertSuccessful();
        $this->artisan('orders:remind-scheduled')->assertSuccessful();
        $this->assertDatabaseCount('in_app_notifications', 1);
        $this->assertDatabaseHas('in_app_notifications', ['user_id'=>$user->id]);
        $this->travel(42)->hours();
        $schedule->update(['family_status'=>'declined']);
        $this->artisan('orders:remind-scheduled')->assertSuccessful();
        $this->assertDatabaseCount('in_app_notifications', 1);
    }
}
