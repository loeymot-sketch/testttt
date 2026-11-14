<?php

namespace Tests\Feature\Pos;

use App\Models\Branch;
use App\Models\ProInvoice;
use App\Models\Scopes\BranchScope;
use App\Models\User;
use App\Services\ProInvoice\ProInvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * [FACTURE PRO 2026-11-14 · propriétaire] Facture à montant libre pour client professionnel,
 * créée depuis la caisse. HORS chaîne NF525 : jamais une commande, jamais de séquence fiscale.
 */
class ProInvoiceTest extends TestCase
{
    use RefreshDatabase;

    private const API_KEY = 'test-api-key';
    private const BASE = '/api/admin/pos/pro-invoices';

    protected function setUp(): void
    {
        parent::setUp();
        if (! file_exists(storage_path('installed'))) {
            touch(storage_path('installed'));
        }
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        foreach ([1, 2] as $id) {
            if (! Branch::withoutGlobalScope(BranchScope::class)->find($id)) {
                Branch::factory()->create(['id' => $id]);
            }
        }
        config(['app.api_key' => self::API_KEY]);
        $this->withHeaders(['x-api-key' => self::API_KEY, 'Accept' => 'application/json']);
    }

    private function cashier(int $branchId = 1): User
    {
        $u = User::factory()->create(['branch_id' => $branchId]);
        $u->assignRole('POS Operator');

        return $u;
    }

    private function payload(array $override = []): array
    {
        return array_merge([
            'client_name'    => 'Transports Martin SARL',
            'client_address' => '12 rue de la Gare, 62110 Hénin-Beaumont',
            'client_siret'   => '123 456 789 00012',
            'service_date'   => now()->subDay()->toDateString(),
            'label'          => '2 repas',
            'quantity'       => 1,
            'amount_ttc'     => '18.00',
            'payment_method' => 'titre_restaurant',
        ], $override);
    }

    private function store(array $override = [])
    {
        return $this->withHeaders(['X-Idempotency-Key' => (string) Str::uuid()])
            ->postJson(self::BASE, $this->payload($override));
    }

    // ── Numérotation ────────────────────────────────────────────────────────────────────────

    public function test_numbers_are_sequential_gap_free_per_branch_and_year(): void
    {
        $year = now()->format('Y');
        Sanctum::actingAs($this->cashier(1), ['*']);

        $numbers = [];
        foreach (range(1, 3) as $_) {
            $numbers[] = $this->store()->assertStatus(201)->json('data.number');
        }
        $this->assertSame(["FP-{$year}-0001", "FP-{$year}-0002", "FP-{$year}-0003"], $numbers);

        // Une autre branche a sa propre suite.
        Sanctum::actingAs($this->cashier(2), ['*']);
        $this->store()->assertStatus(201)->assertJsonPath('data.number', "FP-{$year}-0001");

        // Nouvelle année : la suite repart à 0001, sans toucher à l'année écoulée.
        $this->travelTo(now()->addYear()->startOfYear()->addHours(10));
        Sanctum::actingAs($this->cashier(1), ['*']);
        $this->store(['service_date' => now()->toDateString()])
            ->assertStatus(201)->assertJsonPath('data.number', 'FP-'.now()->format('Y').'-0001');
        $this->travelBack();
    }

    public function test_admin_branch_zero_issues_on_branch_one(): void
    {
        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');
        Sanctum::actingAs($admin, ['*']);

        $id = $this->store()->assertStatus(201)->json('data.id');
        $this->assertSame(1, (int) ProInvoice::withoutGlobalScope(BranchScope::class)->find($id)->branch_id);
    }

    // ── Montants ────────────────────────────────────────────────────────────────────────────

    public function test_18_euros_ttc_at_10_percent_gives_16_36_ht_and_1_64_vat(): void
    {
        Sanctum::actingAs($this->cashier(), ['*']);

        $this->store(['amount_ttc' => '18,00'])
            ->assertStatus(201)
            ->assertJsonPath('data.amount_ttc', '18.00')
            ->assertJsonPath('data.vat_rate', '10.00')
            ->assertJsonPath('data.amount_ht', '16.36')
            ->assertJsonPath('data.vat_amount', '1.64');
    }

