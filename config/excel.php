<?php

/*
 * [ULTRA-AUDIT V3 2026-07-02 — P2 CSV/formula injection] Config partielle : on ne surcharge QUE le
 * value_binder global des exports Maatwebsite/Excel pour neutraliser l'injection de formule
 * (une valeur `=cmd|...` contrôlée par un utilisateur — ex. name d'un signup public → CustomerExport —
 * s'exécuterait à l'ouverture du fichier). Le reste de la config Excel vient des défauts du package
 * (mergeConfigFrom fournit exports/imports/temporary_files/etc. ; array_merge → ce fichier ne
 * remplace QUE la clé `value_binder`).
 */

return [
    'value_binder' => [
        'default' => \App\Support\Excel\FormulaGuardValueBinder::class,
    ],
];
