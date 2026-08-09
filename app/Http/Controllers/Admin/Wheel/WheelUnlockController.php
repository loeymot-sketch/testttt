<?php

namespace App\Http\Controllers\Admin\Wheel;

use App\Http\Controllers\Controller;
use App\Services\Wheel\WheelException;
use App\Services\Wheel\WheelUnlockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * La validation au comptoir — le geste qui remplace une API qui n'existe pas.
 *
 * L'équipe regarde l'avis Google et l'abonnement sur le téléphone du client, puis appuie ici. Un
 * jeton signé, court et à usage unique est émis ; le client le consomme immédiatement en tournant
 * la roue, devant l'équipe.
 *
 * Réservé aux comptes qui tiennent la caisse (`pos`) : c'est un droit de DONNER un lot, il n'a
 * rien à faire dans les mains d'un compte qui n'est pas au comptoir.
 */
class WheelUnlockController extends Controller
{
    public function __construct(private readonly WheelUnlockService $unlock) {}

    public function issue(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user || ! $user->can('pos')) {
            return response()->json(['status' => false, 'message' => 'Accès refusé.'], 403);
        }

        // La branche vient du COMPTE, jamais de la requête : un opérateur ne débloque un tour que
        // pour SON comptoir. Accepter un `branch_id` entrant laisserait valider chez le voisin.
        $branchId = (int) ($user->branch_id ?: 1);

        try {
            $jeton = $this->unlock->issue($branchId, (int) $user->id);
        } catch (WheelException $e) {
            return response()->json(['status' => false, 'message' => $e->getMessage()], $e->status());
        }

        return response()->json([
            'status'     => true,
            'token'      => $jeton['token'],
            'expires_at' => $jeton['expires_at'],
            // De quoi afficher le QR à scanner par le client, jeton compris : il n'a rien à taper.
            'spin_url'   => rtrim((string) config('wheel.public_url', ''), '/') !== ''
                ? rtrim((string) config('wheel.public_url'), '/') . '/roue?t=' . urlencode($jeton['token'])
                : null,
        ]);
    }
}
