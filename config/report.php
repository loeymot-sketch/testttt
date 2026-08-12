<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Rapports — bornes d'export
    |--------------------------------------------------------------------------
    |
    | [GOAL-OPS-SWAP W1 2026-08-12] Ce fichier MANQUAIT.
    |
    | Trois contrôleurs lisent `config('report.pdf_max_rows', 2000)` :
    |   · app/Http/Controllers/Admin/SalesReportController.php:82
    |   · app/Http/Controllers/Admin/OnlineOrderController.php:92
    |   · app/Http/Controllers/Admin/ItemsReportController.php:69
    |
    | Sans `config/report.php`, Laravel ne charge aucun espace de noms `report`
    | et la valeur retombait TOUJOURS sur le défaut codé en dur — la variable
    | d'environnement n'était jamais consultée, puisque `env()` n'est lu que
    | depuis un fichier de configuration. Le plafond avait donc l'air réglable
    | et ne l'était pas : exactement le motif des « réglages orphelins »
    | relevés au même moment (voir reports/goal-ops-swap-2026-08-12/w1/).
    |
    | Le garde-fou lui-même reste JUSTE : au-delà de ce plafond, dompdf part en
    | dépassement mémoire. On refuse proprement en 422 avec un message qui dit
    | quoi faire, plutôt que de rendre une 500.
    |
    */

    /**
     * Nombre maximum de lignes acceptées dans un export PDF.
     * Au-delà, l'export est refusé en 422 avec une invitation à filtrer par
     * période. Défaut identique à l'ancien défaut codé en dur : aucun
     * changement de comportement, seulement la capacité de le régler.
     */
    'pdf_max_rows' => (int) env('REPORT_PDF_MAX_ROWS', 2000),

];
