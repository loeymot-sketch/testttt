<?php

namespace Tests\Feature\Sentinels;

use App\Enums\PaymentGateway;
use App\Models\Branch;
use App\Models\KioskMachine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * @FK-ID F-008 — Payment Confirm Reconciliation Queue (Sentinel: ability gate)
 * @source plans/PLAN_AUDIT_F008_PAYMENT_CONFIRM_RECONCILE_2026-05-07.md
 * @sprint S4 + WAVE6 reinforcement (2026-05-08)
 * @severity P1 — surface autorisation parallèle de /payment-confirm
 *
 * Sentinel : enforce que /api/frontend/payment/reconcile-pending exige
 * la même garde d'ability `kiosk:order` que /payment-confirm. Sans cette
 * garde, un Sanctum token authentifié sans l'ability mais lié à un
 * KioskMachine.user_id par accident (ou régression de seed) franchirait
 * la ligne — divergence de surface vs PaymentConfirmRequest::authorize().
 *
 * Pattern d'autorisation chosen : in-controller `tokenCan('kiosk:order')`
 * avec `app()->runningUnitTests()` tolerance pour les fixtures session.
 * Symétrique à PaymentConfirmRequest::authorize() (commit 9dc009ec9 §1).
 *
 * Décision route-level `abilities:kiosk:order` middleware REJETÉE :
 * casserait les tests session-auth (cf. routes/api.php:1102-1112).
 *
 * Ne pas alléger sans introduire un guard équivalent.
 */
class F008PaymentReconcileAbilitySentinelTest extends TestCase
{
    use RefreshDatabase;

    public function test_reconcile_requires_kiosk_order_ability(): void
    {
        $branch = Branch::factory()->create(['id' => 901]);
        $user = User::factory()->create(['branch_id' => $branch->id]);
        KioskMachine::factory()->create([
            'user_id'   => $user->id,
            'branch_id' => $branch->id,
        ]);

        // Token authenticated WITHOUT kiosk:order ability.
        Sanctum::actingAs($user, ['some:other-ability']);

        $response = $this->postJson('/api/frontend/payment/reconcile-pending', [
            'entries' => [[
                'order_id'       => 1,
                'transaction_id' => 'TX-F008-NO-ABILITY',
                'amount_cents'   => 100,
                'card_type'      => 'VISA',
                'payment_method' => PaymentGateway::CARD,
            ]],
        ]);

        $this->assertContains(
            $response->status(),
            [401, 403],
            'reconcile-pending must reject Sanctum tokens lacking kiosk:order ability (F-008 + WAVE6-KIOSK-005).'
        );
    }

    public function test_reconcile_accepts_token_with_kiosk_order_ability(): void
    {
        // Forward-compat probe : sentinel must not regress when ability IS present.
        $branch = Branch::factory()->create(['id' => 902]);
        $user = User::factory()->create(['branch_id' => $branch->id]);
        KioskMachine::factory()->create([
            'user_id'   => $user->id,
            'branch_id' => $branch->id,
        ]);

        Sanctum::actingAs($user, ['kiosk:order']);

        // Use a non-existent order_id to avoid touching fiscal flow ;
        // status='order_not_found' but HTTP 200 means the auth gate passed.
        $response = $this->postJson('/api/frontend/payment/reconcile-pending', [
            'entries' => [[
                'order_id'       => 999999,
                'transaction_id' => 'TX-F008-WITH-ABILITY',
                'amount_cents'   => 100,
                'card_type'      => 'VISA',
                'payment_method' => PaymentGateway::CARD,
            ]],
        ]);

        $response->assertStatus(200);
        $this->assertEquals(
            'order_not_found',
            $response->json('data.0.status'),
            'Authorized request must reach per-entry processing (order_not_found is the post-auth path).'
        );
    }
}
