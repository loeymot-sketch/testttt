<?php

namespace Tests\Feature\Onboarding;

use App\Enums\DiscountType;
use App\Models\Coupon;
use App\Services\CouponService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [ONB-09 2026-08-28] « Remise maximale = 0 » veut dire ILLIMITÉE.
 *
 * ═══ LE DÉFAUT, ET IL EST DANS L'ÉCRAN ═══
 *
 * `CouponService:418-421` n'applique le plafond que s'il est **strictement
 * positif** :
 *
 *     if ($maximumDiscount > 0 && $amount > $maximumDiscount) { … }
 *
 * À zéro, aucun plafond. `CouponRequest:59` accepte `min:0`, et l'écran présentait
 * le champ comme obligatoire **sans la moindre indication**.
 *
 * Un code −20 % créé avec 0 dans ce champ rend **50 €** sur une commande de groupe
 * à 250 €. Le commerçant croyait avoir borné sa promotion ; il l'avait ouverte.
 *
 * ═══ POURQUOI ON NE CHANGE PAS LA RÈGLE ═══
 *
 * La sémantique « 0 = pas de plafond » est établie et lue ailleurs :
 * `WheelReportService:373-377` compte explicitement les `codes_sans_plafond`. La
 * changer casserait les coupons existants et le rapport qui les surveille.
 *
 * Le défaut n'est donc pas la règle — c'est que **l'écran se taisait au moment où
 * le commerçant décide**. Ce banc verrouille les deux : le comportement, pour qu'il
 * ne dérive pas ; et l'indication, pour qu'elle ne disparaisse pas.
 */
class UnPlafondDeRemiseAZeroEstDitAuCommercantTest extends TestCase
{
    use RefreshDatabase;

    public function test_zero_signifie_bien_aucun_plafond(): void
    {
        // On verrouille le comportement RÉEL avant de parler de l'écran : si un
        // jour quelqu'un change la sémantique, l'indication deviendrait fausse et
        // ce banc doit le dire.
        $coupon = Coupon::factory()->create([
            'discount_type'    => DiscountType::PERCENTAGE,
            'discount'         => 20,
            'maximum_discount' => 0,
        ]);

        $remise = app(CouponService::class)->calculateDiscountAmount($coupon, 250.00);

        $this->assertEqualsWithDelta(
            50.00,
            $remise,
            0.001,
            "La sémantique a changé : 0 n'est plus « pas de plafond ».\n"
            . "L'indication portée par l'écran devient donc fausse, et\n"
            . '`WheelReportService::codes_sans_plafond` compte autre chose.'
        );
    }

    public function test_un_plafond_positif_borne_bien_la_remise(): void
    {
        // Contrôle de périmètre : sans lui, le banc précédent serait vert même si
        // le plafond ne s'appliquait JAMAIS.
        $coupon = Coupon::factory()->create([
            'discount_type'    => DiscountType::PERCENTAGE,
            'discount'         => 20,
            'maximum_discount' => 15,
        ]);

        $remise = app(CouponService::class)->calculateDiscountAmount($coupon, 250.00);

        $this->assertEqualsWithDelta(
            15.00,
            $remise,
            0.001,
            'Le plafond ne borne plus la remise : tous les codes deviennent illimités.'
        );
    }

    public function test_l_ecran_dit_ce_que_zero_veut_dire(): void
    {
        $ecran = file_get_contents(
            resource_path('js/components/admin/coupons/CouponCreateComponent.vue')
        );

        $this->assertStringContainsString(
            'data-testid="coupon-maximum-discount-hint"',
            $ecran,
            "L'écran ne dit pas que 0 signifie « aucun plafond ».\n"
            . "Le commerçant croit borner sa promotion au moment même où il l'ouvre."
        );

        $libelles = json_decode(
            file_get_contents(resource_path('js/languages/fr.json')),
            true
        );

        $indication = $libelles['label']['maximum_discount_hint'] ?? '';

        $this->assertNotEmpty(
            $indication,
            "L'indication s'affiche sous une clé brute : « label.maximum_discount_hint »."
        );

        // Et elle doit NOMMER le comportement, pas seulement exister. Une phrase
        // vague — « champ facultatif » — laisserait le piège entier.
        $this->assertMatchesRegularExpression(
            '/\b0\b/',
            $indication,
            "L'indication ne nomme pas la valeur qui déclenche le comportement : « {$indication} »"
        );
    }
}
