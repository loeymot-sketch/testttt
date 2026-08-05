<?php

namespace Tests\Feature\Hardware;

use App\Models\Branch;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Hardware\OrderReceiptEscPosRenderer;
use Tests\TestCase;

/**
 * [GOAL-8AXES V4 2026-08-05] Matrice de vérité ticket cuisine — D-2 / D-3 / axe 7.
 *
 * G-9 (verdict) : quand un SKU conteneur « Menu (…) » coexiste avec un produit
 * porteur d'addons menu_*, et quand des « Frites » produit coexistent avec un
 * composant FRITES de menu, ce sont des PORTIONS DISTINCTES → on DISTINGUE
 * structurellement (bloc tête double-taille vs ligne indentée), on ne fusionne
 * JAMAIS (fusionner ferait sous-produire la cuisine — Renderer:319 l'avait
 * déjà écarté sciemment).
 *
 * Axe 7 (owner) : chaque ligne d'EN-TÊTE (bannière type, Client, Tel) = GRAS
 * + UNE SEULE LIGNE garantie (troncature « … », plus de repli).
 */
class KitchenTicketHeaderAndDistinctionTest extends TestCase
{
    private function render(array $items, array $orderAttrs = []): string
    {
        $branch = (new Branch)->forceFill([
            'name' => 'Le Cayenne (principal)',
            'address' => '437 Rue Élie Gruyelle, 62110 Hénin-Beaumont',
            'phone' => '+33365678291',
        ]);
        $orderItems = collect();
        foreach ($items as $it) {
            $oi = (new OrderItem)->forceFill([
                'quantity' => $it['quantity'] ?? 1,
                'total_price' => 8.90,
                'composition_snapshot' => $it['snapshot'] ?? [],
                'instruction' => $it['instruction'] ?? '',
            ]);
            $oi->name = $it['name'];
            $orderItems->push($oi);
        }
        $order = (new Order)->forceFill(array_merge([
            'order_serial_no' => 'E2E-8AXES',
            'queue_number' => 'A0042',
            'order_type' => \App\Enums\OrderType::TAKEAWAY,
            'subtotal' => 8.90,
            'total' => 8.90,
            'order_datetime' => '2026-08-05 12:00:00',
        ], $orderAttrs));
        $order->setRelation('orderItems', $orderItems);
        $order->setRelation('branch', $branch);

        return app(OrderReceiptEscPosRenderer::class)->renderKitchenTicket($order, ['width_chars' => 48]);
    }

    /** Décode les octets ESC/POS en lignes de texte lisible (CP858, commandes strippées). */
    private function lines(string $bytes): array
    {
        $stripped = preg_replace('/\x1B[aEtd!@].|\x1D![\x00-\xFF]|\x1B-.|\x1DV.|\x1B\x40/s', '', $bytes);
        $txt = (string) iconv('CP858', 'UTF-8//IGNORE', (string) $stripped);
        $txt = preg_replace('/[\x00-\x09\x0B-\x1F]/', '', $txt);

        return array_values(array_filter(array_map('trim', explode("\n", $txt)), fn ($l) => $l !== ''));
    }

    public function test_d2_menu_sku_and_menu_addons_are_both_rendered_distinctly(): void
    {
        $bytes = $this->render([
            [
                'name' => 'Tacos M',
                'snapshot' => ['addons' => [
                    ['role' => 'menu_frites', 'quantity' => 1, 'addon_name' => 'Frites'],
                    ['role' => 'menu_boisson', 'quantity' => 1, 'addon_name' => 'Coca 33cl'],
                ]],
            ],
            [
                'name' => 'Menu (Frites + Boisson)',
                'snapshot' => ['addons' => [
                    ['role' => 'menu_boisson', 'quantity' => 1, 'addon_name' => 'Fanta 33cl'],
                ]],
            ],
        ]);
        $text = implode("\n", $this->lines($bytes));

        // G-9 : les DEUX portions existent — ni fusion, ni disparition.
        $this->assertSame(
            2,
            substr_count($text, 'MENU'),
            "D-2: le produit (ligne MENU indentée) ET le SKU conteneur (tête MENU) doivent tous deux sortir.\nTicket :\n{$text}"
        );
        // Chaque bloc garde sa boisson propre (pas de mélange entre portions).
        $this->assertStringContainsString('Coca 33cl', $text);
        $this->assertStringContainsString('Fanta 33cl', $text);
    }

    public function test_d3_standalone_frites_and_menu_frites_component_both_visible(): void
    {
        $bytes = $this->render([
            ['name' => 'Frites', 'snapshot' => []],
            [
                'name' => 'Cayenne',
                'snapshot' => ['addons' => [
                    ['role' => 'menu_frites', 'quantity' => 1, 'addon_name' => 'Frites'],
                ]],
            ],
        ]);
        $text = implode("\n", $this->lines($bytes));

        // Portion standalone (code produit FRI) ET composant de menu (FRITES) — distincts.
        $this->assertStringContainsString('FRI', $text, "D-3: la portion Frites standalone doit sortir.\n{$text}");
        $this->assertStringContainsString('FRITES', $text, "D-3: le composant frites du menu doit sortir.\n{$text}");
    }

    public function test_axe7_header_lines_are_single_line_even_when_too_long(): void
    {
        $longName = 'Jean-Baptiste-Alexandre De La Rochefoucauld-Montmorency-Bourbon';
        $bytes = $this->render(
            [['name' => 'Cayenne', 'snapshot' => []]],
            ['pos_customer_name' => $longName, 'pos_customer_phone' => '+33 6 12 34 56 78']
        );
        $lines = $this->lines($bytes);

        $clientLines = array_values(array_filter($lines, fn ($l) => str_starts_with($l, 'Client :')));
        $this->assertCount(1, $clientLines, 'Le nom client tient sur UNE ligne (tronqué, jamais replié).');
        $this->assertLessThanOrEqual(48, mb_strlen($clientLines[0]));
        $this->assertStringEndsWith('...', $clientLines[0], 'Troncature signalée par « ... » (ASCII, CP858-safe).');

        // Aucune ligne orpheline du repli (le reste du nom ne doit apparaître nulle part).
        $this->assertStringNotContainsString('Bourbon', implode("\n", $lines));

        // La bannière type de commande tient sur une ligne.
        $banner = array_values(array_filter($lines, fn ($l) => str_contains($l, 'EMPORTER')));
        $this->assertCount(1, $banner, 'Bannière type de commande = une seule ligne.');
    }

    public function test_axe7_header_lines_are_bold(): void
    {
        $bytes = $this->render(
            [['name' => 'Cayenne', 'snapshot' => []]],
            ['pos_customer_name' => 'Karim']
        );

        // Octets de style : chaque ligne d'en-tête est précédée de ESC E \x01 (gras ON).
        $this->assertStringContainsString("\x1BE\x01CUISINE", $bytes, 'CUISINE est en gras (ESC E 1).');
        $this->assertStringContainsString("\x1BE\x01Client : Karim", $bytes, 'La ligne Client est en gras (ESC E 1).');
    }
}
