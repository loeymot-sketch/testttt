<?php

namespace Tests\Feature\Reports;

use Tests\TestCase;

/**
 * [GOAL-OPS-SWAP W1 2026-08-12 — constat CONFIG-REPORT-FANTOME]
 *
 * Trois contrôleurs plafonnent les exports PDF via `config('report.pdf_max_rows', 2000)`
 * (`SalesReportController.php:82`, `OnlineOrderController.php:92`,
 * `ItemsReportController.php:69`), mais `config/report.php` n'existait pas.
 *
 * Conséquence mesurée : l'espace de noms `report` n'était jamais chargé, la
 * valeur retombait toujours sur le défaut codé en dur, et `REPORT_PDF_MAX_ROWS`
 * dans l'environnement n'avait AUCUN effet. Un plafond qui a l'air réglable et
 * ne l'est pas — le même motif que les réglages orphelins relevés au même moment.
 *
 * Cette sentinelle échoue si le fichier de configuration disparaît à nouveau.
 */
class ReportPdfMaxRowsIsConfigurableTest extends TestCase
{
    public function test_l_espace_de_noms_report_est_reellement_charge(): void
    {
        // Sans config/report.php, `config('report')` vaut null : c'était le défaut.
        $this->assertIsArray(
            config('report'),
            "config/report.php est absent : `config('report.pdf_max_rows')` retombe "
            ."silencieusement sur le défaut codé en dur et REPORT_PDF_MAX_ROWS n'a aucun effet."
        );
    }

    public function test_le_plafond_pdf_est_un_entier_exploitable(): void
    {
        $max = config('report.pdf_max_rows');

        $this->assertIsInt($max, 'Le plafond doit être un entier : il est comparé à un `count()`.');
        $this->assertGreaterThan(0, $max, 'Un plafond nul ou négatif refuserait TOUS les exports.');
    }

    public function test_le_plafond_conserve_la_valeur_historique_par_defaut(): void
    {
        // Le correctif rend la valeur réglable ; il ne doit RIEN changer au
        // comportement tant que l'environnement ne la surcharge pas.
        $this->assertSame(
            2000,
            config('report.pdf_max_rows'),
            'Le défaut doit rester 2000 — la valeur codée en dur historique.'
        );
    }
}
