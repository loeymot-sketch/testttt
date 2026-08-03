<?php

namespace Tests\Feature\Sentinels;

use App\Enums\Ask;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use App\Services\KdsSyncService;
use App\Services\KitchenDisplaySystemOrderService;
use App\Services\OrderStatusScreenOrderService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * [ULTRA WAVE 3 P3 → HEAL 2026-07-04] Bascule de minuit — Le Cayenne opère APRÈS minuit
 * (commandes réelles 23h-02h, DB-prouvé). L'ancienne fenêtre non-advance filtrait par jour
 * CIVIL (`whereBetween [todayStart, todayEnd]`) : à 00h00, une commande de 23h30 encore
 * PREPARING disparaissait du mur client OSS ET du board KDS ET du flux sync alors qu'elle
 * n'avait que 30 min. Le fix remplace le jour civil par la fenêtre GLISSANTE partagée
 * (`oss.stale_window_hours`, défaut 8h) comme borne basse + `< demain` comme borne haute
 * (anti-futur), identique sur les 4 chemins pour garder OSS↔KDS↔sync cohérents.
 *
 * Ce test verrouille LES DEUX directions :
 *  1. une commande active à cheval sur minuit (< 8h d'âge) reste VISIBLE partout ;
 *  2. un zombie (> 8h d'âge) reste EXCLU partout (l'intention anti-zombie d'origine).
 */
class OssKdsMidnightStraddleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function makeOrder(Branch $branch, Carbon $when, int $status = OrderStatus::PREPARING, int $advance = Ask::NO): Order
    {
        $order = Order::factory()->create([
            'branch_id' => $branch->id,
            'status' => $status,
            'payment_status' => PaymentStatus::PAID,
            'order_type' => \App\Enums\OrderType::TAKEAWAY,
            'order_datetime' => $when,
            'is_advance_order' => $advance,
            // Le mur OSS n'affiche que les commandes numérotées (token OU KIOSK OU TAKEAWAY+queue_number).
            'queue_number' => 'A'.$when->format('Hi'),
            'business_date' => $when->toDateString(),
        ]);
        $order->forceFill(['updated_at' => $when])->saveQuietly();

        return $order;
    }

    /** @test */
    public function commande_a_cheval_sur_minuit_reste_visible_partout_et_zombie_exclu(): void
    {
        // « Maintenant » = 00:30 Paris (hiver). La commande de 23:30 la veille a 1 h.
        $parisNow = CarbonImmutable::parse('2026-01-16 00:30:00', 'Europe/Paris');
        Carbon::setTestNow($parisNow);
        CarbonImmutable::setTestNow($parisNow);

        $branch = Branch::factory()->create();
        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');

        $straddle = $this->makeOrder($branch, Carbon::parse('2026-01-15 23:30:00', 'Europe/Paris'));
        // Zombie : 12 h d'âge (> fenêtre 8h) — doit rester EXCLU (intention anti-zombie).
        $zombie = $this->makeOrder($branch, Carbon::parse('2026-01-15 12:30:00', 'Europe/Paris'));

        // === 1. KDS list ===
        $this->actingAs($admin);
        $kdsIds = app(KitchenDisplaySystemOrderService::class)
            ->list(new Request(['branch_id' => $branch->id]))
            ->pluck('id')->all();
        $this->assertContains($straddle->id, $kdsIds, 'KDS list : la commande de 23h30 (1h d\'âge) doit rester sur le board après minuit.');
        $this->assertNotContains($zombie->id, $kdsIds, 'KDS list : un zombie > 8h reste exclu.');

        // === 2. KDS sync (delta) — doit refléter list (leçon Wave 1) ===
        Cache::flush();
        $syncIds = array_map(
            fn ($o) => (int) ($o['id'] ?? 0),
            app(KdsSyncService::class)->sync($branch->id, new DateTimeImmutable('2000-01-01T00:00:00'), true)['orders']
        );
        $this->assertContains($straddle->id, $syncIds, 'KDS sync : la commande à cheval sur minuit doit être dans le delta.');
        $this->assertNotContains($zombie->id, $syncIds, 'KDS sync : zombie > 8h exclu.');

        // === 3. OSS mur client (list — lit la request globale) ===
        $ossIds = app(OrderStatusScreenOrderService::class)
            ->list()
            ->pluck('id')->all();
        $this->assertContains($straddle->id, $ossIds, 'OSS list : le client de 23h30 doit encore voir sa commande « En préparation » à 00h30.');
        $this->assertNotContains($zombie->id, $ossIds, 'OSS list : zombie > 8h exclu.');

        // === 4. OSS mur public (listForBranch) ===
        $wallIds = app(OrderStatusScreenOrderService::class)
            ->listForBranch($branch->id)
            ->pluck('id')->all();
        $this->assertContains($straddle->id, $wallIds, 'OSS listForBranch : parité avec list().');
        $this->assertNotContains($zombie->id, $wallIds, 'OSS listForBranch : zombie > 8h exclu.');
    }

    /**
     * @test — [SELF-AUDIT A 2026-07-05] Une PRÉCOMMANDE en retard (>8h, toujours en préparation) doit
     * rester visible sur les 4 chemins. Avant le fix, OSS appliquait le plancher 8h en AND top-level qui
     * contraignait AUSSI la branche advance → le KDS la gardait (contrat AUDIT-52-BUG1) mais le mur client
     * OSS la PRUNEAIT → le client ne voyait plus sa commande encore en cuisine. Le plancher vit désormais
     * dans la seule sous-clause non-advance (parité KDS exacte). Ce cas manquait au sentinel (il ne testait
     * que des commandes non-advance).
     */
    public function precommande_en_retard_reste_visible_sur_les_quatre_chemins(): void
    {
        // « Maintenant » = 14:00 Paris. La précommande programmée à 05:00 (9h de retard) est toujours
        // PREPARING → au-delà du plancher 8h (floor = 06:00 > 05:00).
        $parisNow = CarbonImmutable::parse('2026-01-16 14:00:00', 'Europe/Paris');
        Carbon::setTestNow($parisNow);
        CarbonImmutable::setTestNow($parisNow);

        $branch = Branch::factory()->create();
        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');

        $overdueAdvance = $this->makeOrder(
            $branch,
            Carbon::parse('2026-01-16 05:00:00', 'Europe/Paris'),
            OrderStatus::PREPARING,
            Ask::YES
        );

        $this->actingAs($admin);

        // === 1. KDS list — garde la précommande en retard (AUDIT-52-BUG1) ===
        $kdsIds = app(KitchenDisplaySystemOrderService::class)
            ->list(new Request(['branch_id' => $branch->id]))
            ->pluck('id')->all();
        $this->assertContains($overdueAdvance->id, $kdsIds, 'KDS list : précommande en retard gardée (branche advance sans plancher).');

        // === 2. KDS sync (delta) ===
        Cache::flush();
        $syncIds = array_map(
            fn ($o) => (int) ($o['id'] ?? 0),
            app(KdsSyncService::class)->sync($branch->id, new DateTimeImmutable('2000-01-01T00:00:00'), true)['orders']
        );
        $this->assertContains($overdueAdvance->id, $syncIds, 'KDS sync : précommande en retard dans le delta.');

        // === 3. OSS list — DOIT désormais la garder aussi (le bug A la pruneait ici) ===
        $ossIds = app(OrderStatusScreenOrderService::class)
            ->list()
            ->pluck('id')->all();
        $this->assertContains($overdueAdvance->id, $ossIds, 'OSS list : le client DOIT voir sa précommande en retard encore en préparation (parité KDS — régression bug A).');

        // === 4. OSS listForBranch — parité ===
        $wallIds = app(OrderStatusScreenOrderService::class)
            ->listForBranch($branch->id)
            ->pluck('id')->all();
        $this->assertContains($overdueAdvance->id, $wallIds, 'OSS listForBranch : parité avec les 3 chemins cuisine.');
    }
}
