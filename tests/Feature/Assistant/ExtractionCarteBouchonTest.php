<?php

namespace Tests\Feature\Assistant;

use App\Services\Menu\Vision\MenuExtractionContract;
use App\Services\Menu\Vision\MockMenuExtractionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [ONB-04 2026-08-27] L'extraction de carte est bouchonnée, et le reste tant que
 * le propriétaire n'a pas tranché le gate G-IA.
 *
 * Ce test garde trois promesses :
 *  1. rien ne sort de la machine par défaut ;
 *  2. la proposition est déterministe — un bouchon qui varie masque les vrais
 *     défauts derrière du bruit ;
 *  3. la forme du contrat est celle que l'écran de validation attend.
 *
 * Il ne teste PAS la qualité de lecture d'une IA : ça, seul un appel réel le
 * dirait, et il est interdit ici.
 */
class ExtractionCarteBouchonTest extends TestCase
{
    // Necessaire pour la derniere assertion, qui compte les articles en base afin de
    // prouver que la lecture n'ecrit rien.
    use RefreshDatabase;

    public function test_le_conteneur_resout_le_bouchon_par_defaut(): void
    {
        config(['assistant.enabled' => false]);

        $this->assertInstanceOf(
            MockMenuExtractionService::class,
            app(MenuExtractionContract::class),
            'Sans gate G-IA tranché, aucune implémentation réelle ne doit être choisie.'
        );
    }

    public function test_le_bouchon_reste_choisi_meme_si_on_active_le_drapeau_sans_cle(): void
    {
        // Deux verrous, jamais un : un drapeau basculé par erreur ne doit pas
        // suffire à faire sortir une requête de la machine.
        config(['assistant.enabled' => true, 'services.openai.key' => '']);

        $this->assertInstanceOf(
            MockMenuExtractionService::class,
            app(MenuExtractionContract::class)
        );
    }

    public function test_le_plafond_de_depense_est_nul_par_defaut(): void
    {
        $this->assertSame(
            0.0,
            (float) config('assistant.budget.plafond_mensuel_euros'),
            "Le plafond par défaut doit être zéro : le projet n'a aucun compteur de coût, "
            . 'et un commerçant ne doit pas découvrir la facture après coup.'
        );
    }

    public function test_la_lecture_rend_la_forme_attendue_par_l_ecran_de_validation(): void
    {
        $resultat = app(MenuExtractionContract::class)->lireCarte('/chemin/fictif/carte.jpg');

        $this->assertArrayHasKey('categories', $resultat);
        $this->assertArrayHasKey('articles', $resultat);
        $this->assertArrayHasKey('source', $resultat);
        $this->assertArrayHasKey('tronquee', $resultat);
        $this->assertSame('bouchon', $resultat['source'], "L'écran doit pouvoir dire d'où vient ce qu'il affiche.");

        foreach ($resultat['articles'] as $a) {
            $this->assertArrayHasKey('nom', $a);
            $this->assertArrayHasKey('categorie', $a);
            $this->assertArrayHasKey('prix', $a);
            $this->assertArrayHasKey('confiance', $a);
        }
    }

    public function test_la_lecture_est_deterministe(): void
    {
        $service = app(MenuExtractionContract::class);

        $this->assertSame(
            $service->lireCarte('/chemin/fictif/carte.jpg'),
            $service->lireCarte('/chemin/fictif/carte.jpg'),
            'Un bouchon qui varie rend les tests instables et masque les vrais défauts.'
        );
    }

    public function test_la_fixture_contient_les_cas_qui_font_mal(): void
    {
        $articles = app(MenuExtractionContract::class)
            ->lireCarte('/chemin/fictif/carte.jpg')['articles'];

        $prixIllisible = array_filter($articles, static fn ($a) => $a['prix'] === null);
        $this->assertNotEmpty(
            $prixIllisible,
            "La fixture doit contenir un prix illisible : l'écran doit le faire SAISIR, jamais l'inventer."
        );

        $seuil = (float) config('assistant.menu_extraction.seuil_confiance', 0.75);
        $douteux = array_filter($articles, static fn ($a) => $a['confiance'] < $seuil);
        $this->assertNotEmpty(
            $douteux,
            'La fixture doit contenir une ligne sous le seuil de confiance, pour que la chaîne '
            . 'prouve qu’elle la SIGNALE au lieu de l’écarter.'
        );

        $noms = array_column($articles, 'nom');
        $this->assertNotSame(
            count($noms),
            count(array_unique($noms)),
            "La fixture doit contenir deux articles de MÊME NOM : le catalogue impose l'unicité, "
            . 'et le conflit doit se voir à la validation, pas exploser à l’écriture.'
        );
    }

    public function test_le_bouchon_n_ecrit_rien_en_base(): void
    {
        // La promesse centrale du GOAL : l'IA propose, l'humain valide, le système
        // applique. On vérifie qu'aucune écriture n'a lieu pendant la lecture.
        $avant = \Illuminate\Support\Facades\DB::table('items')->count();

        app(MenuExtractionContract::class)->lireCarte('/chemin/fictif/carte.jpg');

        $this->assertSame(
            $avant,
            \Illuminate\Support\Facades\DB::table('items')->count(),
            "La lecture ne doit créer AUCUN article : elle propose, elle n'applique pas."
        );
    }
}
