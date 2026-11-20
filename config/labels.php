<?php

/**
 * [ETIQUETTES 2026-10-16] Imprimante d'étiquettes Brother QL-820NWB.
 */
return [
    // Restaurant unique (V1 LOCAL « Le Cayenne », branch_id=1) : branche portant les lignes créées par un
    // compte administrateur (branch_id=0) qui n'appartient à aucune branche.
    'branch_id' => (int) env('LABELS_BRANCH_ID', 1),
];
