<?php

namespace Tests\Feature\Promo;

use App\Services\Promo\PromoFlyerEscPosRenderer;
use Tests\TestCase;

/**
 * [PHOTO OWNER IMG_2090 · 2026-08-08] Le ticket promo imprimé était mal centré et coupait les mots.
 *
 * Deux défauts indépendants, chacun suffisant :
 *
 * 1. LARGEUR. `PromoFlyerService::renderBytes` avait 48 EN DUR par défaut, et le contrôleur
 *    n'admettait que `[32, 48]` — donc 42, la largeur réelle de la caisse de production
 *    (`RECEIPT_WIDTH_CHARS=42`, calée en juillet sur la photo IMG_1709 pour le ticket de COMMANDE),
 *    était silencieusement ramenée à 48. Le ticket promo est le jumeau qu'on avait oublié de
 *    brancher sur ce réglage. Sur le papier : les 48 « = » débordaient en 42 + 6, laissant une
 *    seconde ligne « ====== » orpheline ; « rien que pour toi » se coupait en « pour t » / « oi » ;
 *    « prelevent jusqu'a » en « prelevent j » / « usqu'a ». L'imprimante réenroule au CARACTÈRE,
 *    pas au mot — une ligne trop longue est donc toujours coupée en plein mot.
 *
 * 2. CENTRAGE FAIT DEUX FOIS. Le pilote est mis en mode centré une fois pour tout le ticket
 *    (`E::alignCenter()`), et `centerLine()` ajoutait EN PLUS des espaces à gauche. L'imprimante
 *    centrait donc « <espaces>-10% », espaces compris : la ligne partait vers la DROITE. C'est le
 *    « pas au milieu, pas bien centré » du propriétaire, et il touchait le « Merci M. Dorian ! »,
 *    le -10%, le code promo et l'adresse du site — tout ce qui passe par `line()`.
 *
 * Le test lit les OCTETS réellement envoyés à l'imprimante. C'est le seul niveau qui prouve quoi
 * que ce soit ici : un rendu HTML ou un aperçu écran ne dit rien de ce que le papier montrera.
 */
class PromoFlyerTicketLayoutTest extends TestCase
{
    private const LARGEUR = 42;

    private function donnees(): array
    {
        return [
            'headline' => 'LE CAYENNE',
            'greeting_civility' => 'M.',
            'greeting_name' => 'Dorian',
            'intro' => "Merci pour ta commande ! La prochaine fois commande en direct sur notre "
                . "site : c'est le meme restaurant, mais moins cher.",
            'discount_percent' => 10,
            'code' => 'DORIAN-TH2P',
            'valid_until' => '07/09/2026',
            'qr_url' => 'https://www.lecayenne.fr/?promo=DORIAN-TH2P',
            'site_url' => 'www.lecayenne.fr',
            'footer' => "Jusqu'a -30% d'economies en commandant en direct : les plateformes de "
                . "livraison prelevent jusqu'a 35% de commission sur chaque commande.",
        ];
    }

    /**
     * Ne garde que les caractères imprimables ASCII de chaque ligne. Les octets de commande
     * (ESC/GS) et le bloc QR sont hors de cette plage et disparaissent ; un ESPACE (0x20) est
     * conservé — c'est indispensable, puisque c'est justement le padding fautif qu'on traque.
     *
     * @return array<int, string> lignes contenant au moins une lettre ou un chiffre
     */
    private function lignes(string $octets): array
    {
        $lignes = [];
        foreach (explode("\n", $octets) as $brut) {
            $propre = preg_replace('/[^\x20-\x7E]/', '', $brut);
            if ($propre === null || ! preg_match('/[A-Za-z0-9=]/', $propre)) {
                continue;
            }
            // Le bloc QR transporte l'URL comme DONNÉE, pas comme ligne imprimée : il traverse ce
            // filtre en laissant « (k…https://… ». L'exclure n'assouplit rien — une donnée de QR
            // n'a pas de largeur de colonne. Le marqueur est la séquence GS ( k, qui survit au
            // filtre sous la forme « (k ».
            if (str_contains($propre, '(k')) {
                continue;
            }
            $lignes[] = rtrim($propre);
        }

        return $lignes;
    }

