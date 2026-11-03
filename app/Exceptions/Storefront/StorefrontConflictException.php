<?php

namespace App\Exceptions\Storefront;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * [APP HYBRIDE W2a · 2026-11-03] Ce que l'écran avait sous les yeux n'est plus à jour :
 *   · la version PUBLIÉE a changé (double clic, deuxième publication) ;
 *   · [RED-W2a P2-2] le BROUILLON a été modifié par un autre administrateur depuis l'affichage (`$brouillon`).
 */
class StorefrontConflictException extends RuntimeException
{
    public function __construct(public readonly int $currentVersion, public readonly bool $brouillon = false)
    {
        parent::__construct($brouillon
            ? "Le brouillon a été modifié depuis votre dernier affichage (par un autre administrateur ?). Rechargez la vitrine pour voir ces changements avant de publier."
            : "Une autre publication vient d'avoir lieu (version {$currentVersion}). Rechargez la vitrine avant de publier.");
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'status' => false,
            'message' => $this->getMessage(),
            'current_version' => $this->currentVersion,
            'reason' => $this->brouillon ? 'draft_changed' : 'version_changed',
        ], 409);
    }
}