    public function test_ht_plus_vat_equals_ttc_exactly_for_every_cent_and_rate(): void
    {
        foreach (['5.5', '10', '20'] as $rate) {
            for ($cents = 1; $cents <= 5000; $cents += 7) {
                $ttc = number_format($cents / 100, 2, '.', '');
                $a = ProInvoiceService::computeAmounts($ttc, $rate);
                $this->assertSame(
                    $cents,
                    (int) round((float) $a['amount_ht'] * 100) + (int) round((float) $a['vat_amount'] * 100),
                    "HT + TVA ≠ TTC pour {$ttc} € à {$rate} %"
                );
                $this->assertGreaterThanOrEqual(0, (float) $a['vat_amount']);
            }
        }
        $this->assertSame(['amount_ttc' => '100.00', 'amount_ht' => '94.79', 'vat_amount' => '5.21'], ProInvoiceService::computeAmounts('100', '5.5'));
        $this->assertSame(['amount_ttc' => '12.00', 'amount_ht' => '10.00', 'vat_amount' => '2.00'], ProInvoiceService::computeAmounts('12', '20'));
    }

    public function test_client_supplied_ht_and_vat_are_ignored(): void
    {
        Sanctum::actingAs($this->cashier(), ['*']);

        $this->store(['amount_ht' => '1.00', 'vat_amount' => '17.00'])
            ->assertStatus(201)
            ->assertJsonPath('data.amount_ht', '16.36')
            ->assertJsonPath('data.vat_amount', '1.64');
    }

    // ── Validation ──────────────────────────────────────────────────────────────────────────

    public function test_validation_rejects_bad_input_with_french_messages(): void
    {
        Sanctum::actingAs($this->cashier(), ['*']);

        $this->store(['client_name' => ''])->assertStatus(422)
            ->assertJsonPath('errors.client_name.0', 'Indiquez la raison sociale du client.');
        $this->store(['amount_ttc' => '0'])->assertStatus(422)
            ->assertJsonPath('errors.amount_ttc.0', 'Le montant TTC doit être supérieur à 0.');
        $this->store(['amount_ttc' => '-5'])->assertStatus(422)->assertJsonValidationErrors('amount_ttc');
        $this->store(['amount_ttc' => '12.345'])->assertStatus(422)->assertJsonValidationErrors('amount_ttc');
        $this->store(['payment_method' => 'cheque'])->assertStatus(422)
            ->assertJsonPath('errors.payment_method.0', 'Mode de règlement inconnu (espèces, carte ou titre-restaurant).');
        $this->store(['label' => ''])->assertStatus(422)->assertJsonValidationErrors('label');
        $this->store(['service_date' => now()->addDay()->toDateString()])->assertStatus(422)
            ->assertJsonPath('errors.service_date.0', 'La date de la prestation ne peut pas être dans le futur.');
        $this->store(['client_siret' => '1234'])->assertStatus(422)->assertJsonValidationErrors('client_siret');
        $this->store(['vat_rate' => '7'])->assertStatus(422)->assertJsonValidationErrors('vat_rate');
        $this->store(['quantity' => 0])->assertStatus(422)->assertJsonValidationErrors('quantity');

        $this->assertSame(0, ProInvoice::withoutGlobalScope(BranchScope::class)->count(), 'Aucune facture ne doit naître d\'une saisie refusée.');
    }

    public function test_defaults_quantity_one_vat_ten_and_today(): void
    {
        Sanctum::actingAs($this->cashier(), ['*']);

        $p = $this->payload();
        unset($p['quantity'], $p['service_date']);
        $this->withHeaders(['X-Idempotency-Key' => (string) Str::uuid()])->postJson(self::BASE, $p)
            ->assertStatus(201)
            ->assertJsonPath('data.quantity', 1)
            ->assertJsonPath('data.vat_rate', '10.00')
            ->assertJsonPath('data.service_date', now()->toDateString())
            ->assertJsonPath('data.client_siret', '12345678900012');
    }

