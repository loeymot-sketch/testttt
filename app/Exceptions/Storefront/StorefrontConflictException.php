<?php

namespace App\Exceptions\Storefront;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/** [APP HYBRIDE W2a · 2026-11-03] La version vue à l'écran n'est plus la version publiée (double clic, deuxième administrateur). */
class StorefrontConflictException extends RuntimeException
{
    public function __construct(public readonly int $currentVersion)
    {
        parent::__construct("Une autre publication vient d'avoir lieu (version {$currentVersion}). Rechargez la vitrine avant de publier.");
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'status' => false,
            'message' => $this->getMessage(),
            'current_version' => $this->currentVersion,
        ], 409);
    }
}
