<?php

namespace Tests\Feature\Api;

use App\Models\AppGuestSession;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\PlatformControl;
use App\Models\ProductiveFamily;
use App\Models\Store;
use App\Services\App\GuestSessionAuthenticator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/** The provider is ALWAYS mocked. Never charges a card or contacts Moyasar. */
class WebPaymentIntentCorrelationTest extends TestCase
{
    use RefreshDatabase;

    private array $headers;

    protected function setUp(): void
    {
        parent::setUp();
        // Controller guards read Laravel's runtime environment, not config alone.
        $this->app->detectEnvironment(fn () => 'production');
        config()->set([
            'app.env' => 'production', // HTTP always mocked, no live charge.
            'moyasar.live_publishable_key' => 'pk_live_local_fake_only',
            'moyasar.live_secret_key' => 'sk_live_local_fake_only',
            'moyasar.api_url' => 'http://127.0.0.1:9/v1',
            'zad_web_payment.enabled' => true,
            'zad_web_payment.pilot_enabled' => false, // Existing correlation tests exercise future full rollout.
            'zad_web_payment.callback_url' => 'https://zad-sync-sa.onrender.com/zad-moyasar-return.html',
            'internal_testing.enabled' => false,
        ]);
        foreach (['platform'=>['platformEnabled'=>true, 'maintenanceMode'=>false],
                  'modules'=>['financeEnabled'=>true],
                  'services'=>['paymentGatewayEnabled'=>true, 'paymentMode'=>'live'],
                  'finance'=>['paymentsEnabled'=>true]] as $section=>$changes) {
            $existing = PlatformControl::query()->where('section',$section)->value('value') ?? [];
            PlatformControl::query()->updateOrCreate(['section'=>$section],['value'=>array_replace($existing,$changes)]);
        }
        PlatformControl::forgetCachedValues();
        Http::preventStrayRequests();
    }

    private function order(): Order
    {
        $family = ProductiveFamily::query()->create([
            'code'=>'FAM-WEB-INTENT-TEST', 'owner_name'=>'Web Intent Test Family',
            'phone'=>'0500000011', 'status'=>'active',
        ]);
        $store = Store::query()->create([
            'productive_family_id'=>$family->id, 'name_ar'=>'Web Intent Store',
            'slug'=>'web-intent-store', 'status'=>'active', 'is_open'=>true,
        ]);
        $guest = AppGuestSession::query()->create([
            'device_id'=>'zad-web-intent-test-device', 'last_seen_at'=>now(),
        ]);
        [$token] = app(GuestSessionAuthenticator::class)->issue($guest);
        $this->headers = [
            'X-Guest-Session'=>(string)$guest->id,
            GuestSessionAuthenticator::HEADER=>$token,
            'X-Device-ID'=>'zad-web-intent-test-device',
        ];
        return Order::query()->create([
            'number'=>'ZAD-WEB-INTENT-TEST-1', 'store_id'=>$store->id,
            'status'=>Order::STATUS_PENDING, 'payment_status'=>Order::PAYMENT_UNPAID,
            'subtotal'=>10, 'delivery_fee'=>5, 'discount'=>0, 'tax'=>0,
            'total'=>15, 'guest_session_id'=>$guest->id,
            'contact_phone'=>'0500000012',
            'checkout_request_id'=>'local-web-intent-test-'.Str::uuid(),
        ]);
    }

    private function endpoint(Order $order, string $action): string
    {
        return "/api/v1/app/orders/{$order->id}/payment/{$action}";
    }

    private function startAttempt(Order $order): string
    {
        $response=$this->withHeaders($this->headers)->postJson($this->endpoint($order,'attempt'));
        $response->assertOk();
        $givenId=(string)$response->json('data.given_id');
        $this->assertTrue(Str::isUuid($givenId));
        return $givenId;
    }

    private function intentFixture(Order $order): array
    {
        return [
            'id'=>(string)Str::uuid(), 'status'=>'created', 'amount'=>1500,
            'currency'=>'SAR', 'description'=>'ZAD Sync order '.$order->number,
            'callback_url'=>'https://zad-sync-sa.onrender.com/zad-moyasar-return.html',
            'client_secret'=>'pi_secret_LOCAL_FAKE_NOT_REAL',
            'expires_at'=>now()->addMinutes(50)->toIso8601String(),
        ];
    }