    // ── Autorisation ────────────────────────────────────────────────────────────────────────

    public function test_user_without_pos_permission_is_refused_everywhere(): void
    {
        Sanctum::actingAs($this->cashier(), ['*']);
        $id = $this->store()->assertStatus(201)->json('data.id');

        $stranger = User::factory()->create(['branch_id' => 1]);
        Sanctum::actingAs($stranger, ['*']);

        $this->getJson(self::BASE)->assertStatus(403);
        $this->store()->assertStatus(403);
        $this->getJson(self::BASE."/{$id}")->assertStatus(403);
        $this->getJson(self::BASE."/{$id}/document")->assertStatus(403);
        $this->withHeaders(['X-Idempotency-Key' => (string) Str::uuid()])
            ->postJson(self::BASE."/{$id}/cancel", ['cancel_reason' => 'Erreur de saisie'])->assertStatus(403);

        $this->assertSame(1, ProInvoice::withoutGlobalScope(BranchScope::class)->count());
        $this->assertNull(ProInvoice::withoutGlobalScope(BranchScope::class)->find($id)->cancelled_at);
    }

    public function test_unauthenticated_is_401(): void
    {
        $this->getJson(self::BASE)->assertStatus(401);
    }

    // ── Isolation de branche ────────────────────────────────────────────────────────────────

    public function test_another_branch_cannot_list_show_print_or_cancel(): void
    {
        Sanctum::actingAs($this->cashier(1), ['*']);
        $id = $this->store()->assertStatus(201)->json('data.id');

        Sanctum::actingAs($this->cashier(2), ['*']);
        $this->getJson(self::BASE)->assertStatus(200)->assertJsonPath('meta.total', 0);
        $this->getJson(self::BASE."/{$id}")->assertStatus(404);
        $this->getJson(self::BASE."/{$id}/document")->assertStatus(404);
        $this->withHeaders(['X-Idempotency-Key' => (string) Str::uuid()])
            ->postJson(self::BASE."/{$id}/cancel", ['cancel_reason' => 'Pas la mienne'])->assertStatus(404);

        $this->assertNull(ProInvoice::withoutGlobalScope(BranchScope::class)->find($id)->cancelled_at);
    }

    public function test_list_is_paginated_newest_first_and_filters_by_date(): void
    {
        Sanctum::actingAs($this->cashier(), ['*']);
        $this->travelTo(now()->subDays(3));
        $this->store(['service_date' => now()->toDateString()])->assertStatus(201);
        $this->travelBack();
        $this->store()->assertStatus(201);
        $this->store()->assertStatus(201);

        $res = $this->getJson(self::BASE.'?per_page=2')->assertStatus(200);
        $res->assertJsonPath('meta.total', 3)->assertJsonPath('meta.last_page', 2);
        $this->assertSame(['FP-'.now()->format('Y').'-0003', 'FP-'.now()->format('Y').'-0002'], array_column($res->json('data'), 'number'));

        $today = now()->toDateString();
        $this->getJson(self::BASE."?date_from={$today}&date_to={$today}")->assertJsonPath('meta.total', 2);
        $this->getJson(self::BASE.'?date_from=nope')->assertStatus(422);
    }

    // ── Annulation et ajout seul ────────────────────────────────────────────────────────────

