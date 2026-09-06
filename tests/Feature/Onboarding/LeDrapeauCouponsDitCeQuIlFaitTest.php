<?php

namespace Tests\Feature\Onboarding;

use Tests\TestCase;

/**
 * [ONB-09 2026-08-28] Le drapeau des codes promo dit ce qu'il fait.
 *
 * ═══ LE DÉFAUT, ET IL EST DANS UN COMMENTAIRE ═══
 *
 * `config/pos.php` promettait : « quand il vaut true, le pré-contrôle **et
 * l'application** d'un coupon sont autorisés, que les remises manuelles soient
 * coupées ou non ».
 *
 * La seconde moitié était fausse. `OrderService::assertDiscretionaryDiscountAllowed`
 * (7 sites d'appel) ne lit QUE `pos.manual_discount_enabled` ; le drapeau dédié
 * n'apparaît nulle part dans `OrderService.php`.
 *
 * Vécu par l'exploitant : le code du client est **accepté** au pré-contrôle, puis
 * **refusé** au paiement. Le drapeau créé pour éviter d'ouvrir les remises libres
 * oblige donc à les ouvrir.
 *
 * ═══ POURQUOI CE BANC N'EXIGE PAS LE CORRECTIF ═══
 *
 * Le garde n'est pas en faute : `LOCK_ZREPORT_F1_DISCOUNT_NETTING_2026_05_31.md §7`
 * désigne `manual_discount_enabled` comme l'interrupteur **unique** d'activation. Il
 * applique une décision prise.
 *
 * Découpler — faire lire le drapeau par la caisse — change ce qu'un interrupteur
 * d'arrêt protège : c'est une décision propriétaire, pas une correction.
 * Dossier : `docs/gates/GATE_COUPONS_EN_CAISSE_2026-08-28.md`.
 *
 * Ce banc épingle donc l'état RÉEL. Le jour où quelqu'un fera lire le drapeau par la
 * caisse, il virera au rouge et renverra au dossier — pour que le découplage soit
 * une décision, pas un effet de bord.
 */
class LeDrapeauCouponsDitCeQuIlFaitTest extends TestCase
{
    public function test_le_garde_de_caisse_ne_lit_toujours_qu_un_seul_drapeau(): void
    {
        $service = file_get_contents(app_path('Services/OrderService.php'));

        $this->assertStringContainsString(
            "config('pos.manual_discount_enabled') !== true",
            $service,
            "Le garde d'admission des remises a changé de forme : relire le dossier\n"
            . 'docs/gates/GATE_COUPONS_EN_CAISSE_2026-08-28.md avant d\'aller plus loin.'
        );

        $this->assertStringNotContainsString(
            'coupon_codes_enabled',
            $service,
            "La caisse lit désormais `pos.coupon_codes_enabled`.\n\n"
            . "C'est peut-être exactement ce qu'il fallait faire — mais c'est une\n"
            . "DÉCISION : elle change ce qu'un interrupteur d'arrêt protège, et elle\n"
            . "était en attente de signature propriétaire.\n\n"
            . "Si elle a été prise, mettre à jour :\n"
            . "  · docs/gates/GATE_COUPONS_EN_CAISSE_2026-08-28.md\n"
            . "  · le commentaire de config/pos.php\n"
            . '  · ce banc, qui n\'a alors plus lieu d\'être.'
        );
    }

    public function test_le_commentaire_de_config_ne_promet_plus_ce_que_le_code_ne_fait_pas(): void
    {
        $config = file_get_contents(config_path('pos.php'));

        // La promesse retirée. Sa réapparition signifierait qu'on a repris la
        // rédaction d'origine sans revoir le code — le motif « un commentaire qui
        // affirme un comportement que le code n'a pas », déjà rencontré trois fois
        // cette semaine.
        $this->assertStringNotContainsString(
            "sont autorisés, que les remises manuelles soient coupées ou non",
            $config,
            "Le commentaire promet de nouveau que le drapeau autorise l'APPLICATION\n"
            . "d'un coupon indépendamment des remises manuelles. Le code ne le fait pas."
        );

        // Et il doit nommer ce que le drapeau commande vraiment.
        $this->assertStringContainsString(
            'CouponController:55',
            $config,
            'Le commentaire ne dit plus quels lecteurs ce drapeau commande réellement.'
        );
    }

    public function test_l_obstacle_fiscal_invoque_par_le_garde_a_bien_ete_leve(): void
    {
        // Le docblock du garde (daté du 2026-05-30) refuse les remises parce que le
        // découpage TVA/HT serait faux dans la zone gelée. Ce défaut — F1 — a été
        // corrigé le LENDEMAIN sous clé propriétaire. Le garde survit donc à sa
        // propre justification.
        //
        // Ce banc vérifie que la clé et sa preuve existent toujours : sans elles, la
        // recommandation du dossier d'arbitrage ne tiendrait plus.
        $this->assertFileExists(
            base_path('plans/LOCK_ZREPORT_F1_DISCOUNT_NETTING_2026_05_31.md'),
            "La clé qui a levé l'obstacle fiscal a disparu : le dossier d'arbitrage\n"
            . 'repose sur elle et doit être relu.'
        );

        $cle = file_get_contents(base_path('plans/LOCK_ZREPORT_F1_DISCOUNT_NETTING_2026_05_31.md'));

        $this->assertStringContainsString(
            'GRANTED',
            $cle,
            "La clé n'est plus marquée accordée."
        );

        $this->assertFileExists(
            base_path('tests/Feature/Fiscal/ZReportDiscountNettingTest.php'),
            "La preuve du netting TVA a disparu : sans elle, l'obstacle fiscal\n"
            . 'redevient une question ouverte, et le dossier doit être relu.'
        );
    }
}