    public function test_intent_reuses_original_attempt_and_is_idempotent(): void
    {
        $order=$this->order();
        $givenId=$this->startAttempt($order);
        $fixture=$this->intentFixture($order);
        Http::fake([
            'https://api.moyasar.com/v1/payment_intents'=>Http::response($fixture,201),
            'https://api.moyasar.com/v1/payment_intents/*'=>Http::response($fixture,200),
        ]);
        $url=$this->endpoint($order,'web-intent');
        $first=$this->withHeaders($this->headers)->postJson($url,['given_id'=>$givenId]);
        $first->assertOk()->assertJsonPath('data.given_id',$givenId)
            ->assertJsonPath('data.intent_id',$fixture['id']);
        $this->assertDatabaseCount('zad_payment_attempts',1);
        $attempt=PaymentAttempt::query()->firstOrFail();
        $this->assertSame($givenId,$attempt->idempotency_key);
        $this->assertSame('ready',$attempt->payload['web_intent_state']);
        $this->assertNotSame($fixture['client_secret'],$attempt->payload['web_client_secret_enc']);
        $second=$this->withHeaders($this->headers)->postJson($url,['given_id'=>$givenId]);
        $second->assertOk()->assertJsonPath('data.given_id',$givenId)
            ->assertJsonPath('data.intent_id',$fixture['id']);
        $this->assertDatabaseCount('zad_payment_attempts',1);
        Http::assertSentCount(2); // one creation, one lookup: never a second intent
    }

    public function test_web_intent_rejects_foreign_uuid_without_provider_call(): void
    {
        $order=$this->order();
        $this->startAttempt($order);
        $this->withHeaders($this->headers)
            ->postJson($this->endpoint($order,'web-intent'),['given_id'=>(string)Str::uuid()])
            ->assertStatus(409);
        Http::assertNothingSent();
        $this->assertDatabaseCount('zad_payment_attempts',1);
    }

    public function test_web_intent_rejects_malformed_id_without_provider_call(): void
    {
        $order=$this->order();
        $this->startAttempt($order);
        $this->withHeaders($this->headers)
            ->postJson($this->endpoint($order,'web-intent'),['given_id'=>'../fake'])
            ->assertUnprocessable();
        Http::assertNothingSent();
    }

    public function test_ambiguous_creation_response_must_not_generate_second_intent(): void
    {
        $order=$this->order();
        $givenId=$this->startAttempt($order);
        Http::fake(['https://api.moyasar.com/v1/payment_intents'=>Http::response([],503)]);
        $url=$this->endpoint($order,'web-intent');
        $this->withHeaders($this->headers)->postJson($url,['given_id'=>$givenId])->assertStatus(503);
        $this->assertSame('creating',PaymentAttempt::query()->firstOrFail()->payload['web_intent_state']);
        $this->withHeaders($this->headers)->postJson($url,['given_id'=>$givenId])->assertStatus(409);
        Http::assertSentCount(1);
        $this->assertDatabaseCount('zad_payment_attempts',1);
    }

    public function test_web_intent_never_bypasses_disabled_payment_mode(): void
    {
        $order = $this->order();
        $givenId = $this->startAttempt($order);
        $services = PlatformControl::query()->where('section', 'services')->firstOrFail();
        $values = is_array($services->value) ? $services->value : [];
        $services->update(['value' => array_replace($values, ['paymentMode' => 'disabled'])]);
        PlatformControl::forgetCachedValues();
        $this->withHeaders($this->headers)
            ->postJson($this->endpoint($order, 'web-intent'), ['given_id' => $givenId])
            ->assertUnprocessable();
        Http::assertNothingSent();
    }

    public function test_changed_amount_blocks_intent_without_provider_call(): void
    {
        $order=$this->order();
        $givenId=$this->startAttempt($order);
        $order->update(['total'=>18]);
        $this->withHeaders($this->headers)
            ->postJson($this->endpoint($order,'web-intent'),['given_id'=>$givenId])
            ->assertStatus(409);
        Http::assertNothingSent();
    }
}
