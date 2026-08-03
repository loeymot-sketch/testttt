<?php

namespace Tests\Feature\Purchasing;

use App\Services\Purchasing\Vision\InvoiceVisionContract;
use App\Services\Purchasing\Vision\MockInvoiceVisionService;
use App\Services\Purchasing\Vision\OpenAiInvoiceVisionService;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * [ARCH_STOCK_INTELLIGENT_BOM_2026-07-23 / P3b] Contrat de lecture IA + bascule.
 *
 * Prouve : le MOCK lit le fixture déterministe (4 lignes) et ne crashe jamais ;
 * l'OpenAI construit le bon appel HTTP (URL / model / bearer / image) SANS
 * réseau réel (Http::fake) et parse la réponse ; sans clé il FAIL-CLOSE (exception
 * claire) ; le binding choisit Mock par défaut et OpenAi seulement si clé+enabled.
 *
 * NF525 : domaine ADDITIF — aucune assertion fiscale.
 */
class InvoiceVisionServiceTest extends TestCase
{
    // ── MOCK ────────────────────────────────────────────────────────────────

    public function test_mock_extracts_four_deterministic_lines_from_default_fixture(): void
    {
        $lines = (new MockInvoiceVisionService())->extractLines('/whatever/photo.jpg');

        $this->assertCount(4, $lines);

        $poulet = $lines[0];
        $this->assertSame('Poulet frais 3kg', $poulet['raw_label']);
        $this->assertEqualsWithDelta(3.0, $poulet['qty'], 0.0001);
        $this->assertSame('kg', $poulet['unit']);
        $this->assertEqualsWithDelta(6.0, $poulet['unit_price'], 0.0001);
        $this->assertEqualsWithDelta(5.5, $poulet['tva_rate'], 0.0001);

        $labels = array_column($lines, 'raw_label');
        $this->assertSame(
            ['Poulet frais 3kg', 'Cheddar 100 tranches', 'Coca cola 24 canettes', 'Sac papier kraft 500'],
            $labels
        );
    }

    public function test_mock_reads_a_per_path_json_fixture_when_given_one(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'inv').'.json';
        file_put_contents($tmp, json_encode(['lines' => [
            ['raw_label' => 'Jambon 50 tranches', 'qty' => 50, 'unit' => 'tranche', 'unit_price' => 0.20, 'tva_rate' => 5.5],
        ]]));

        $lines = (new MockInvoiceVisionService())->extractLines($tmp);

        $this->assertCount(1, $lines);
        $this->assertSame('Jambon 50 tranches', $lines[0]['raw_label']);

        @unlink($tmp);
    }

    public function test_mock_is_fail_safe_returns_empty_on_invalid_json_never_throws(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'inv').'.json';
        file_put_contents($tmp, 'PAS DU JSON {{{');

        $lines = (new MockInvoiceVisionService())->extractLines($tmp);

        $this->assertSame([], $lines, 'Un fixture illisible/invalide doit rendre [] sans crash (fail-safe).');

        @unlink($tmp);
    }

    // ── OPENAI (câblage prouvé sans réseau réel) ─────────────────────────────

    public function test_openai_builds_the_vision_http_call_and_parses_response_without_network(): void
    {
        config([
            'services.openai.key' => 'sk-test-123',
            'services.openai.model' => 'gpt-4o-mini',
            'services.openai.base_url' => 'https://api.openai.com/v1',
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode(['lines' => [
                            ['raw_label' => 'Poulet 3kg', 'qty' => 3, 'unit' => 'kg', 'unit_price' => 6.0, 'tva_rate' => 5.5],
                            ['raw_label' => 'Coca 24', 'qty' => 24, 'unit' => 'piece', 'unit_price' => 0.58, 'tva_rate' => 20],
                        ]]),
                    ],
                ]],
            ], 200),
        ]);

        // N'importe quel fichier lisible sert d'image (encode base64) — pas de réseau.
        $lines = (new OpenAiInvoiceVisionService())->extractLines(
            base_path('tests/fixtures/invoices/metro-sample.json')
        );

        $this->assertCount(2, $lines);
        $this->assertSame('Poulet 3kg', $lines[0]['raw_label']);
        $this->assertEqualsWithDelta(6.0, $lines[0]['unit_price'], 0.0001);

        Http::assertSent(function ($request) {
            $body = $request->data();

            return str_contains($request->url(), '/chat/completions')
                && $request->hasHeader('Authorization', 'Bearer sk-test-123')
                && ($body['model'] ?? null) === 'gpt-4o-mini'
                && str_contains(json_encode($body['messages'] ?? []), 'image_url');
        });
    }

    public function test_openai_fails_closed_without_key(): void
    {
        config(['services.openai.key' => '']);

        $this->expectException(RuntimeException::class);

        (new OpenAiInvoiceVisionService())->extractLines('/whatever.jpg');
    }

    // ── BINDING (bascule clé) ────────────────────────────────────────────────

    public function test_binding_resolves_mock_when_no_key(): void
    {
        config(['services.openai.enabled' => false, 'services.openai.key' => '']);

        $this->assertInstanceOf(MockInvoiceVisionService::class, app(InvoiceVisionContract::class));
    }

    public function test_binding_resolves_openai_when_enabled_with_key(): void
    {
        config(['services.openai.enabled' => true, 'services.openai.key' => 'sk-live-xyz']);

        $this->assertInstanceOf(OpenAiInvoiceVisionService::class, app(InvoiceVisionContract::class));
    }

    public function test_binding_stays_mock_when_key_present_but_disabled(): void
    {
        config(['services.openai.enabled' => false, 'services.openai.key' => 'sk-live-xyz']);

        $this->assertInstanceOf(MockInvoiceVisionService::class, app(InvoiceVisionContract::class));
    }
}
