<?php

namespace Tests\Feature\Auth;

use App\Enums\Ask;
use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\Source;
use App\Enums\Status;
use App\Events\OrderCreated;
use App\Events\OrderStatusChanged;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\KioskMachine;
use App\Models\Tax;
use App\Models\User;
use App\Services\Order\OrderQuoteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\Sanctum;
use Smartisan\Settings\Facades\Settings;
use Tests\TestCase;

class KioskThrottleKeysTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (! file_exists(storage_path('installed'))) {
            touch(storage_path('installed'));
        }

        $this->seedMinimalSettings();

        config([
            'app.api_key' => '123456',
            'kiosk.order_rate_limit' => 3,
        ]);

        Settings::group('order_setup')->set([
            'order_setup_food_preparation_time' => 30,
            'order_setup_schedule_order_slot_duration' => 30,
            'order_setup_delivery' => 5,
            'order_setup_takeaway' => 5,
        ]);

        Event::fake([OrderCreated::class, OrderStatusChanged::class]);

        RateLimiter::clear('kiosk:guest|127.0.0.1');
        RateLimiter::clear('anon|127.0.0.1');
    }

    /** @test */
    public function test_legitimate_kiosk_orders_pass_until_cap(): void
    {
        $context = $this->makeKioskContext('legit');
        $payload = $this->orderPayload($context['item']);
        $this->clearKioskKey($context['user']);

        for ($i = 0; $i < 3; $i++) {
            $response = $this->postKioskOrder($context['user'], $payload);
            $this->assertContains($response->status(), [200, 201], json_encode($response->json()));
        }

        $response = $this->postKioskOrder($context['user'], $payload);

        $response->assertStatus(429)->assertExactJson([
            'message' => 'Trop de commandes. Veuillez patienter.',
            'retry_after' => 60,
        ]);
    }

    /** @test */
    public function test_kiosk_orders_recovery_after_window_reset(): void
    {
        $context = $this->makeKioskContext('recovery');
        $payload = $this->orderPayload($context['item']);
        $this->clearKioskKey($context['user']);
        $start = Carbon::now();

        Carbon::setTestNow($start);

        try {
            for ($i = 0; $i < 4; $i++) {
                $response = $this->postKioskOrder($context['user'], $payload);
            }

            $response->assertStatus(429);

            Carbon::setTestNow($start->copy()->addMinutes(2));

            $response = $this->postKioskOrder($context['user'], $payload);

            $this->assertContains($response->status(), [200, 201], json_encode($response->json()));
        } finally {
            Carbon::setTestNow();
        }
    }

    /** @test */
    public function test_kiosk_orders_isolation_two_machines_same_ip(): void
    {
        $machineA = $this->makeKioskContext('machine-a');
        $machineB = $this->makeKioskContext('machine-b');
        $payloadA = $this->orderPayload($machineA['item']);
        $payloadB = $this->orderPayload($machineB['item']);

        $this->clearKioskKey($machineA['user']);
        $this->clearKioskKey($machineB['user']);

        for ($i = 0; $i < 3; $i++) {
            $response = $this->postKioskOrder($machineA['user'], $payloadA);
            $this->assertContains($response->status(), [200, 201], json_encode($response->json()));
        }

        $blocked = $this->postKioskOrder($machineA['user'], $payloadA);
        $blocked->assertStatus(429);

        for ($i = 0; $i < 3; $i++) {
            $response = $this->postKioskOrder($machineB['user'], $payloadB);
            $this->assertContains($response->status(), [200, 201], json_encode($response->json()));
        }
    }

    /** @test */
    public function test_login_lockout_email_path_429_after_max_attempts(): void
    {
        config([
            'auth.login_lockout.max_attempts' => 3,
            'auth.login_lockout.decay_minutes' => 10,
        ]);

        RateLimiter::clear('lockout@example.com|127.0.0.1');

        for ($i = 0; $i < 4; $i++) {
            $response = $this->postLogin([
                'email' => 'lockout@example.com',
                'password' => 'wrongpassword',
            ]);
        }

        $response->assertStatus(429)->assertExactJson([
            'message' => 'Trop de tentatives de connexion. Veuillez réessayer plus tard.', // [AUTH-E1C-EN heal 2026-06-10]
            'retry_after' => 600,
        ]);
    }

    /** @test */
    public function test_login_lockout_anon_fallback_when_no_email_no_username(): void
    {
        config([
            'auth.login_lockout.max_attempts' => 3,
            'auth.login_lockout.decay_minutes' => 10,
        ]);

        RateLimiter::clear('anon|127.0.0.1');

        for ($i = 0; $i < 4; $i++) {
            $response = $this->postLogin([]);
        }

        $response->assertStatus(429)->assertExactJson([
            'message' => 'Trop de tentatives de connexion. Veuillez réessayer plus tard.', // [AUTH-E1C-EN heal 2026-06-10]
            'retry_after' => 600,
        ]);
    }

    /**
     * @return array{branch: Branch, item: Item, user: User}
     */
    private function makeKioskContext(string $suffix): array
    {
        $branch = Branch::factory()->create();

        $tax = Tax::create([
            'name' => 'TVA 10 '.$suffix,
            'code' => 'TVA10-'.$suffix,
            'tax_rate' => 10,
            'type' => 2,
            'status' => 1,
        ]);

        $category = ItemCategory::forceCreate([
            'name' => 'Throttle '.$suffix,
            'slug' => 'throttle-'.$suffix,
            'status' => Status::ACTIVE,
        ]);

        $item = Item::forceCreate([
            'name' => 'Kiosk Burger '.$suffix,
            'slug' => 'kiosk-burger-'.$suffix,
            'price' => 10.00,
            'status' => Status::ACTIVE,
            'item_category_id' => $category->id,
            'tax_id' => $tax->id,
        ]);

        $user = User::factory()->create([
            'username' => 'kiosk_'.$suffix,
            'branch_id' => $branch->id,
        ]);

        KioskMachine::factory()->create([
            'machine_id' => 'machine-'.$suffix,
            'branch_id' => $branch->id,
            'user_id' => $user->id,
            'username' => 'machine_user_'.$suffix,
            'password' => bcrypt('secret'),
            'is_login' => Ask::NO,
            'status' => Status::ACTIVE,
        ]);

        return compact('branch', 'item', 'user');
    }

    /**
     * @return array<string, mixed>
     */
    private function orderPayload(Item $item): array
    {
        return [
            'order_type' => OrderType::TAKEAWAY,
            'is_advance_order' => Ask::NO,
            'source' => Source::APP,
            'payment_method' => PaymentGateway::CASH_ON_DELIVERY,
            'items' => json_encode([[
                'item_id' => $item->id,
                'quantity' => 1,
            ]]),
        ];
    }

    private function postKioskOrder(User $user, array $payload)
    {
        Sanctum::actingAs($user, ['kiosk:order']);

        return $this->withHeader('x-api-key', (string) config('app.api_key'))
            ->withServerVariables([
                'REMOTE_ADDR' => '127.0.0.1',
                'HTTP_ACCEPT' => 'application/json',
            ])
            ->postJson('/api/frontend/order', $this->withQuote($user, $payload));
    }

    private function postLogin(array $payload)
    {
        return $this->withHeader('x-api-key', (string) config('app.api_key'))
            ->withServerVariables([
                'REMOTE_ADDR' => '127.0.0.1',
                'HTTP_ACCEPT' => 'application/json',
            ])
            ->postJson('/api/auth/login', $payload);
    }

    private function clearKioskKey(User $user): void
    {
        RateLimiter::clear(sprintf('kiosk:%s|127.0.0.1', $user->id));
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function withQuote(User $user, array $payload): array
    {
        $request = Request::create('/api/frontend/order/quote', 'POST', $payload);
        $request->setUserResolver(fn (?string $guard = null): User => $user);

        $quote = app(OrderQuoteService::class)->quote($request, 'kiosk');

        return $payload + [
            'quote_token' => $quote->quote_token,
            'quote_signature' => $quote->hmac_signature,
        ];
    }
}
