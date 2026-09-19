<?php

namespace Tests\Feature\Onboarding;

use App\Http\Requests\BranchRequest;
use App\Http\Requests\ItemRequest;
use App\Services\Menu\Vision\MenuExtractionContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * [ONB-14 W1 2026-08-27] La journée d'un nouveau commerçant, en jumeau PHP.
 *
 * Ce fichier est le SQUELETTE de la mission de convergence : il rejoue, étape par
 * étape, ce qu'un commerçant qui n'est pas Le Cayenne doit pouvoir faire. Chaque
 * étape est soit VÉRIFIÉE, soit marquée incomplète avec la raison exacte — jamais
 * silencieusement absente.
 *
 * Pourquoi un jumeau PHP et pas seulement un parcours navigateur : le parcours
 * navigateur prouve que l'écran marche, le jumeau prouve que la RÈGLE marche. Les
 * deux sont nécessaires, et c'est le jumeau qui tourne en intégration continue.
 *
 * Ce que ce fichier NE PEUT PAS encore faire, et le dit :
 *  - l'installation vierge exige `foodking:installer`, qui n'existe dans aucun
 *    commit : c'est le livrable d'ONB-12, lui-même bloqué par le gate G0 ;
 *  - la vente de bout en bout créerait une commande, donc un fait fiscal
 *    permanent — interdit hors base dédiée, et c'est démontré sur ce projet.
 *
 * Les étapes déjà vérifiables le sont ici et maintenant. Les autres portent un
 * `markTestIncomplete` qui NOMME le blocage : un test absent se serait oublié,
 * un test incomplet se voit à chaque exécution.
 */
class JourneeNouveauCommercantTest extends TestCase
{
    use RefreshDatabase;

    /** Étape 2 — le commerçant renseigne son identité fiscale. */
    public function test_etape_2_l_identite_fiscale_est_saisissable(): void
    {
        $regles = (new BranchRequest())->rules();

        foreach (['siret', 'vat_intra', 'legal_footer', 'register_id'] as $champ) {
            $this->assertArrayHasKey(
                $champ,
                $regles,
                "Sans règle sur « {$champ} », le champ n'entre jamais dans validated() "
                . "et le ticket sort sans identité fiscale."
            );
        }

        $charge = [
            'name' => 'Chez Nadia', 'city' => 'Lille', 'state' => 'Hauts-de-France',
            'zip_code' => '59000', 'address' => '3 rue des Lilas', 'status' => 5,
            'siret' => '81234567800015', 'vat_intra' => 'FR12345678901',
        ];

        $this->assertFalse(
            Validator::make($charge, $regles)->fails(),
            'Une identité fiscale bien formée doit passer.'
        );
    }

    /** Étape 3 — le commerçant recopie sa carte, et ne peut pas vendre hors taxe. */
    public function test_etape_3_un_article_ne_peut_pas_naitre_hors_taxe(): void
    {
        $regles = (new ItemRequest())->rules();

        $this->assertContains(
            'required',
            $regles['tax_id'],
            'Un article sans taxe est facturé à 0 % en silence par le moteur de prix.'
        );

        $sansTaxe = [
            'name' => 'Kebab maison', 'item_category_id' => 1, 'item_type' => 1,
            'price' => 9.50, 'is_featured' => 0, 'status' => 5, 'order' => 1,
        ];

        $this->assertTrue(
            Validator::make($sansTaxe, $regles)->fails(),
            'La carte doit refuser un article sans taxe.'
        );
    }

    /** Étape 3bis — la carte peut être lue par photo, sans que rien ne soit écrit. */
    public function test_etape_3bis_la_lecture_de_carte_propose_sans_ecrire(): void
    {
        $avant = \Illuminate\Support\Facades\DB::table('items')->count();

        $proposition = app(MenuExtractionContract::class)->lireCarte('/fictif/carte.jpg');

        $this->assertNotEmpty($proposition['articles']);
        $this->assertSame(
            $avant,
            \Illuminate\Support\Facades\DB::table('items')->count(),
            "La lecture PROPOSE : elle ne doit créer aucun article avant validation humaine."
        );
    }

    /** Étape 6 — le commerçant comprend ce qu'il accorde à son équipe. */
    public function test_etape_6_les_messages_de_formulaire_sont_en_francais(): void
    {
        $v = Validator::make([], ['item_category_id' => ['required']]);
        $v->fails();
        $message = $v->errors()->first('item_category_id');

        $this->assertStringContainsString('catégorie', $message);
        $this->assertStringNotContainsString(
            'item_category_id',
            $message,
            "Un commerçant ne doit jamais lire un nom de colonne de base de données."
        );
    }

    /** Étape 7 — retirer une borne la coupe vraiment. */
    public function test_etape_7_la_revocation_de_borne_existe(): void
    {
        $source = file_get_contents(app_path('Services/KioskMachineService.php'));

        $this->assertStringContainsString(
            'revoquerJetonsDeLaBorne',
            $source,
            'Sans révocation, une borne retirée continue de commander pendant 8 heures.'
        );
        $this->assertSame(
            3,
            substr_count($source, '$this->revoquerJetonsDeLaBorne('),
            'Les TROIS chemins — supprimer, désactiver, déconnecter — doivent révoquer.'
        );
    }

    // ---------------------------------------------------------------------
    // Étapes qui ne sont pas encore vérifiables, et qui disent pourquoi.
    // ---------------------------------------------------------------------

    /** Étape 1 — l'installation vierge. */
    public function test_etape_1_installation_vierge(): void
    {
        $this->markTestIncomplete(
            "Bloqué par ONB-12, lui-même bloqué par le gate propriétaire G0 : la commande "
            . "`foodking:installer` n'existe dans aucun commit, et un `migrate --seed` neuf "
            . "produit Le Cayenne intégral (DatabaseSeeder appelle neuf seeders de marque "
            . "sans condition). Tant que G0 n'est pas tranché, il n'existe aucune "
            . "installation générique à vérifier."
        );
    }

    /** Étapes 9 à 12 — vendre, cuisiner, encaisser, clôturer. */
    public function test_etapes_9_a_12_la_vente_de_bout_en_bout(): void
    {
        $this->markTestIncomplete(
            "Exige une base DÉDIÉE. Créer une commande sur la base de travail partagée la "
            . "fiscalise définitivement : les journaux d'audit et les clôtures Z sont en "
            . "ajout-seul avec un déclencheur qui interdit la suppression. C'est démontré "
            . "sur ce projet — cinq filiales fictives d'anciens audits portent aujourd'hui "
            . "cinq commandes, onze entrées de journal et six clôtures qu'on ne peut plus "
            . "retirer. L'interdit du protocole n'est pas une précaution : c'est un constat."
        );
    }

    /** Étape 8 — la promotion appliquée au devis puis à l'encaissement. */
    public function test_etape_8_un_coupon_accepte_au_devis_l_est_au_paiement(): void
    {
        $this->markTestIncomplete(
            "Défaut connu, dans la ZONE GELÉE : DiscountCalculator::couponDiscount() appelle "
            . "resolveCouponById() avec trois arguments et ne relaie jamais la filiale ni la "
            . "surface, alors que PricingRequest les porte. Un coupon restreint à la borne "
            . "est donc refusé au devis. Un test existe déjà pour ce cas exact — "
            . "CouponSurfaceEnforcedAtCommitTest — et il est DÉSACTIVÉ, avec pour motif "
            . "que le correctif touche PricingService. Il attend le gate G-PRIX-COUPON."
        );
    }
}
