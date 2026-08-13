<?php

namespace App\Http\Controllers\Admin\Wheel;

use App\Http\Controllers\Controller;
use App\Services\Wheel\WheelDeliveryService;
use App\Services\Wheel\WheelService;
use App\Services\Wheel\WheelSettingsService;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Request;

/**
 * L'écran comptoir de REMISE. Le maillon dont trois audits ont montré l'absence : la roue tirait,
 * mais aucune surface ne disait à l'équipe qu'un client avait un lot à recevoir.
 *
 * Réservé aux comptes caisse (`pos`), et la branche vient du COMPTE : remettre un lot, c'est sortir
 * quelque chose du stock ou créditer des points — ça n'a rien à faire dans les mains d'un compte
 * qui n'est pas au comptoir, ni pour le comptoir d'un autre.
 */
class WheelPrizeController extends Controller
{
    public function __construct(
        private readonly WheelDeliveryService $delivery,
        private readonly WheelSettingsService $reglages,
    ) {}

    public function show(Request $request)
    {
        $branchId = $this->branchId($request);
        $phone = trim((string) $request->query('phone', ''));
        $code = trim((string) $request->query('code', ''));

        /*
         * ── LA RECHERCHE PAR CODE ────────────────────────────────────────────────────────────
         * [2026-08-13 · propriétaire : « valider le code promo au cas où, ou bien dans la caisse »]
         * Ce que le client MONTRE au comptoir, c'est son code, pas son numéro. Cet écran ne savait
         * partir que du numéro : le seul objet remis au client était le seul inutilisable ici.
         *
         * Le code passe EN PREMIER quand il est saisi : s'il a été tapé, c'est qu'il est sous les
         * yeux de l'équipe, et il désigne UN tour précis là où un numéro peut en avoir plusieurs.
         * Le numéro reste le chemin de secours — pour le client qui a perdu son message.
         *
         * On repart ensuite du téléphone DU TOUR TROUVÉ, pas de la saisie : c'est ce qui donne à
         * l'écran son historique complet et permet à la remise (qui travaille par numéro) de
         * fonctionner sans qu'on lui apprenne un second langage.
         */
        if ($code !== '') {
            $spin = \App\Models\WheelSpin::parCode($branchId, $code);

            if ($spin === null) {
                return view('admin.wheel.lot', $this->commun() + [
                    'phone' => $phone,
                    'code' => $code,
                    'spin' => null,
                    'history' => [],
                    'message' => 'Aucun lot ne correspond à ce code dans cette caisse. Vérifie la '
                        . 'saisie, ou cherche par le numéro de téléphone du client.',
                    'messageType' => 'info',
                ]);
            }

            $phone = (string) $spin->phone;
        }

        if ($phone === '') {
            return view('admin.wheel.lot', $this->commun() + [
                'phone' => '', 'code' => '', 'spin' => null, 'history' => [],
            ]);
        }

        /*
         * [P3 2026-08-10 — audit E2E vague C] « Je me suis trompé en tapant » n'est PAS « ce client
         * n'a jamais joué ». Sous 9 chiffres, le service ne cherche même pas — l'équipe lisait donc
         * « aucun tour à ce numéro » pour un numéro incomplet et concluait que le client mentait.
         */
        if (strlen(app(WheelService::class)->normalizePhone($phone)) < 9) {
            return view('admin.wheel.lot', $this->commun() + [
                'phone' => $phone,
                'code' => $code,
                'spin' => null,
                'history' => [],
                'message' => 'Numéro incomplet : il faut les 10 chiffres. Retape-le en entier.',
                'messageType' => 'info',
            ]);
        }

        $spin = $this->delivery->pending($branchId, $phone);
        $history = $this->historiqueAvecCodes($this->delivery->history($branchId, $phone));

        $message = null;
        $type = 'info';
        if (! $spin) {
            $message = $this->pourquoiRienARemettre($history);
        }

        return view('admin.wheel.lot', $this->commun() + [
            'phone' => $phone,
            'code' => $code,
            'spin' => $spin,
            'history' => $history,
            'message' => $message,
            'messageType' => $type,
        ]);
    }

    /**
     * L'HISTORIQUE — la lecture qui manquait au dispositif.
     *
     * [2026-08-13 · propriétaire : « toutes les fonctionnalités d'historique, de la gestion, de la
     * validation, de l'utilisation — par exemple quel code promo a été validé »]
     *
     * L'accueil de la roue donne des AGRÉGATS : combien de tours, quelle valeur offerte. Utile pour
     * régler des plafonds, inutile pour répondre à la seule question qu'on se pose devant un
     * client : « ce code-là, il a été validé, oui ou non ? ». Un chiffre ne s'explique pas ; une
     * ligne, si.
     *
     * La période est bornée à des valeurs CHOISIES, pas librement saisie : un `?jours=100000` sur
     * un écran de comptoir sortirait la totalité de la table dans une page HTML.
     */
    public function history(Request $request)
    {
        $branchId = $this->branchId($request);

        $permis = [1, 7, 30, 90];
        $jours = (int) $request->query('jours', 7);
        if (! in_array($jours, $permis, true)) {
            $jours = 7;
        }

        return view('admin.wheel.historique', $this->commun() + [
            'jours' => $jours,
            'periodes' => $permis,
            'lignes' => app(\App\Services\Wheel\WheelReportService::class)
                ->historique($branchId, $jours),
        ]);
    }

