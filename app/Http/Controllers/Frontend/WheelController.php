<?php

namespace App\Http\Controllers\Frontend;

use App\Http\Controllers\Controller;
use App\Services\Wheel\WheelException;
use App\Services\Wheel\WheelService;
use App\Services\Wheel\WheelStepService;
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
        private readonly WheelStepService $steps,
    ) {}

    /** De quoi DESSINER la roue et savoir si l'on peut jouer. Jamais les poids. */
    public function config(Request $request): JsonResponse
    {
        if (! $this->accessible($request)) {
            return $this->porteFermee();
        }

        $branchId = $this->branchId($request);

        // [P1 ORACLE 2026-08-09] Cet endpoint renvoyait `already_spun` et `previous_prize` pour
        // N'IMPORTE QUEL numéro, sans jeton ni authentification. C'était un annuaire interrogeable :
        // « ce numéro a-t-il joué, et qu'a-t-il gagné ». Deux usages malveillants immédiats :
        // énumérer des numéros pour trouver ceux qui n'ont pas joué (afin d'y brûler un jeton volé),
        // et lire les lots des autres.
        // La consultation exige désormais le JETON du client : il ne peut donc interroger que dans
        // le cadre de sa propre validation, et c'est précisément ce qu'il faut pour lui permettre de
        // RETROUVER son lot après une coupure réseau.
        $tel = (string) $request->query('phone', '');
        $jeton = (string) $request->query('t', '');
        $deja = null;
        if ($tel !== '' && $jeton !== '') {
            try {
                $v = $this->unlock->verify($jeton);
                if ((int) $v['branch_id'] === $branchId) {
                    $deja = $this->wheel->alreadySpun($branchId, $tel);
                }
            } catch (WheelException $e) {
                // Jeton invalide ou expiré : on ne dit RIEN sur ce numéro. Un refus silencieux vaut
                // mieux qu'un oracle poli.
                $deja = null;
            }
        }

        return response()->json([
            'segments'       => $this->wheel->publicSegments(),
            'requires_order' => $this->wheel->requiresOrder(),
            // Les ÉTAPES, dans l'ordre, avec leurs liens et leur temps d'attente. La page les
            // révèle UNE PAR UNE — on n'annonce jamais « 3 étapes » : un client debout au comptoir
            // qui lit ça repose son téléphone.
            // On ne publie NI les poids des lots, NI le contrôle d'abonnés : le client n'a pas à
            // savoir qu'on compare les totaux avant et après.
            'steps' => $this->steps->publicSteps(),
            // Annoncé AVANT, jamais découvert après : une condition qu'on apprend au moment de
            // retirer son lot est vécue comme un piège.
            'min_order' => (float) config('wheel.min_order_amount', 0),
            'preview'        => ! $this->wheel->isOpenToPublic(),
            'already_spun'   => $deja !== null,
            'previous_prize' => $deja?->prize_label,
            // De quoi RÉAFFICHER le lot après une coupure réseau ou un rechargement en plein tour.
            // Sans ça, le client à qui l'on a dit « ton tour n'a pas été utilisé » puis « tu as déjà
            // tourné » ne revoit jamais son lot : deux messages contradictoires et une impasse.
            'previous_code'  => $deja && $deja->coupon_id ? optional($deja->coupon)->code : null,
            'previous_points' => $deja?->points_awarded,
        ]);
    }

    /**
     * LE CLIENT VIENT D'OUVRIR UN LIEN — c'est le SERVEUR qui pose l'heure.
     *
     * Le compteur du navigateur ne prouve rien : il se contourne dans les outils de développement.
     * Cet appel est la seule version honnête de la garde « il a pris le temps d'écrire ».
     */
    public function step(Request $request): JsonResponse
    {
        if (! $this->accessible($request)) {
            return $this->porteFermee();
        }

        $data = $request->validate([
            'step' => ['required', 'string', 'in:review,follow'],
            'unlock_token' => ['required', 'string', 'max:512'],
        ]);

        $branchId = $this->branchId($request);

        try {
            $v = $this->unlock->verify($data['unlock_token']);
            if ((int) $v['branch_id'] !== $branchId) {
                throw new WheelException('Cette validation ne vient pas de ce comptoir.', 403);
            }
        } catch (WheelException $e) {
            return response()->json(['status' => false, 'message' => $e->getMessage()], $e->status());
        }

        $r = $this->steps->open($v['token_hash'], $branchId, $data['step']);

        return response()->json(['status' => true, 'wait_seconds' => $r['wait_seconds']]);
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
            // L'ADRESSE est requise : c'est la seconde clé d'identité ET le canal par lequel les
            // conditions du lot sont envoyées. Un lot dont les conditions ne sont pas écrites est
            // un lot qu'on découvre au moment de le retirer — et le client se sent piégé.
            'email'        => ['required', 'email', 'max:190'],
            'name'         => ['nullable', 'string', 'max:120'],
            'unlock_token' => ['nullable', 'string', 'max:512'],
        ]);

        $branchId = $this->branchId($request);

        try {
            $deverrouillage = $this->resoudreDeverrouillage($data['unlock_token'] ?? null, $branchId);

            // LES ÉTAPES, vérifiées côté serveur. Si l'une manque ou a été franchie trop vite, on
            // refuse AVANT de tirer : un tour accordé puis annulé serait pire que refusé.
            $etapes = $this->steps->assertDone((string) ($deverrouillage['token_hash'] ?? ''));

            $spin = $this->wheel->spin(
                $branchId,
                $data['phone'],
                $data['name'] ?? null,
                $deverrouillage,
                (string) $request->header('X-Device-Id', ''),
                // L'IP est HACHÉE : elle sert à repérer un abus, pas à identifier quelqu'un.
                hash('sha256', (string) $request->ip() . (string) config('app.key')),
                $data['email']
            );

            // Traces des étapes et contrôle d'abonnés, recopiés sur le tour. Le relevé « après »
            // est pris maintenant : l'abonnement vient d'avoir lieu.
            $spin->forceFill([
                'review_clicked_at' => $etapes['review_opened_at'],
                'follow_clicked_at' => $etapes['follow_opened_at'],
                'steps_seconds' => $etapes['steps_seconds'],
                'followers_before' => $etapes['followers_before'],
                'followers_after' => $this->steps->followersNow(),
            ])->save();
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
            // Le minimum d'achat est RAPPELÉ sur l'écran de gain : le client doit le lire au
            // moment où il apprend son lot, pas le découvrir en caisse.
            'min_order' => (float) config('wheel.min_order_amount', 0),
            // L'ÉCHÉANCE. La page ne la disait nulle part : un lot sans date se remet à plus tard,
            // et plus tard ne revient jamais. Pour un produit offert (sans coupon) on calcule la
            // même validité que celle des codes — la promesse doit être identique quel que soit le
            // lot, sinon on crée deux règles que personne ne retiendra.
            'valid_until' => $spin->coupon_id
                ? optional(optional($spin->coupon)->end_date)->format('d/m/Y')
                : $spin->created_at?->copy()
                    ->addDays((int) config('wheel.prize_validity_days', 30))->format('d/m/Y'),
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

        // Clé de prévisualisation : le seul chemin praticable depuis la page, qui vit sur un autre
        // domaine que l'API et n'emporte donc aucun cookie de session. Comparaison en temps
        // constant — un `===` fuit, par son temps d'exécution, le nombre d'octets corrects.
        $attendue = (string) config('wheel.preview_key', '');
        $fournie = (string) $request->input('preview', (string) $request->query('preview', ''));
        if ($attendue !== '' && $fournie !== '' && hash_equals($attendue, $fournie)) {
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
