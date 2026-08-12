<?php

namespace Tests\Feature\Sentinels;

use App\Enums\PosPaymentMethod;
use App\Http\Requests\PosOrderRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [P1 V1 Cloud-Prep insights 2026-05-18] Sentinel: single-tender CARD POS
 * payment MUST require `terminal_id`. Wave 5F F-SPLIT-PHANTOM-CARD-001
 * closed the split-tender CARD path (payment_breakdown.*.terminal_id with
 * branch + ACTIVE checks). This sentinel closes the legacy single-tender
 * path that was still bucketed as "Sans TPE" in the Z-report TPE breakdown.
 *
 * Tested at the rule-shape level only (cheap, isolated). The deep ACTIVE +
 * branch ownership cross-check belongs in OrderService::posOrderStore — it
 * needs branch context that the FormRequest doesn't have reliably (CARD
 * tile single-tender bypass attempt by a desync'd UI must die at the
 * service layer, not the request layer).
 */
class PosSingleTenderCardTerminalIdSentinelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // PosOrderRequest::rules() reads Settings::group('pos')->get('pos_dine_in_enabled')
        // at call time, which hits the `settings` table. seedMinimalSettings()
        // ships a `pos.pos_dine_in_enabled=false` row.
        $this->seedMinimalSettings();
    }

    public function test_rules_require_terminal_id_when_single_tender_card(): void
    {
        $rules = (new PosOrderRequest())->rules();

        $this->assertArrayHasKey(
            'terminal_id',
            $rules,
            'PosOrderRequest must declare a top-level `terminal_id` rule '
            . 'for single-tender CARD path (Wave 5F closed the split path; '
            . 'this closes the legacy single-tender path).'
        );

        $rule = is_array($rules['terminal_id'])
            ? implode('|', $rules['terminal_id'])
            : $rules['terminal_id'];

        /*
         * ── CETTE EXIGENCE A ÉTÉ RETIRÉE LE 2026-08-12, ET VOICI POURQUOI ────────────────────
         * Le propriétaire a signalé : « le choix par carte bleue ne fonctionne pas ». Cause exacte :
         * `required_if:pos_payment_method,CARD` sur `terminal_id`, alors que l'écran de caisse n'envoie
         * pas ce champ. Toute vente carte partait donc en 422.
         *
         * Et la règle ne protégeait RIEN. Mesuré sur la base réelle le 12 août :
         *   · `orders.terminal_id` N'EXISTE PAS → pour une vente mono-tender, le champ de la requête
         *     n'avait AUCUN endroit où être rangé ;
         *   · une vente mono-tender ne crée AUCUNE ligne `order_payments` — 47 ventes carte sur 104
         *     sont dans ce cas ;
         *   · la ventilation TPE du Z lit `order_payments` (`ZReportCashEnrichmentService`), donc ces
         *     47 ventes (274,40 €) n'y figurent pas du tout — pas même sous « Sans TPE ».
         *
         * La sentinelle exigeait donc un champ impossible à persister, au prix de bloquer toutes les
         * ventes carte. Le commentaire d'origine (« was still bucketed as Sans TPE ») partait d'une
         * prémisse fausse : il n'y a pas de ligne à ventiler.
         *
         * ⚠️ LE VRAI TROU RESTE OUVERT, ET IL EST PLUS GRAND : les ventes carte mono-tender sont
         * ABSENTES de la ventilation TPE du Z. Le refermer demande d'écrire une ligne
         * `order_payments` pour une vente mono-tender — un changement du chemin de l'ARGENT, avec
         * `ZReportService` en zone gelée (§7). Il mérite sa propre passe, avec gate propriétaire, pas
         * une improvisation au milieu d'une vague de validation.
         *
         * ⛔ NE PAS « RÉPARER » EN REMETTANT `required_if` : ça rebloquerait les ventes carte du
         * propriétaire sans rien ajouter au Z. Sentinelle du défaut :
         * tests/Feature/Pos/PosCardSaleWithoutTerminalTest.php
         */
        $this->assertStringNotContainsString(
            'required_if:pos_payment_method,' . PosPaymentMethod::CARD,
            $rule,
            'terminal_id NE DOIT PAS être required_if CARD : l\'écran de caisse n\'envoie pas ce '
            . 'champ, la vente carte partirait en 422 (défaut signalé par le propriétaire), et rien '
            . 'ne serait persisté — `orders.terminal_id` n\'existe pas et une vente mono-tender ne '
            . 'crée aucune ligne `order_payments`.'
        );

        $this->assertStringContainsString(
            'nullable',
            $rule,
            'terminal_id reste ACCEPTÉ (nullable) : le jour où l\'écran de caisse l\'enverra, la '
            . 'requête doit le laisser passer sans changement de contrat.'
        );

        $this->assertStringContainsString(
            'integer',
            $rule,
            'terminal_id must be typed as integer.'
        );

        $this->assertStringContainsString(
            'min:1',
            $rule,
            'terminal_id must have a min:1 floor to reject 0 / negative spoofs.'
        );
    }
}