    public function test_cancel_keeps_the_number_prints_annulee_and_does_not_free_the_number(): void
    {
        Sanctum::actingAs($this->cashier(), ['*']);
        $year = now()->format('Y');
        $id = $this->store()->assertStatus(201)->json('data.id');

        $this->withHeaders(['X-Idempotency-Key' => (string) Str::uuid()])
            ->postJson(self::BASE."/{$id}/cancel", [])->assertStatus(422)
            ->assertJsonPath('errors.cancel_reason.0', 'Indiquez le motif de l\'annulation.');

        $this->withHeaders(['X-Idempotency-Key' => (string) Str::uuid()])
            ->postJson(self::BASE."/{$id}/cancel", ['cancel_reason' => 'Erreur de montant'])
            ->assertStatus(200)
            ->assertJsonPath('data.number', "FP-{$year}-0001")
            ->assertJsonPath('data.cancelled', true)
            ->assertJsonPath('data.cancel_reason', 'Erreur de montant')
            ->assertJsonPath('data.amount_ttc', '18.00');

        // Deuxième annulation refusée.
        $this->withHeaders(['X-Idempotency-Key' => (string) Str::uuid()])
            ->postJson(self::BASE."/{$id}/cancel", ['cancel_reason' => 'Encore'])
            ->assertStatus(422)->assertJsonPath('message', 'Cette facture est déjà annulée.');

        $html = $this->get(self::BASE."/{$id}/document")->assertStatus(200)->getContent();
        $this->assertStringContainsString('ANNULÉE', $html);
        $this->assertStringContainsString("FP-{$year}-0001", $html);

        // Le numéro annulé n'est pas réattribué.
        $this->store()->assertStatus(201)->assertJsonPath('data.number', "FP-{$year}-0002");
    }

    public function test_no_delete_or_edit_route_exists(): void
    {
        Sanctum::actingAs($this->cashier(), ['*']);
        $id = $this->store()->assertStatus(201)->json('data.id');

        $this->deleteJson(self::BASE."/{$id}")->assertStatus(405);
        $this->putJson(self::BASE."/{$id}", ['amount_ttc' => '1.00'])->assertStatus(405);
        $this->patchJson(self::BASE."/{$id}", ['amount_ttc' => '1.00'])->assertStatus(405);

        $names = collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn ($r) => str_contains($r->uri(), 'pro-invoices'))
            ->flatMap(fn ($r) => $r->methods())
            ->unique()->values()->all();
        $this->assertEmpty(array_intersect(['DELETE', 'PUT', 'PATCH'], $names), 'Aucune route ne doit supprimer ou modifier une facture pro.');

