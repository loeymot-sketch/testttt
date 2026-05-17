<?php

namespace App\Events;

use App\Events\Concerns\DispatchableAfterCommit;

/**
 * [Wave 5G R10 heal 2026-05-17] Émis lorsque le status d'une branche change
 * (ACTIVE <-> INACTIVE). Le listener
 * {@see \App\Listeners\RevokeTokensOnBranchDeactivated} révoque tous les
 * tokens Sanctum des utilisateurs scopés à cette branche quand le nouveau
 * status est INACTIVE.
 *
 * RED-team R10 evidence (2026-05-17):
 *   `find Branch*Listener` = 0 rows. Désactiver une branche n'invalide
 *   AUCUN token côté staff/POS — le device garde son accès jusqu'à
 *   l'expiration naturelle (480 minutes). Ce event ferme la boucle.
 *
 * Convention : on transmet $oldStatus + $newStatus pour que le listener
 * évite de re-firer sur un save sans transition (cf. advisor note 1).
 */
class BranchStatusChanged
{
    use DispatchableAfterCommit;

    public function __construct(
        public int $branchId,
        public int $oldStatus,
        public int $newStatus,
    ) {
    }
}
