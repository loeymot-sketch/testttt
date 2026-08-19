<?php

namespace Tests\Feature\Kitchen;

use App\Services\Kitchen\MeatMaterialResolver;
use App\Services\Kitchen\MeatPortionCalculator;
use Database\Seeders\RawMaterialBaselineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [OWNER 2026-08-19] VERROU : le bandeau de cuisson et le stock matière disent la même chose.
 *
 * POURQUOI CE TEST EXISTE
 * -----------------------
 * Le bandeau CUISSON n'est pas qu'un affichage : les MÊMES pièces alimentent la consommation de
 * matière première. Deux façons de casser ça en silence ont été rencontrées le même soir, aucune
 * n'aurait fait rougir une suite :
 *
 *   1. DOUBLER LE COMPTE SANS DIVISER LE POIDS. Le poulet est passé de « 1 portion de 200 g » à
 *      « 2 pièces de 100 g » à la demande du propriétaire. Toucher l'une des deux valeurs seule
 *      aurait fait sortir 400 g de poulet par Cayenne au lieu de 200 — +100 % sur la matière la
 *      plus vendue, sans le moindre signe à l'écran.
 *
 *   2. MAPPER UN SYMBOLE VERS UNE MATIÈRE QUI N'EXISTE PAS EN BASE. Découvert en LISANT la
 *      production le 2026-08-19, puis MESURÉ sur le serveur juste après le déploiement :
 *
 *          php artisan stock:ensure-meat-materials --dry-run
 *          → « 7 création(s), 0 alignement(s) »
 *
 *      SEPT viandes sur dix n'avaient aucune matière en base au restaurant : Poulet mariné,
 *      Mexicanos, Tenders, Nuggets, Fricadelle, Chicken burger, Poisson pané. Le chemin du
 *      moteur de CUISSON ne résolvait donc rien pour elles. Ce n'est PAS silencieux (le
 *      résolveur empile `matiere_absente` et journalise), mais personne ne lisait les journaux.
 *      La base de DÉVELOPPEMENT, elle, portait bien « Poulet mariné » : c'est cet écart
 *      local ↔ production qui a rendu le trou invisible des deux côtés pendant treize jours.
 *
 *      ⚠️ NE PAS SUR-INTERPRÉTER : « le moteur de cuisson ne résout rien » ≠ « rien n'est
 *      décompté ». Un SECOND moteur, celui des recettes (`raw_material_recipe_lines`),
 *      décomptait bien le poulet EN VRAC (matière « Poulet », id 2, forfait 200 g sur les items
 *      22 et 38) — mesuré à −28 400 g en production. La première lecture avait conclu « rien
 *      n'est décompté » en interrogeant `stock_levels` / `stock_movements`, qui sont les tables
 *      des PRODUITS, au lieu de `raw_material_stocks` / `raw_material_movements` (4 780
 *      mouvements réels). La mauvaise table rend « 0 » et fabrique une conclusion fausse :
 *      quand deux lectures se contredisent, examiner le JUGE avant la preuve.
 *
 *      Le « 0 alignement » de la même mesure prouve au passage que la migration du poids
 *      unitaire a été un no-op en production — la ligne à corriger n'existait pas.
 *
 * Ce test verrouille les deux : l'accord des valeurs, et l'état réellement atteignable de la base.
 * Il ne décide RIEN pour le propriétaire — mettre en route le décompte du poulet en production
 * reste sa décision (cf. le dernier cas, qui prouve seulement que le chemin existe).
 */
class CuissonStockCoherenceSentinelTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Poids réel d'une portion complète de poulet, en grammes. C'est la CONSTANTE PHYSIQUE :
     * elle ne doit pas bouger quand on change l'unité d'affichage.
     */
    private const PORTION_POULET_GRAMMES = 200.0;

    /**
     * VERROU 1 — pièces × poids unitaire = la portion servie, toujours.
     *
     * Mesuré par l'API publique, sans réflexion : c'est le produit des deux valeurs couplées qui
     * compte, pas leur valeur prise isolément. Quiconque double l'une sans diviser l'autre fait
     * rougir ce test avant que le stock ne parte de travers.
     */
    public function test_une_portion_de_poulet_pese_toujours_deux_cents_grammes(): void
    {
        $poidsUnite = (float) MeatMaterialResolver::MATIERES_A_CREER['Poulet mariné'][1];
        $calc = new MeatPortionCalculator;

        $entier = $calc->forLine('Cayenne', $this->snapViandes(['Poulet mariné']))['pieces']['P'];
        $this->assertSame(
            self::PORTION_POULET_GRAMMES,
            $entier * $poidsUnite,
            'Un produit à UNE viande sert une portion complète de poulet. Si ce produit change, '
            .'c\'est que PORTION_PAR_VIANDE ou MATIERES_A_CREER a bougé sans l\'autre : la '
            .'consommation réelle de poulet vient de doubler ou d\'être divisée par deux.'
        );

        $mixte = $calc->forLine('Méga', $this->snapViandes(['Poulet mariné', 'Viande Hachée']))['pieces']['P'];
        $this->assertSame(
            self::PORTION_POULET_GRAMMES / 2,
            $mixte * $poidsUnite,
            'Un emplacement partagé sert une demi-portion, soit 100 g.'
        );

        $bol = $calc->forLine('Bol Riz', $this->snapViandes(['Poulet mariné']))['pieces']['P'];
        $this->assertSame(
            self::PORTION_POULET_GRAMMES / 2,
            $bol * $poidsUnite,
            'Owner 2026-08-19 : « sur les bols on mettra qu\'une seule » — le bol est le SEUL '
            .'déplacement de stock voulu par ce changement.'
        );
    }

    /**
     * VERROU 2 — tout symbole de cuisson désigne une matière que le système sait faire exister.
     *
     * Un symbole mappé vers un nom que ni le seeder de base ni `MATIERES_A_CREER` ne produisent
     * ne serait JAMAIS décompté, quelle que soit la base. On lit les deux sources plutôt que de
     * recopier une liste : une liste recopiée finit toujours par diverger de son original.
     */
    public function test_chaque_symbole_de_cuisson_designe_une_matiere_que_le_systeme_sait_creer(): void
    {
        $this->seed(RawMaterialBaselineSeeder::class);

        $connues = array_map(
            static fn (string $n): string => mb_strtolower($n),
            array_merge(
                \App\Models\RawMaterial::query()->pluck('name')->all(),
                array_keys(MeatMaterialResolver::MATIERES_A_CREER),
            )
        );

        foreach (MeatMaterialResolver::SYMBOLE_VERS_MATIERE as $symbole => $matiere) {
            $this->assertContains(
                mb_strtolower($matiere),
                $connues,
                "Le symbole « {$symbole} » pointe « {$matiere} », que rien ne crée : cette viande "
                .'ne sera décomptée du stock dans AUCUNE base. Câbler le nom dans '
                .'MATIERES_A_CREER ou dans RawMaterialBaselineSeeder.'
            );
        }
    }

    /**
     * VERROU 3 — L'ÉTAT RÉEL DE LA PRODUCTION, épinglé nommément.
     *
     * Le seeder de base est EXACTEMENT ce que porte le serveur du restaurant : 13 matières, lues
     * une à une sur le VPS le 2026-08-19, et confirmées par un
     * `stock:ensure-meat-materials --dry-run` qui a répondu « 7 création(s), 0 alignement(s) ».
     * Les sept symboles ci-dessous n'y résolvent rien. Ce test ne prétend pas que c'est bien : il
     * prouve que c'est SIGNALÉ — jamais consommé à zéro en douce — et il rend l'écart visible
     * dans la suite plutôt que dans un journal que personne n'ouvre.
     *
     * Le jour où quelqu'un ajoute `stock:ensure-meat-materials` au script de déploiement, ce test
     * rougira : ce sera le bon moment pour le mettre à jour, en connaissance de cause.
     */
    public function test_avec_le_seul_seeder_de_base_les_viandes_manquantes_sont_signalees_pas_silencieuses(): void
    {
        $this->seed(RawMaterialBaselineSeeder::class);

        $res = (new MeatMaterialResolver)->toMaterialQuantities($this->uneUniteParSymbole(), 1);

        $absents = array_column(
            array_filter($res['skipped'], static fn (array $s): bool => $s['reason'] === 'matiere_absente'),
            'symbol'
        );
        sort($absents);

        $this->assertSame(
            ['Chick', 'Frec', 'Mex', 'Nug', 'P', 'Poi', 'Tender'],
            $absents,
            'État MESURÉ de la production au 2026-08-19 avant activation (« 7 création(s), '
            .'0 alignement(s) ») : pour ces sept viandes, le moteur de CUISSON ne résout aucune '
            .'matière. Elles sont SIGNALÉES (matiere_absente + Log::warning), ce qui est le '
            .'comportement voulu — consommer zéro en silence serait pire. Cela ne dit RIEN du '
            .'moteur des recettes, qui décomptait par ailleurs le poulet en vrac.'
        );

        $this->assertCount(
            3,
            $res['totals'],
            'Seules la viande hachée, le cordon bleu et la portion de frites existent au seeder '
            .'de base : ce sont les seules réellement décomptées aujourd\'hui.'
        );
    }

    /**
     * VERROU 4 — le chemin de sortie existe et il est prouvé.
     *
     * `stock:ensure-meat-materials` referme l'écart du verrou 3. Le lancer en production METTRAIT
     * EN ROUTE le décompte de SEPT viandes qui n'existe pas aujourd'hui : c'est un choix métier
     * du propriétaire, pas une étape de déploiement — et c'est pourquoi la commande a été passée
     * en `--dry-run` seul le soir du déploiement du 2026-08-19. Ce test prouve seulement que le
     * jour où il le décide, ça marche — et avec le bon poids.
     */
    public function test_la_commande_dediee_referme_lecart_avec_le_bon_poids(): void
    {
        $this->seed(RawMaterialBaselineSeeder::class);
        $this->artisan('stock:ensure-meat-materials', ['--branch' => 1])->assertSuccessful();

        $res = (new MeatMaterialResolver)->toMaterialQuantities($this->uneUniteParSymbole(), 1);

        $this->assertSame([], $res['skipped'], 'Après la commande, plus aucune viande ne tombe dans le trou.');
        $this->assertCount(count(MeatMaterialResolver::SYMBOLE_VERS_MATIERE), $res['totals']);

        $this->assertSame(
            100.0,
            (float) \App\Models\RawMaterial::query()->whereRaw("LOWER(name) = 'poulet mariné'")->value('piece_weight_g'),
            'La commande doit poser le poids de l\'UNITÉ COMPTÉE (100 g), pas celui de la portion '
            .'servie (200 g) — sinon le compte doublé double aussi la sortie de stock.'
        );
    }

    /**
     * VERROU 5 — LE PLUS IMPORTANT : aucune viande ne peut être décomptée DEUX FOIS.
     *
     * Deux moteurs peuvent servir la même ligne de commande :
     *   · le moteur des RECETTES (`raw_material_recipe_lines`, forfait par produit) ;
     *   · le moteur des PORTIONS (le bandeau CUISSON, depuis le choix réel du client).
     *
     * `RawMaterialConsumptionService::matieresReprises()` empêche le doublon en écartant les
     * lignes de recette dès que le moteur de portions a quelque chose à dire — mais il décide
     * cela en comparant le NOM de la matière à deux listes EN DUR, `VIANDES_PILOTEES` et
     * `FRITES_PILOTEES`. Une viande câblée dans `SYMBOLE_VERS_MATIERE` mais oubliée dans ces
     * listes serait donc décomptée par les DEUX moteurs, en silence, sans aucun signal : ni
     * exception, ni journal, ni test rouge. Juste un stock qui fond deux fois plus vite.
     *
     * C'est le motif dominant de ce projet — « un correctif appliqué à une moitié du mécanisme,
     * pas à sa jumelle » — appliqué ici à deux listes de noms qui doivent rester en accord.
     * Ce verrou est devenu critique le 2026-08-19, jour où le décompte des sept viandes
     * manquantes a été ACTIVÉ en production : avant, une viande orpheline ne consommait rien ;
     * depuis, elle consommerait double.
     */
    public function test_aucune_viande_du_bandeau_ne_peut_etre_decomptee_deux_fois(): void
    {
        $service = new \ReflectionClass(\App\Services\RawMaterials\RawMaterialConsumptionService::class);
        $pilotees = array_merge(
            (array) $service->getConstant('VIANDES_PILOTEES'),
            (array) $service->getConstant('FRITES_PILOTEES'),
        );

        foreach (MeatMaterialResolver::SYMBOLE_VERS_MATIERE as $symbole => $matiere) {
            $this->assertContains(
                mb_strtolower($matiere),
                $pilotees,
                "Le symbole « {$symbole} » décompte « {$matiere} » via le moteur de PORTIONS, mais "
                ."ce nom est absent de VIANDES_PILOTEES / FRITES_PILOTEES : les lignes de recette "
                ."portant cette matière ne seront donc PAS écartées, et elle sera décomptée DEUX "
                ."FOIS — sans exception, sans journal, sans test rouge. Ajouter le nom dans "
                .'RawMaterialConsumptionService, en minuscules.'
            );
        }
    }

    /** Une unité de CHAQUE symbole de cuisson, pour exercer le résolveur de bout en bout. */
    private function uneUniteParSymbole(): array
    {
        return array_fill_keys(array_keys(MeatMaterialResolver::SYMBOLE_VERS_MATIERE), 1.0);
    }

    /**
     * Forme canonique du composition_snapshot (clé `lines`), miroir de MeatPortionCalculatorTest.
     *
     * @param  array<int, string>  $viandes
     * @return array<string, mixed>
     */
    private function snapViandes(array $viandes): array
    {
        $lines = [];
        foreach (array_values($viandes) as $i => $nom) {
            $lines[] = ['attribute_name' => 'Viande '.($i + 1), 'variation_name' => $nom];
        }

        return ['lines' => $lines, 'extras' => [], 'addons' => []];
    }
}