        $this->assertSame('18.00', (string) ProInvoice::withoutGlobalScope(BranchScope::class)->find($id)->amount_ttc);
    }

    public function test_model_and_database_refuse_delete_and_amount_edit(): void
    {
        Sanctum::actingAs($this->cashier(), ['*']);
        $id = $this->store()->assertStatus(201)->json('data.id');
        $invoice = ProInvoice::withoutGlobalScope(BranchScope::class)->find($id);

        try {
            $invoice->delete();
            $this->fail('La suppression par le modèle doit être refusée.');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('ne se supprime pas', $e->getMessage());
        }

        try {
            $invoice->amount_ttc = '1.00';
            $invoice->save();
            $this->fail('La modification du montant par le modèle doit être refusée.');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('ne se modifie pas', $e->getMessage());
        }

        // Requête brute : le déclencheur prend le relais.
        foreach ([
            fn () => DB::table('pro_invoices')->where('id', $id)->delete(),
            fn () => DB::table('pro_invoices')->where('id', $id)->update(['amount_ttc' => '1.00']),
            fn () => DB::table('pro_invoices')->where('id', $id)->update(['number' => 'FP-0000-0000']),
        ] as $i => $write) {
            try {
                DB::transaction($write);
                $this->fail("L'écriture brute n°{$i} doit être refusée par le déclencheur.");
            } catch (\Illuminate\Database\QueryException $e) {
                $this->assertStringContainsString('append-only', $e->getMessage());
            }
        }

        $row = DB::table('pro_invoices')->where('id', $id)->first();
        $this->assertNotNull($row);
        $this->assertSame('18.00', number_format((float) $row->amount_ttc, 2, '.', ''));
    }

    // ── Document imprimable ─────────────────────────────────────────────────────────────────

    public function test_document_carries_the_mandatory_mentions_from_settings(): void
    {
        Branch::withoutGlobalScope(BranchScope::class)->whereKey(1)->update([
            'address' => '437 Rue Élie Gruyelle, 62110 Hénin-Beaumont',
            'siret' => '10417050100019',
            'vat_intra' => 'FR19104170501',
        ]);
        Sanctum::actingAs($this->cashier(), ['*']);
        $id = $this->store()->assertStatus(201)->json('data.id');

        $html = $this->get(self::BASE."/{$id}/document")->assertStatus(200)->getContent();

        foreach ([
            'FACTURE', 'FP-'.now()->format('Y').'-0001', '10417050100019', 'FR19104170501',
            '437 Rue Élie Gruyelle', 'Transports Martin SARL', '12 rue de la Gare', '2 repas',
            'Total HT', 'TVA 10', 'Total TTC', 'Titre-restaurant', 'Acquittée',
            'Facture établie hors ticket de caisse.', now()->subDay()->format('d/m/Y'),
        ] as $needle) {
            $this->assertStringContainsString($needle, $html, "Mention absente du document : {$needle}");
        }
        $this->assertStringContainsString('16,36', $html);
        $this->assertStringContainsString('1,64', $html);
        $this->assertStringContainsString('18,00', $html);
        $this->assertStringNotContainsString('ANNULÉE', $html);
        $this->assertStringNotContainsString('à compléter dans les réglages', $html);
    }

    public function test_missing_seller_legal_fields_show_a_placeholder_never_an_invented_value(): void
    {
        Branch::withoutGlobalScope(BranchScope::class)->whereKey(1)->update(['siret' => null, 'vat_intra' => null]);
        Sanctum::actingAs($this->cashier(), ['*']);
        $id = $this->store()->assertStatus(201)->json('data.id');

        $html = $this->get(self::BASE."/{$id}/document")->assertStatus(200)->getContent();
        $this->assertGreaterThanOrEqual(2, substr_count($html, 'à compléter dans les réglages'));
        $this->assertStringNotContainsString('10417050100019', $html);
    }

    public function test_document_escapes_client_supplied_text(): void
    {
        Sanctum::actingAs($this->cashier(), ['*']);
        $id = $this->store(['client_name' => '<script>alert(1)</script>', 'label' => '<img src=x onerror=alert(2)>'])
            ->assertStatus(201)->json('data.id');

        $html = $this->get(self::BASE."/{$id}/document")->getContent();
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    // ── Hors chaîne NF525 ───────────────────────────────────────────────────────────────────

    public function test_invoice_never_creates_an_order_nor_touches_fiscal_tables(): void
    {
        $before = [
            'orders' => DB::table('orders')->count(),
            'audit_logs' => DB::table('audit_logs')->count(),
        ];
        Sanctum::actingAs($this->cashier(), ['*']);
        $id = $this->store()->assertStatus(201)->json('data.id');
        $this->withHeaders(['X-Idempotency-Key' => (string) Str::uuid()])
            ->postJson(self::BASE."/{$id}/cancel", ['cancel_reason' => 'Erreur'])->assertStatus(200);

        $this->assertSame($before, [
            'orders' => DB::table('orders')->count(),
            'audit_logs' => DB::table('audit_logs')->count(),
        ]);

        // Le code (hors commentaires) n'importe ni n'appelle aucune pièce de la chaîne fiscale.
        foreach (['Services/ProInvoice/ProInvoiceService.php', 'Http/Controllers/Admin/Pos/ProInvoiceController.php', 'Models/ProInvoice.php'] as $file) {
            $code = collect(token_get_all(file_get_contents(app_path($file))))
                ->reject(fn ($t) => is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true))
                ->map(fn ($t) => is_array($t) ? $t[1] : $t)->implode('');
            foreach (['PricingService', 'Fiscal', 'ZReport', 'AuditLog', 'CashDrawer', 'Models\\Order'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $code, "{$file} ne doit pas dépendre de {$forbidden}.");
            }
        }
    }
}
