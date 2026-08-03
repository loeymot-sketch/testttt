<?php

namespace Tests\Feature\Maintenance;

use App\Models\Branch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * [NUIT-A2 2026-07-03] La purge order_quotes ne supprime QUE les devis abandonnés (expirés + jamais
 * consommés). Elle PRÉSERVE les devis consommés (liés à une commande = preuve de litige) et les frais.
 */
class PruneOrderQuotesTest extends TestCase
{
    use RefreshDatabase;

    private ?int $branchId = null;

    private function branchId(): int
    {
        return $this->branchId ??= Branch::factory()->create()->id;
    }

    private function seedQuote(string $label, ?string $expiresAt, ?string $consumedAt): int
    {
        return DB::table('order_quotes')->insertGetId([
            'quote_token' => 'qt-' . $label . '-' . Str::random(6),
            'branch_id' => $this->branchId(),
            'surface' => 'kiosk',
            'intent_hash' => hash('sha256', $label),
            'hmac_signature' => hash('sha256', 'sig-' . $label),
            'canonical_payload' => json_encode(['label' => $label]),
            'subtotal' => 10,
            'total_tax' => 1,
            'delivery_charge' => 0,
            'total_ttc' => 10,
            'currency' => 'EUR',
            'expires_at' => $expiresAt,
            'consumed_at' => $consumedAt,
            'created_at' => now()->subDays(30),
            'updated_at' => now()->subDays(30),
        ]);
    }

    /** @test */
    public function la_purge_ne_supprime_que_les_devis_abandonnes(): void
    {
        // Abandonné : expiré il y a 10 j, jamais consommé → DOIT être supprimé.
        $abandoned = $this->seedQuote('abandoned', now()->subDays(10)->toDateTimeString(), null);
        // Consommé : expiré mais lié à une commande → DOIT être préservé.
        $consumed = $this->seedQuote('consumed', now()->subDays(10)->toDateTimeString(), now()->subDays(9)->toDateTimeString());
        // Frais : non encore expiré, non consommé → DOIT être préservé.
        $fresh = $this->seedQuote('fresh', now()->addHour()->toDateTimeString(), null);

        $this->artisan('foodking:order-quotes:prune', ['--older-than-days' => 7])->assertExitCode(0);

        $this->assertDatabaseMissing('order_quotes', ['id' => $abandoned]);
        $this->assertDatabaseHas('order_quotes', ['id' => $consumed]);
        $this->assertDatabaseHas('order_quotes', ['id' => $fresh]);
    }

    /** @test */
    public function le_dry_run_ne_supprime_rien(): void
    {
        $abandoned = $this->seedQuote('dry', now()->subDays(10)->toDateTimeString(), null);
        $this->artisan('foodking:order-quotes:prune', ['--older-than-days' => 7, '--dry-run' => true])->assertExitCode(0);
        $this->assertDatabaseHas('order_quotes', ['id' => $abandoned]);
    }
}
