<?php

namespace Tests\Feature\Loyalty;

use App\Enums\Activity;
use App\Enums\Ask;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Events\OrderStatusChanged;
use App\Models\Branch;
use App\Models\Order;
use App\Models\Otp;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * [WAVE E FIDÉLITÉ 2026-07-28 — GOAL_WEB_COMMANDE_CLIENT §7]
 * Structure pérenne : le TÉLÉPHONE est la clé fidélité (web/borne/app future),
 * l'email n'est que le canal de vérification/login. Chaîne prouvée ici :
 * signup EMAIL-OTP → le User créé porte DÉJÀ un loyalty_code (LOY-WEB-01,
 * GuestSignupController::register) → un ordre livré crédite les points via
 * AwardLoyaltyPointsOnDelivery (résolution par user_id + loyalty_code) →
 * lookup caisse/borne par TÉLÉPHONE (LoyaltyController::check fallback phone).
 * Piège documenté : SANS loyalty_code le listener n'attribue RIEN (constaté
 * e2e sur un user créé en factory) — ce test verrouille le maillon signup.
 */
class EmailSignupLoyaltyLinkTest extends TestCase
{
    use RefreshDatabase;

    private const API_KEY = 'test-api-key';
    private const PHONE = '0699555077';
    private const EMAIL = 'fidele.web@example.com';

    protected function setUp(): void
    {
        parent::setUp();

        if (!file_exists(storage_path('installed'))) {
            touch(storage_path('installed'));
        }

        $this->seedMinimalSettings();
        $this->seedSpatieRoles();

        DB::table('settings')->insert([
            ['key' => 'site_guest_login',        'payload' => json_encode(Activity::ENABLE),  'group' => 'site', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'site_phone_verification', 'payload' => json_encode(Activity::DISABLE), 'group' => 'site', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'site_default_branch',     'payload' => json_encode(1),                 'group' => 'site', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'otp_expire_time',         'payload' => json_encode(5),                 'group' => 'otp',  'created_at' => now(), 'updated_at' => now()],
        ]);

        config(['app.api_key' => self::API_KEY]);
        $this->withHeaders(['x-api-key' => self::API_KEY, 'Accept' => 'application/json']);

        Mail::fake();
    }

    /** Parcours signup email-otp complet → renvoie le User créé. */
    private function signupViaEmailOtp(): User
    {
        $this->postJson('/api/auth/guest-signup/email-otp', [
            'phone' => self::PHONE, 'email' => self::EMAIL, 'code' => '+33',
        ])->assertOk();

        $token = Otp::where('phone', self::PHONE)->latest('created_at')->first()->token;

        $this->postJson('/api/auth/guest-signup/verify', [
            'phone' => self::PHONE, 'code' => '+33', 'token' => (string) $token,
        ])->assertStatus(201);

        return User::where('phone', self::PHONE)->firstOrFail();
    }

    /** @test */
    public function le_signup_email_cree_un_compte_fidelite_par_telephone(): void
    {
        $user = $this->signupViaEmailOtp();

        // Clé fidélité présente DÈS la création (sinon AwardLoyaltyPointsOnDelivery ignore le user).
        $this->assertNotEmpty($user->loyalty_code, 'LOY-WEB-01 cassé : signup sans loyalty_code = aucun point jamais crédité.');
        $this->assertSame(self::PHONE, $user->phone);
        $this->assertSame(self::EMAIL, $user->email);
    }

    /** @test */
    public function une_commande_livree_credite_les_points_et_le_lookup_telephone_les_voit(): void
    {
        $user = $this->signupViaEmailOtp();
        $branch = Branch::factory()->create();

        $order = Order::factory()->create([
            'branch_id'        => $branch->id,
            'user_id'          => $user->id,
            'order_type'       => OrderType::TAKEAWAY,
            'status'           => OrderStatus::DELIVERED,
            'payment_status'   => PaymentStatus::PAID,
            'total'            => 10.00,
            'order_datetime'   => now(),
            'is_advance_order' => Ask::NO,
            'loyalty_points_awarded' => null,
        ]);

        event(new OrderStatusChanged($order, OrderStatus::PREPARED, OrderStatus::DELIVERED));

        $rate = 10; // défaut loyalty_points_per_euro (LoyaltySetupResource)
        $this->assertSame((int) floor(10.00 * $rate), (int) $user->fresh()->loyalty_points);

        // Lookup cross-surface par TÉLÉPHONE (même endpoint que l'écran Fidélité caisse).
        $token = $user->createToken('t', ['kiosk:order'])->plainTextToken;
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/frontend/loyalty/check', ['code' => self::PHONE])
            ->assertOk()
            ->assertJsonPath('data.points', 100)
            ->assertJsonPath('data.loyalty_code', $user->fresh()->loyalty_code);
    }
}