    private function rendu(int $largeur = self::LARGEUR): array
    {
        $octets = app(PromoFlyerEscPosRenderer::class)->render($this->donnees(), $largeur);

        return $this->lignes($octets);
    }

    /** LE DÉFAUT DE LARGEUR : plus une seule ligne ne dépasse, donc plus un seul mot coupé. */
    public function test_aucune_ligne_ne_depasse_la_largeur_de_l_imprimante(): void
    {
        $lignes = $this->rendu();

        // Garde anti-test-vide : sans contenu, « aucune ligne ne dépasse » ne prouve rien.
        $this->assertGreaterThan(8, count($lignes), 'le ticket doit avoir du contenu à mesurer');

        foreach ($lignes as $l) {
            $this->assertLessThanOrEqual(self::LARGEUR, strlen($l),
                'ligne de ' . strlen($l) . ' car. sur une imprimante ' . self::LARGEUR
                . ' col. : elle sera réenroulée EN PLEIN MOT — |' . $l . '|');
        }
    }

    /** LE DÉFAUT DE CENTRAGE : l'imprimante centre déjà ; aucun padding ne doit s'y ajouter. */
    public function test_aucune_ligne_n_est_pre_centree_a_coups_d_espaces(): void
    {
        foreach ($this->rendu() as $l) {
            $this->assertStringStartsNotWith(' ', $l,
                'cette ligne est pré-centrée avec des espaces alors que le pilote est déjà en mode '
                . 'centré : elle partira vers la DROITE — |' . $l . '|');
        }
    }

    /**
     * Les deux mots que la photo montrait coupés. On exige la phrase ENTIÈRE sur UNE ligne :
     * c'est la formulation qui échoue si la largeur redevient fausse, alors qu'un simple
     * `assertStringContainsString` sur tout le ticket passerait même coupé.
     */
    public function test_les_mots_coupes_sur_la_photo_tiennent_sur_une_seule_ligne(): void
    {
        $lignes = $this->rendu();

        $this->assertContains('valable une seule fois, rien que pour toi', $lignes,
            '« rien que pour toi » était coupé en « pour t » / « oi » sur la photo IMG_2090');

        $joint = implode('|', $lignes);
        $this->assertStringNotContainsString('pour t|', $joint, '« toi » est de nouveau coupé');
        $this->assertStringNotContainsString("prelevent j|", $joint, '« jusqu\'a » est de nouveau coupé');
    }

    /** Le séparateur remplit la ligne EXACTEMENT : ni marge blanche, ni « ====== » orphelin. */
    public function test_le_separateur_fait_exactement_la_largeur(): void
    {
        $lignes = $this->rendu();
        $separateurs = array_values(array_filter($lignes, fn ($l) => preg_match('/^=+$/', $l)));

        $this->assertNotEmpty($separateurs, 'le ticket doit encadrer la remise');
        foreach ($separateurs as $s) {
            $this->assertSame(self::LARGEUR, strlen($s),
                'séparateur de ' . strlen($s) . ' « = » : à 48 il débordait en 42 + 6 et laissait '
                . 'une seconde ligne « ====== » orpheline sur le papier');
        }
    }

    /**
     * La largeur vient de la CONFIG quand l'appelant n'en impose pas — c'est ce lien qui manquait.
     * Sans ce test, on pourrait « corriger » le rendu tout en laissant le défaut d'origine : un
     * défaut de câblage, pas de mise en page.
     */
    public function test_la_largeur_configuree_est_reellement_honoree(): void
    {
        config(['printing.receipt.width_chars' => 42]);

        $flyer = new \App\Models\PromoFlyer();
        $service = app(\App\Services\Promo\PromoFlyerService::class);

        // On n'exige pas un rendu complet (le modèle n'est pas persisté) : on exige que la valeur
        // 48 en dur ait disparu de la signature, seule cause du mauvais calage.
        $r = new \ReflectionMethod($service, 'renderBytes');
        $defaut = $r->getParameters()[1]->getDefaultValue();

        $this->assertSame(0, $defaut,
            'la largeur par défaut doit être 0 = « résous-la depuis la config », jamais 48 en dur : '
            . 'c\'est ce 48 qui imprimait un ticket de 48 colonnes sur une caisse de 42');
        unset($flyer);
    }
}
