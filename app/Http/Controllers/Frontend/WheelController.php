<?php

namespace App\Http\Controllers\Frontend;

use App\Http\Controllers\Controller;
use App\Mail\WheelPrizeMail;
use App\Services\Wheel\WheelAccountService;
use App\Services\Wheel\WheelException;
use App\Services\Wheel\WheelService;
use App\Services\Wheel\WheelStepService;
use App\Services\Wheel\WheelUnlockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

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
        private readonly WheelAccountService $comptes,
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
                // Expiration tolérée, comme à la réclamation. C'est ICI qu'un client retrouve son
                // code après une coupure réseau — et il le cherche justement quelques minutes plus
                // tard, donc souvent après l'expiration du jeton. Le jeton reste EXIGÉ : ce qu'on
                // relâche, c'est sa fraîcheur, pas la preuve de possession. Le renseignement qu'un
                // jeton déjà dépensé peut encore livrer (« ce numéro a-t-il joué ») ne vaut pas de
                // laisser un client sans son lot.
                $v = $this->unlock->verify($jeton, true);
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
     * LE TOUR — et il ne demande RIEN au client.
     *
     * [2026-08-10 · ARBITRAGE PROPRIÉTAIRE] Avant, il fallait donner son numéro et son adresse AVANT
     * de tourner. C'était l'ordre le plus coûteux possible : deux champs contre une promesse. Le
     * client tourne désormais d'abord, voit son lot, et ne donne ses coordonnées que pour le
     * DÉBLOQUER. Le même effort, mais contre quelque chose de déjà gagné.
     *
     * La réponse contient l'index du segment pour que l'animation s'arrête au bon endroit — c'est
     * bien le serveur qui décide où la roue s'arrête, l'animation ne fait que l'exécuter — et le nom
     * du lot, pour l'annoncer. Elle ne contient JAMAIS le code : il n'existe pas encore.
     */
    public function spin(Request $request): JsonResponse
    {
        if (! $this->accessible($request)) {
            return $this->porteFermee();
        }

        $data = $request->validate([
            'unlock_token' => ['nullable', 'string', 'max:512'],
        ]);

        $branchId = $this->branchId($request);

        try {
            $deverrouillage = $this->resoudreDeverrouillage($data['unlock_token'] ?? null, $branchId);
            $empreinte = (string) ($deverrouillage['token_hash'] ?? '');

            // LES ÉTAPES, vérifiées côté serveur. Si l'une manque ou a été franchie trop vite, on
            // refuse AVANT de tirer : un tour accordé puis annulé serait pire que refusé.
            $this->steps->assertDone($empreinte);

            $progress = $this->steps->progress($empreinte, $branchId);
            $segment = $this->wheel->drawPending($branchId, $progress);
        } catch (WheelException $e) {
            return response()->json(['status' => false, 'message' => $e->getMessage()], $e->status());
        }

        return response()->json([
            'status' => true,
            'segment_index' => $this->indexDuLot((string) $segment['key']),
            'prize_label' => $segment['label'],
            'prize_type' => $segment['type'],
            // Combien de temps il lui reste pour réclamer. Affiché : une échéance qu'on découvre
            // après coup est vécue comme un piège.
            'claim_minutes' => max(5, (int) config('wheel.claim_window_minutes', 30)),
        ]);
    }

    /**
     * LA RÉCLAMATION — le client donne son numéro et son adresse, et le code apparaît.
     *
     * C'est le seul endroit où une participation naît, où l'unicité est tranchée en base, et où le
     * COMPTE du client est créé. Le propriétaire l'a demandé ainsi : « on va lui créer un compte en
     * même temps, ça va être inscrit avec son numéro et e-mail pour recevoir le code ». Un seul
     * formulaire fait donc deux choses, et le client n'en remplit qu'un.
     */
    public function claim(Request $request): JsonResponse
    {
        if (! $this->accessible($request)) {
            return $this->porteFermee();
        }

        $data = $request->validate([
            'phone' => ['required', 'string', 'max:32'],
            // L'ADRESSE est requise : c'est la seconde clé d'identité, le canal par lequel le code
            // et ses conditions sont envoyés, ET l'identifiant du compte qu'on crée à l'instant.
            'email' => ['required', 'email', 'max:190'],
            'name' => ['nullable', 'string', 'max:120'],
            'unlock_token' => ['nullable', 'string', 'max:512'],
        ]);

        $branchId = $this->branchId($request);

        try {
            // Expiration du JETON tolérée ici, et seulement ici : le lot est déjà tiré, et c'est la
            // fenêtre de réclamation qui borne le délai (voir WheelUnlockService::verify).
            $deverrouillage = $this->resoudreDeverrouillage($data['unlock_token'] ?? null, $branchId, true);
            $empreinte = (string) ($deverrouillage['token_hash'] ?? '');

            // On re-vérifie les étapes : la réclamation est une requête à part entière, et une garde
            // qui ne s'applique qu'au premier appel d'une paire ne garde rien.
            $etapes = $this->steps->assertDone($empreinte);

            $progress = $this->steps->progress($empreinte, $branchId);

            $spin = $this->wheel->claimPending(
                $branchId,
                $progress,
                $data['phone'],
                $data['email'],
                $data['name'] ?? null,
                $deverrouillage,
                (string) $request->header('X-Device-Id', ''),
                // L'IP est HACHÉE : elle sert à repérer un abus, pas à identifier quelqu'un.
                hash('sha256', (string) $request->ip() . (string) config('app.key'))
            );

            // Traces des étapes et contrôle d'abonnés, recopiés sur le tour. Le relevé « après » est
            // pris maintenant : l'abonnement vient d'avoir lieu.
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

        // LE COMPTE. Après la participation, jamais avant : si la création échouait, le lot resterait
        // dû quand même. Et aucune session n'est émise ici — un numéro de téléphone n'est pas une
        // preuve d'identité (cf. WheelAccountService).
        $compte = $this->comptes->ensure($data['phone'], $data['email'], $data['name'] ?? null);

        $code = $spin->coupon_id ? optional($spin->coupon)->code : null;
        $echeance = $spin->coupon_id
            ? optional(optional($spin->coupon)->end_date)->format('d/m/Y')
            : $spin->created_at?->copy()
                ->addDays((int) config('wheel.prize_validity_days', 30))->format('d/m/Y');

        $this->envoyerLeLot($spin, $code, $echeance, (bool) $compte['created']);

        return response()->json([
            'status' => true,
            'prize_label' => $spin->prize_label,
            'prize_type' => $spin->prize_type,
            'code' => $code,
            'points' => $spin->points_awarded,
            'requires_order' => $this->wheel->requiresOrder(),
            // Le minimum d'achat est RAPPELÉ sur l'écran de gain : le client doit le lire au moment
            // où il apprend son lot, pas le découvrir en caisse.
            'min_order' => (float) config('wheel.min_order_amount', 0),
            // L'ÉCHÉANCE. La page ne la disait nulle part : un lot sans date se remet à plus tard, et
            // plus tard ne revient jamais. Pour un produit offert (sans coupon) on calcule la même
            // validité que celle des codes — la promesse doit être identique quel que soit le lot,
            // sinon on crée deux règles que personne ne retiendra.
            'valid_until' => $echeance,
            // Le compte existe-t-il ? La page le DIT au client — « ton compte est créé, connecte-toi
            // avec ce numéro » — plutôt que de le laisser découvrir seul qu'il en a un.
            'account_created' => (bool) $compte['created'],
            'account_ready' => $compte['user_id'] !== null,
        ]);
    }

    /**
     * L'E-MAIL DU LOT. Il part MAINTENANT, pas dans une file : le client vient de donner son
     * adresse et regarde son écran.
     *
     * Son échec ne fait JAMAIS échouer la réclamation. Le lot est déjà acquis, le code est déjà à
     * l'écran ; refuser la réponse parce qu'un serveur de messagerie tousse ferait perdre au client
     * un lot qu'il a gagné. On journalise, et on continue.
     */
    private function envoyerLeLot(\App\Models\WheelSpin $spin, ?string $code, ?string $echeance, bool $compteCree): void
    {
        if (! (bool) config('wheel.notify_by_email', true) || blank($spin->email)) {
            return;
        }

        try {
            Mail::to((string) $spin->email)->send(new WheelPrizeMail(
                $spin, $code, (float) config('wheel.min_order_amount', 0), $echeance, $compteCree
            ));

            $spin->forceFill(['notified_at' => now()])->save();
        } catch (\Throwable $e) {
            Log::channel('daily')->warning('wheel.prize_mail_failed', [
                'spin_id' => $spin->id, 'error' => $e->getMessage(),
            ]);
        }
    }

    private function indexDuLot(string $prizeKey): int
    {
        foreach ($this->wheel->publicSegments() as $i => $s) {
            if ($s['key'] === $prizeKey) {
                return $i;
            }
        }

        return 0;
    }

    /**
     * @return array{method: string, user_id?: int|null, token_hash?: string|null}
     *
     * @throws WheelException
     */
    private function resoudreDeverrouillage(?string $token, int $branchId, bool $tolererExpiration = false): array
    {
        if ($token !== null && $token !== '') {
            $v = $this->unlock->verify($token, $tolererExpiration);
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