    public function deliver(Request $request)
    {
        $branchId = $this->branchId($request);
        $data = $request->validate([
            'spin_id' => ['required', 'integer'],
            'phone'   => ['nullable', 'string', 'max:32'],
        ]);

        // La caisse vient du contexte posé par la porte, jamais du corps : `spin_id` est un champ
        // caché, donc une valeur qu'on ne peut pas croire seule.
        $phone = trim((string) ($data['phone'] ?? ''));

        $r = $this->delivery->deliver(
            (int) $data['spin_id'],
            $this->actorId($request),
            (int) $request->attributes->get('wheel_branch_id', 1),
            // Le numéro AFFICHÉ : sans lui, `spin_id` seul permet de consommer le lot d'un autre.
            $phone
        );

        return view('admin.wheel.lot', $this->commun() + [
            'phone' => $phone,
            // Après une remise on ne réaffiche PAS de bouton : le geste est fait, et un second
            // bouton juste après un succès est la meilleure façon de provoquer un double clic.
            'spin' => $r['ok'] ? null : $this->delivery->pending($branchId, $phone),
            'history' => $phone !== '' ? $this->historiqueAvecCodes($this->delivery->history($branchId, $phone)) : [],
            'message' => $r['message'],
            'messageType' => $r['ok'] ? 'ok' : 'err',
        ]);
    }

    /**
     * POURQUOI il n'y a rien à tendre — et c'est la question de l'équipe, devant le client.
     *
     * [P1 2026-08-10 — audit E2E vague C] Un seul message couvrait deux situations opposées : « ses
     * lots sont déjà remis, ou ce sont des codes à utiliser sur le site ». L'équipe lisait cette
     * phrase à deux branches et ne pouvait pas trancher devant un client qui, lui, sait très bien
     * qu'il n'a rien reçu. On tranche donc ici, où l'information existe.
     */
    private function pourquoiRienARemettre($history): ?string
    {
        if (empty($history) || count($history) === 0) {
            return 'Aucun tour à ce numéro. Vérifie le numéro, ou fais-le jouer d\'abord.';
        }

        $remise = null;
        foreach ($history as $h) {
            if ($h->delivered_at === null
                && in_array((string) $h->prize_type, ['coupon_percent', 'coupon_fixed'], true)) {
                $remise = $h;
                break;
            }
        }

        if ($remise) {
            $code = trim((string) ($remise->coupon->code ?? ''));

            // [P1 2026-08-10] LA GARDE DU TIRAGE N'AVAIT PAS DE JUMELLE AU COMPTOIR. Quand les codes
            // de remise sont éteints dans la caisse, l'écran ORDONNAIT quand même à l'équipe d'envoyer
            // le client saisir un code que la prise de commande refuse. Une consigne vouée à l'échec
            // fait perdre la face à l'équipe devant le client — autant dire la vérité.
            if (! app(\App\Services\Wheel\WheelService::class)->remisesAcceptees()) {
                return 'Rien à lui tendre : son lot est une remise (' . $remise->prize_label . '), et '
                    . 'les codes promo sont DÉSACTIVÉS dans la caisse en ce moment — il serait refusé '
                    . 'au panier. Préviens le gérant avant de promettre quoi que ce soit.';
            }

            return 'Rien à lui tendre : son lot est une remise (' . $remise->prize_label . '), '
                . ($code !== ''
                    ? 'à saisir sur le site avec le code ' . $code . '.'
                    : 'à saisir sur le site avec son code.')
                . ' Le détail est en bas, dans ses derniers tours.';
        }

        return 'Ses lots sont déjà remis — rien à lui donner cette fois. Les dates sont en bas, dans '
            . 'ses derniers tours.';
    }

    /**
     * Les codes des remises, chargés en UNE requête. Sans ça l'historique en réclamait un par ligne
     * pendant un service, et le code du coupon — la seule chose que le client vient chercher quand il
     * a perdu son e-mail — n'était affiché nulle part.
     */
    private function historiqueAvecCodes($history)
    {
        if ($history instanceof EloquentCollection) {
            $history->loadMissing('coupon');
        }

        return $history;
    }

    /** Ce que l'écran doit dire à chaque affichage, quelle que soit la branche du code. */
    private function commun(): array
    {
        return [
            'minOrder' => $this->reglages->minOrder(),
            'exigeCommande' => (bool) config('wheel.requires_order_to_claim', true),
        ];
    }

    /*
     * ── LA CAISSE ET L'AUTEUR VIENNENT DE LA PORTE, PAS DE L'ÉCRAN ───────────────────────────
     * L'intergiciel `wheel.access` ouvre les écrans de deux façons : session web habilitée, ou code
     * de la maison. Sur le chemin du code il n'y a PAS d'utilisateur — relire `$request->user()` ici
     * refermait l'écran juste après que la porte l'avait ouvert. Le contexte est donc posé une seule
     * fois, par la porte. Jamais lu dans le corps de la requête : ce serait remettre un lot au nom
     * d'un autre comptoir.
     */
    private function branchId(Request $request): int
    {
        $porte = (int) $request->attributes->get('wheel_branch_id', 0);
        if ($porte > 0) {
            return $porte;
        }

        $u = $request->user();

        return (int) (($u && $u->branch_id) ? $u->branch_id : 1);
    }

    /** Qui a fait le geste — nul quand l'écran a été ouvert par le code : on n'invente pas un auteur. */
    private function actorId(Request $request): ?int
    {
        $porte = (int) $request->attributes->get('wheel_actor_id', 0);
        if ($porte > 0) {
            return $porte;
        }

        $id = (int) ($request->user()?->id ?? 0);

        return $id > 0 ? $id : null;
    }
}
