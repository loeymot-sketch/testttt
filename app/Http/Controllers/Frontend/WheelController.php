<?php

namespace App\Http\Controllers\Frontend;

use App\Http\Controllers\Controller;
use App\Services\Wheel\WheelException;
use App\Services\Wheel\WheelService;
use App\Services\Wheel\WheelUnlockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Roue Le Cayenne — surface publique.
 *
 * PORTE FERMÉE PAR DÉFAUT. Tant que `wheel.enabled` est faux, seul un compte du rôle de
 * prévisualisation (le propriétaire) obtient une réponse : tous les autres reçoivent un 404, pas
 * un 403. Un 403 dit « ça existe mais tu n'y as pas droit » et donne envie de chercher ; un 404
 * ne dit rien. Le propriétaire peut donc tester en production, sur le vrai matériel, sans que
 * personne d'autre ne puisse jouer.
 *
 * AUCUNE VALEUR DE LOT NE TRANSITE DANS UNE REQUÊTE ENTRANTE. Le client envoie son numéro, son
 * prénom, et éventuellement le jeton de validation émis par l'équipe. Rien d'autre. Ce qu'il gagne
 * est décidé par le serveur, écrit en base, puis renvoyé.
 */
class WheelController extends Controller
{
    public function __construct(
        private readonly WheelService $wheel,
        private readonly WheelUnlockService $unlock,
    ) {}

    /** De quoi DESSINER la roue et savoir si l'on peut jouer. Jamais les poids. */
    public function config(Request $request): JsonResponse
    {
        if (! $this->accessible($request)) {
            return $this->porteFermee();
        }

        $branchId = $this->branchId($request);
        $tel = (string) $request->query('phone', '');

        $deja = $tel !== '' ? $this->wheel->alreadySpun($branchId, $tel) : null;

        return response()->json([
            'segments'       => $this->wheel->publicSegments(),
            'requires_order' => $this->wheel->requiresOrder(),
            'preview'        => ! $this->wheel->isOpenToPublic(),
            'already_spun'   => $deja !== null,
            'previous_prize' => $deja?->prize_label,
        ]);
    }

    /**
     * LE TOUR. La réponse contient l'index du segment gagnant pour que l'animation s'arrête au bon
     * endroit — c'est bien le serveur qui décide où la roue s'arrête, l'animation ne fait que
     * l'exécuter.
     */
    public function spin(Request $request): JsonResponse
    {
        if (! $this->accessible($request)) {
            return $this->porteFermee();
        }

        $data = $request->validate([
            'phone'        => ['required', 'string', 'max:32'],
            'name'         => ['nullable', 'string', 'max:120'],
            'unlock_token' => ['nullable', 'string', 'max:512'],
        ]);

        $branchId = $this->branchId($request);

        try {
            $deverrouillage = $this->resoudreDeverrouillage($data['unlock_token'] ?? null, $branchId);

            $spin = $this->wheel->spin(
                $branchId,
                $data['phone'],
                $data['name'] ?? null,
                $deverrouillage,
                (string) $request->header('X-Device-Id', ''),
                // L'IP est HACHÉE : elle sert à repérer un abus, pas à identifier quelqu'un.
                hash('sha256', (string) $request->ip() . (string) config('app.key'))
            );
        } catch (WheelException $e) {
            return response()->json(['status' => false, 'message' => $e->getMessage()], $e->status());
        }

        $segments = $this->wheel->publicSegments();
        $index = 0;
        foreach ($segments as $i => $s) {
            if ($s['key'] === $spin->prize_key) {
                $index = $i;
                break;
            }
        }

        return response()->json([
            'status'         => true,
            'segment_index'  => $index,
            'prize_label'    => $spin->prize_label,
            'prize_type'     => $spin->prize_type,
            'code'           => $spin->coupon_id ? optional($spin->coupon)->code : null,
            'points'         => $spin->points_awarded,
            'requires_order' => $this->wheel->requiresOrder(),
        ]);
    }

    /**
     * @return array{method: string, user_id?: int|null, token_hash?: string|null}
     *
     * @throws WheelException
     */
    private function resoudreDeverrouillage(?string $token, int $branchId): array
    {
        if ($token !== null && $token !== '') {
            $v = $this->unlock->verify($token);
            if ((int) $v['branch_id'] !== $branchId) {
                throw new WheelException('Cette validation ne vient pas de ce comptoir.', 403);
            }

            return ['method' => 'staff', 'user_id' => $v['user_id'], 'token_hash' => $v['token_hash']];
        }

        // Pas de jeton = pas de tour. On ne retombe JAMAIS sur un mode déclaratif : c'est
        // exactement la porte que le propriétaire refuse d'ouvrir.
        throw new WheelException(
            'Montre ton avis et ton abonnement à l\'équipe : elle débloque ton tour en deux secondes.',
            403
        );
    }

    /** Ouverte au public, ou réservée au propriétaire pendant la mise au point. */
    private function accessible(Request $request): bool
    {
        if ($this->wheel->isOpenToPublic()) {
            return true;
        }

        $user = $request->user();
        $role = (string) config('wheel.preview_role', 'Admin');

        return $user !== null && method_exists($user, 'hasRole') && $user->hasRole($role);
    }

    /** 404 et pas 403 : un 403 dit « ça existe » et donne envie de chercher. */
    private function porteFermee(): JsonResponse
    {
        return response()->json(['status' => false, 'message' => 'Not found.'], 404);
    }

    private function branchId(Request $request): int
    {
        $b = (int) $request->input('branch_id', (int) $request->query('branch_id', 0));

        return $b > 0 ? $b : 1;
    }
}
