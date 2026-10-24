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

    /**
     * [ONB 2026-08-28] CE BANC AFFIRMAIT CE QU'IL NE MESURAIT PAS.
     *
     * Il s'appelait « le bouchon reste choisi même si on active le drapeau sans
     * clé » et son commentaire disait « deux verrous, jamais un ». Or
     * `AssistantServiceProvider::register()` renvoie le bouchon dans LES DEUX
     * branches — son propre commentaire l'assume. Le banc aurait donc été vert avec
     * un seul verrou, avec zéro verrou, ou avec n'importe quelle condition.
     *
     * Pire qu'inutile : il prétendait attester la propriété de sécurité centrale du
     * module, ce qui ferme la question pour quiconque la relit.
     *
     * On mesure désormais la vérité d'aujourd'hui, qui est forte et vérifiable :
     * AUCUNE implémentation réelle n'existe, donc AUCUNE requête ne peut sortir —
     * même drapeau levé ET clé renseignée. Le vrai double verrou sera testable le
     * jour où il aura quelque chose à verrouiller ; le banc suivant l'exige.
     */
    public function test_aucune_requete_ne_peut_sortir_aujourdhui_quels_que_soient_les_reglages(): void
    {
        foreach ([
            ['assistant.enabled' => false, 'services.openai.key' => ''],
            ['assistant.enabled' => true,  'services.openai.key' => ''],
            ['assistant.enabled' => true,  'services.openai.key' => 'sk-une-cle-qui-ressemble-a-une-vraie'],
        ] as $reglages) {
            config($reglages);
            app()->forgetInstance(MenuExtractionContract::class);

            $this->assertInstanceOf(
                MockMenuExtractionService::class,
                app(MenuExtractionContract::class),
                "Aucune implémentation réelle n'existe tant que le gate G-IA n'est pas\n"
                . "tranché : le conteneur doit rendre le bouchon quels que soient les\n"
                . 'réglages, y compris avec une clé qui a l\'air vraie. Réglages : '
                . json_encode($reglages)
            );
        }
    }

    /**
     * [ONB 2026-08-28] Le garde qui mord le jour de la bascule.
     *
     * Tant qu'aucune implémentation réelle n'existe, le banc ci-dessus suffit. Le
     * jour où quelqu'un en écrit une, il DOIT écrire aussi un vrai test du double
     * verrou — et ce banc-ci échoue pour le lui rappeler, au lieu de laisser une
     * tautologie couvrir la bascule la plus sensible du module.
     */
    public function test_le_jour_ou_une_implementation_reelle_arrive_le_double_verrou_devra_etre_prouve(): void
    {
        $implementations = [];

        $dossier = app_path('Services/Menu/Vision');
        foreach (scandir($dossier) ?: [] as $fichier) {
            if (! str_ends_with($fichier, '.php')) {
                continue;
            }

            $classe = 'App\\Services\\Menu\\Vision\\' . substr($fichier, 0, -4);

            if (! class_exists($classe) || ! is_subclass_of($classe, MenuExtractionContract::class)) {
                continue;
            }

            $implementations[] = $classe;
        }

        $this->assertSame(
            [MockMenuExtractionService::class],
            $implementations,
            "Une implémentation de `MenuExtractionContract` autre que le bouchon vient\n"
            . "d'apparaître. Le banc précédent ne prouve alors plus rien sur le double\n"
            . "verrou : il faut désormais un test qui montre qu'un drapeau levé SANS clé\n"
            . "rend toujours le bouchon, et qu'un drapeau baissé AVEC clé le rend aussi.\n"
            . 'Vérifiez aussi le plafond de dépense — le projet n\'a aucun compteur de coût.'
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
