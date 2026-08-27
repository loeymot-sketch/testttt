<?php

namespace Tests\Feature\Security;

use App\Http\Requests\LanguageFileTextStoreRequest;
use App\Services\LanguageService;
use Tests\TestCase;

/**
 * [ONB-13 2026-08-28] La valeur écrite dans un fichier de langue n'était pas échappée.
 *
 * `LanguageService::fileTextStore()` réinjectait chaque traduction VERBATIM entre
 * guillemets doubles — `str_replace("'{$cle}'", "\"{$valeur}\"", $contenu)` — puis
 * réécrivait le fichier. Or `lang/fr/all.php` est un `<?php return [...]` que le
 * traducteur de Laravel **inclut à chaque requête traduite**. Une valeur portant un
 * guillemet sort de la chaîne ; un `$` y est interpolé. Écrire dans ce fichier, c'est
 * écrire du code exécuté.
 *
 * DEUX DÉFENSES EXISTAIENT DÉJÀ, et elles sont réelles — ce banc ne les remplace pas :
 *   · le CHEMIN est confiné depuis le 2026-05-24 (realpath, deux répertoires,
 *     extensions php/json), avec sa propre sentinelle
 *     `LanguageServicePathContainmentSentinelTest` ;
 *   · l'ACCÈS est gardé par `permission:settings`, posé en réponse explicite à
 *     « [P0 SEC-RCE] … file_put_contents = arbitrary file write ».
 *
 * La troisième manquait. Et c'est celle qui compte quand les deux autres tombent :
 * un chemin confiné ne protège de rien si le CONTENU écrit dans le fichier autorisé
 * est arbitraire. La sentinelle du chemin était verte depuis trois mois — elle
 * attestait la moitié fermée de la porte.
 *
 * Ce banc ne « prouve pas une exécution » : il n'exécute aucune charge. Il prouve la
 * propriété qui la rend impossible — après écriture, le fichier reste un tableau PHP
 * valide dont la valeur est EXACTEMENT ce qui a été soumis, guillemets et dollars
 * compris. Une injection réussie casserait l'une ou l'autre de ces deux assertions.
 */
class EcritureFichierLangueEchappeeTest extends TestCase
{
    private string $dossier;
    private string $fichierPhp;
    private string $fichierJson;

    protected function setUp(): void
    {
        parent::setUp();

        // Le confinement exige un fichier RÉEL sous `lang/` ou
        // `resources/js/languages/` : on travaille donc là, et on nettoie après.
        $this->dossier = base_path('lang/zz_onb13_test');
        if (! is_dir($this->dossier)) {
            mkdir($this->dossier, 0755, true);
        }

        $this->fichierPhp = $this->dossier . '/all.php';
        file_put_contents(
            $this->fichierPhp,
            "<?php\n\nreturn [\n    'accueil' => 'Bienvenue',\n];\n"
        );

        $this->fichierJson = base_path('resources/js/languages/zz_onb13_test.json');
        file_put_contents($this->fichierJson, '{"accueil": "Bienvenue"}');
    }

    protected function tearDown(): void
    {
        foreach ([$this->fichierPhp, $this->fichierJson] as $f) {
            if (is_file($f)) {
                unlink($f);
            }
        }
        if (is_dir($this->dossier)) {
            rmdir($this->dossier);
        }

        parent::tearDown();
    }

    private function ecrire(string $chemin, string $cle, string $valeur): void
    {
        $requete = new LanguageFileTextStoreRequest();
        $requete->merge([
            'x_language_file_path' => $chemin,
            'x_language_file_name' => basename($chemin),
            $cle                   => $valeur,
        ]);

        app(LanguageService::class)->fileTextStore($requete);
    }

    /**
     * La charge type : sortir de la chaîne et ouvrir une instruction. Si l'échappement
     * manque, le fichier écrit contient `"x"; …` et n'est plus le tableau attendu.
     */
    public function test_un_guillemet_ne_sort_pas_de_la_chaine_dans_un_fichier_php(): void
    {
        $charge = 'x"; /* echappe */ $GLOBALS["onb13"] = 1; "';

        $this->ecrire($this->fichierPhp, 'Bienvenue', $charge);

        $rendu = include $this->fichierPhp;

        $this->assertIsArray(
            $rendu,
            "Le fichier de langue n'est plus un tableau PHP valide après écriture :\n"
            . "la valeur soumise est sortie de sa chaîne. C'est une exécution de code\n"
            . 'dans un fichier inclus à chaque requête traduite.'
        );
        $this->assertSame(
            $charge,
            $rendu['accueil'] ?? null,
            "La traduction doit être relue EXACTEMENT telle que soumise. Une valeur\n"
            . "tronquée ou altérée signifie que des caractères ont été interprétés\n"
            . 'au lieu d\'être stockés.'
        );
        $this->assertArrayNotHasKey(
            'onb13',
            $GLOBALS,
            'La charge a été exécutée : le fichier écrit contenait du code actif.'
        );
    }

    /** Le `$` ne doit pas être interpolé : `"{$x}"` s'évaluerait à l'inclusion. */
    public function test_un_dollar_n_est_pas_interpole(): void
    {
        $charge = 'Prix : ${montant} et $total';

        $this->ecrire($this->fichierPhp, 'Bienvenue', $charge);

        $rendu = include $this->fichierPhp;

        $this->assertIsArray($rendu);
        $this->assertSame($charge, $rendu['accueil'] ?? null);
    }

    /** Une apostrophe est légitime en français et doit survivre intacte. */
    public function test_une_apostrophe_francaise_survit(): void
    {
        $charge = "L'article n'est pas disponible aujourd'hui";

        $this->ecrire($this->fichierPhp, 'Bienvenue', $charge);

        $rendu = include $this->fichierPhp;

        $this->assertIsArray($rendu);
        $this->assertSame($charge, $rendu['accueil'] ?? null);
    }

    /** Même exigence côté JSON : le fichier doit rester analysable. */
    public function test_un_guillemet_ne_casse_pas_le_fichier_json(): void
    {
        $charge = 'dit "bonjour" et \\ recule';

        $this->ecrire($this->fichierJson, 'Bienvenue', $charge);

        $rendu = json_decode((string) file_get_contents($this->fichierJson), true);

        $this->assertIsArray(
            $rendu,
            "Le fichier JSON n'est plus analysable après écriture."
        );
        $this->assertSame($charge, $rendu['accueil'] ?? null);
    }

    /**
     * Contrôle négatif : l'échappement ne doit pas dispenser de la validation. Un
     * saut de ligne permettrait d'écrire une instruction indépendante — même
     * raisonnement que le garde-fou anti-injection du `.env` ailleurs dans ce dépôt.
     */
    public function test_un_retour_a_la_ligne_est_refuse_par_la_validation(): void
    {
        $requete = new LanguageFileTextStoreRequest();
        $regles = $requete->rules();

        $this->assertArrayHasKey('*', $regles);

        $validateur = validator(
            ['Bienvenue' => "avant\nAPRES"],
            ['Bienvenue' => $regles['*']]
        );

        $this->assertTrue(
            $validateur->fails(),
            "Un retour à la ligne doit être refusé : dans un fichier PHP il permet\n"
            . 'd\'écrire une instruction indépendante.'
        );
    }
}
