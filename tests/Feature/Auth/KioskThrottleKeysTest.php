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

        // [ONB-11 2026-08-28] Le message a CHANGÉ délibérément : il était en anglais
        // et ne disait pas le délai, alors que `retry_after` était calculé juste à
        // côté. On n'épingle plus la phrase — une formulation se retouche — mais les
        // deux choses qui comptent : le délai machine, et le fait que le commerçant
        // SOIT informé de la durée. Épingler la phrase ferait rougir ce banc à chaque
        // reformulation, et il finirait par être neutralisé.
        $response->assertStatus(429);
        $this->assertSame(600, $response->json('retry_after'));
        $this->assertStringContainsString(
            '10',
            (string) $response->json('message'),
            "Le message doit DIRE combien de temps attendre. Sans la durée, le\n"
            . "commerçant ne sait pas s'il patiente une minute ou s'il rappelle\n"
            . 'quelqu\'un. Reçu : ' . $response->json('message')
        );
        // ⚠️ La suite tourne en locale `en` : l'anglais y est donc CORRECT. Ce qu'on
        // vérifie n'est pas « ce n'est pas de l'anglais » — ma première version
        // affirmait cela et se trompait — mais que le message SUIT LA LOCALE, au lieu
        // d'être écrit en dur. En production la locale est `fr` (ADR-007), et c'est
        // ce que le commerçant lira.
        $this->assertStringNotContainsString(
            ':minutes',
            (string) $response->json('message'),
            "Le paramètre n'est pas substitué : le commerçant lirait « :minutes » "
            . 'en toutes lettres.'
        );

        app()->setLocale('fr');
        $this->assertStringContainsString(
            'Trop de tentatives',
            trans('auth.trop_de_tentatives', ['minutes' => 10]),
            "Le message n'existe pas en français : il serait écrit en dur, ce qui est "
            . 'le défaut corrigé ici.'
        );
        app()->setLocale('en');
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

        // [ONB-11 2026-08-28] Le message a CHANGÉ délibérément : il était en anglais
        // et ne disait pas le délai, alors que `retry_after` était calculé juste à
        // côté. On n'épingle plus la phrase — une formulation se retouche — mais les
        // deux choses qui comptent : le délai machine, et le fait que le commerçant
        // SOIT informé de la durée. Épingler la phrase ferait rougir ce banc à chaque
        // reformulation, et il finirait par être neutralisé.
        $response->assertStatus(429);
        $this->assertSame(600, $response->json('retry_after'));
        $this->assertStringContainsString(
            '10',
            (string) $response->json('message'),
            "Le message doit DIRE combien de temps attendre. Sans la durée, le\n"
            . "commerçant ne sait pas s'il patiente une minute ou s'il rappelle\n"
            . 'quelqu\'un. Reçu : ' . $response->json('message')
        );
        // ⚠️ La suite tourne en locale `en` : l'anglais y est donc CORRECT. Ce qu'on
        // vérifie n'est pas « ce n'est pas de l'anglais » — ma première version
        // affirmait cela et se trompait — mais que le message SUIT LA LOCALE, au lieu
        // d'être écrit en dur. En production la locale est `fr` (ADR-007), et c'est
        // ce que le commerçant lira.
        $this->assertStringNotContainsString(
            ':minutes',
            (string) $response->json('message'),
            "Le paramètre n'est pas substitué : le commerçant lirait « :minutes » "
            . 'en toutes lettres.'
        );

        app()->setLocale('fr');
        $this->assertStringContainsString(
            'Trop de tentatives',
            trans('auth.trop_de_tentatives', ['minutes' => 10]),
            "Le message n'existe pas en français : il serait écrit en dur, ce qui est "
            . 'le défaut corrigé ici.'
        );
        app()->setLocale('en');
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
