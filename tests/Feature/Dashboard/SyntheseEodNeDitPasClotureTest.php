<?php

namespace Tests\Feature\Dashboard;

use Tests\TestCase;

/**
 * [GOAL CONVERGENCE 2026-09-03 · superviseur] Un bouton ne doit pas promettre une clôture
 * fiscale qu'il ne fait pas.
 *
 * `DashboardController::eodPdf()` délègue à `DashboardService::eodSynthesis()`, qui est une
 * lecture pure : aucune écriture, aucun `ZReport`, aucune allocation de séquence fiscale.
 * Elle ne clôture rien.
 *
 * Le bouton, lui, s'appelait « PDF Clôture du jour » — sur une surface NF525, où « clôture »
 * a un sens précis, opposable, et irréversible. Un exploitant qui croit avoir clôturé sa
 * journée en téléchargeant un PDF ne fera pas la vraie clôture. Le défaut n'est pas dans le
 * code : il est dans la phrase, et c'est l'exploitant qui le paie.
 *
 * Ce banc tient les deux moitiés :
 *   1. le libellé ne dit plus « clôture » ;
 *   2. la synthèse reste bien une lecture pure — si un jour elle se met à écrire, ce banc
 *      devient faux et doit être rouvert, pas contourné.
 */
class SyntheseEodNeDitPasClotureTest extends TestCase
{
    private function libelle(string $locale): string
    {
        $chemin = base_path("resources/js/languages/{$locale}.json");
        $json = json_decode((string) file_get_contents($chemin), true);

        return (string) ($json['label']['eod_pdf_button'] ?? '');
    }

    public function test_le_libelle_francais_ne_promet_pas_une_cloture(): void
    {
        $libelle = $this->libelle('fr');

        $this->assertNotSame('', $libelle, 'le libellé du bouton doit exister');
        $this->assertStringNotContainsStringIgnoringCase(
            'clôtur',
            $libelle,
            "« clôture » a un sens fiscal précis et irréversible. Ce bouton ne fait qu'une "
            .'synthèse en lecture : le dire autrement expose à croire la journée close.'
        );
    }

    public function test_le_libelle_anglais_ne_promet_pas_une_cloture(): void
    {
        $libelle = $this->libelle('en');

        $this->assertNotSame('', $libelle);
        foreach (['closing', 'close', 'closure'] as $mot) {
            $this->assertStringNotContainsStringIgnoringCase(
                $mot,
                $libelle,
                "le libellé anglais ne doit pas non plus annoncer une clôture (mot « {$mot} »)"
            );
        }
    }

    /**
     * L'autre moitié du contrat. Si la synthèse se mettait à écrire, le libellé ci-dessus
     * deviendrait faux dans l'autre sens — et il vaut mieux que ce banc le dise que de le
     * découvrir à un contrôle.
     */
    public function test_la_synthese_reste_une_lecture_pure(): void
    {
        $src = file_get_contents(base_path('app/Services/DashboardService.php'));
        $debut = strpos($src, 'public function eodSynthesis(');
        $this->assertNotFalse($debut, 'eodSynthesis() doit exister');

        // Bornage sur la méthode suivante, à défaut d'analyse syntaxique : suffisant ici,
        // et le test échoue bruyamment si la forme du fichier change.
        $suite = strpos($src, "\n    public function ", $debut + 10);
        $corps = substr($src, $debut, $suite === false ? null : $suite - $debut);

        // Les COMMENTAIRES sont retirés avant l'examen. Première version de ce banc : elle
        // rougissait sur un renvoi croisé « ZReportService::applyOrderToTotals » écrit en
        // commentaire — elle mesurait la prose, pas le code, et aurait signalé un défaut
        // inexistant. Un instrument qui ne sait pas ce qu'il regarde ne prouve rien.
        $corps = preg_replace('#/\*.*?\*/#s', '', $corps);
        $corps = preg_replace('#//[^\n]*#', '', $corps);

        foreach (['ZReport', '->save(', '->insert(', '->update(', 'FiscalSequence'] as $ecriture) {
            $this->assertStringNotContainsString(
                $ecriture,
                $corps,
                "eodSynthesis() doit rester une lecture pure : « {$ecriture} » y est apparu. "
                .'Si la synthèse écrit désormais, le libellé du bouton doit être rouvert.'
            );
        }
    }
}
