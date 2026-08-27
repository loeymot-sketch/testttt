<?php

namespace Tests\Feature\Ux;

use Tests\TestCase;

/**
 * [ONB-11 2026-08-27] Le logiciel s'adresse au commerçant d'une seule façon.
 *
 * Mesure avant correctif : **4 chaînes tutoyaient, 108 vouvoyaient**. Ce n'était pas
 * un choix de ton, c'était quatre oublis — dont le bandeau d'avertissement de la page
 * Conso & Stock, vu à l'écran : « pose les prix ou scanne tes factures pour valoriser
 * ton stock », au milieu d'une interface qui vouvoie partout ailleurs.
 *
 * Le défaut est mineur pris isolément. Il cesse de l'être quand on considère à qui
 * on parle : un restaurateur qui vient d'acheter un logiciel de caisse, et qui sent
 * que le produit ne sait pas s'il s'adresse à un client ou à un collègue.
 *
 * Cette sentinelle balaye le fichier de langue français et échoue si le tutoiement
 * revient. Elle n'impose pas le vouvoiement dans l'absolu — elle impose la
 * COHÉRENCE : si un jour le produit choisit de tutoyer, c'est la liste des 108 qu'il
 * faudra changer, et ce test le rappellera.
 */
class RegistreDeLangueCoherentTest extends TestCase
{
    private function chainesFrancaises(): array
    {
        $chemin = resource_path('js/languages/fr.json');
        $this->assertFileExists($chemin);

        $json = json_decode(file_get_contents($chemin), true, 512, JSON_THROW_ON_ERROR);

        $plates = [];
        $aplatir = function ($noeud, string $chemin) use (&$aplatir, &$plates): void {
            if (is_array($noeud)) {
                foreach ($noeud as $cle => $valeur) {
                    $aplatir($valeur, $chemin === '' ? (string) $cle : "{$chemin}.{$cle}");
                }

                return;
            }
            if (is_string($noeud)) {
                $plates[$chemin] = $noeud;
            }
        };
        $aplatir($json, '');

        return $plates;
    }

    public function test_aucune_chaine_ne_tutoie_le_commercant(): void
    {
        $coupables = [];

        foreach ($this->chainesFrancaises() as $cle => $valeur) {
            // On vise les possessifs et pronoms de la 2e personne du singulier.
            // `ta` et `ton` sont volontairement bornés par \b pour ne pas attraper
            // « tableau », « tonalité » ou un mot qui les contient.
            if (preg_match('/\b(tes|ton|ta|tu)\b/iu', $valeur)) {
                $coupables[] = "{$cle} = " . mb_substr($valeur, 0, 80);
            }
        }

        $this->assertSame(
            [],
            $coupables,
            "Des chaînes tutoient le commerçant alors que l'interface le vouvoie partout\n"
            . "ailleurs (108 chaînes mesurées). Un produit qui hésite entre deux registres\n"
            . "donne l'impression de ne pas savoir à qui il parle.\n\n"
            . implode("\n", $coupables)
        );
    }

    public function test_la_sentinelle_mord(): void
    {
        // Un contrôle négatif : la recherche doit effectivement attraper un tutoiement
        // si on en plante un. Sans ça, un test vert ne prouverait rien.
        $faux = 'Pense à scanner tes factures.';

        $this->assertMatchesRegularExpression(
            '/\b(tes|ton|ta|tu)\b/iu',
            $faux,
            'Le motif doit détecter un tutoiement.'
        );

        // Et il ne doit PAS se déclencher sur un mot qui contient les mêmes lettres.
        $this->assertDoesNotMatchRegularExpression(
            '/\b(tes|ton|ta|tu)\b/iu',
            'Ouvrez le tableau de bord pour consulter la tonalité.',
            'Le motif ne doit pas attraper « tableau » ni « tonalité ».'
        );
    }
}
