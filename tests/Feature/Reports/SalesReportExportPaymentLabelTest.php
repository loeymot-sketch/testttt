<?php

/**
 * [CDASH-SALESREP-PAY-01 FIX 2026-06-13 — P2 reporting data-clarity]
 *
 * The Sales-Report Excel export rendered the TYPE-DE-PAIEMENT column from the
 * transaction payment_method. SalesReportExport::transactionLabel() only mapped
 * the prefixed `counter_*` encaissement codes; rows whose transaction stored the
 * BARE code (cash / card / split / other / ticket_restaurant / mobile_banking)
 * fell to the `strtoupper()` fallback and leaked the raw enum (CASH, CARD, SPLIT,
 * OTHER, TICKET_RESTAURANT) into the financial export — mirroring the on-screen
 * leak healed in the Vue txMap.
 *
 * This test drives transactionLabel() directly with each bare code and asserts the
 * FR label. A genuine gateway provider name (e.g. STRIPE) must still pass through
 * uppercased (no over-translation).
 *
 * @group sentinel
 * @group reports
 */

namespace Tests\Feature\Reports;

use App\Exports\SalesReportExport;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesReportExportPaymentLabelTest extends TestCase
{
    use RefreshDatabase;

    private function export(): SalesReportExport
    {
        // The request is irrelevant for transactionLabel(); pass a bare GET request.
        return new SalesReportExport(
            app(OrderService::class),
            \App\Http\Requests\PaginateRequest::create('/', 'GET', [])
        );
    }

    private function tx(string $method): object
    {
        return (object) ['payment_method' => $method];
    }

    /**
     * @dataProvider bareCodeProvider
     */
    public function test_bare_encaissement_codes_render_french_labels(string $code, string $expected): void
    {
        app()->setLocale('fr');
        $label = $this->export()->transactionLabel($this->tx($code));

        $this->assertSame($expected, $label, "Bare code '{$code}' must render as '{$expected}' in the export.");
        $this->assertNotSame(strtoupper($code), $label, "Bare code '{$code}' leaked the raw enum into the export.");
    }

    public static function bareCodeProvider(): array
    {
        return [
            'cash'             => ['cash', 'Espèces'],
            'card'             => ['card', 'Carte'],
            'ticket_restaurant' => ['ticket_restaurant', 'Titre-restaurant'],
            'mobile_banking'   => ['mobile_banking', 'Paiement mobile'],
            'other'            => ['other', 'Autre'],
            'split'            => ['split', 'Paiement mixte'],
        ];
    }

    public function test_prefixed_counter_codes_still_render_french_labels(): void
    {
        app()->setLocale('fr');
        $export = $this->export();

        $this->assertSame('Espèces', $export->transactionLabel($this->tx('counter_cash')));
        $this->assertSame('Carte', $export->transactionLabel($this->tx('counter_card')));
        $this->assertSame('Titre-restaurant', $export->transactionLabel($this->tx('counter_ticket_restaurant')));
    }

    public function test_real_gateway_provider_name_passes_through_uppercased(): void
    {
        app()->setLocale('fr');
        $this->assertSame('STRIPE', $this->export()->transactionLabel($this->tx('stripe')));
    }
}
