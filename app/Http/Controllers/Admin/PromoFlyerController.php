<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PromoFlyer;
use App\Models\Scopes\BranchScope;
use App\Services\Promo\PromoFlyerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Smartisan\Settings\Facades\Settings;

/**
 * [FLYER PROMO UBER 2026-08-07] Ticket promotionnel nominatif.
 *
 * Deux publics, un seul contrôleur :
 *
 *   - l'EXPLOITANT, depuis son téléphone : il tape un prénom, ça crée le code
 *     et dépose l'ordre d'impression (`store`) ;
 *   - la CAISSE, depuis n'importe quel écran admin ouvert : elle réclame les
 *     ordres en attente (`pending`), récupère les octets (`escpos`) et confirme
 *     (`acknowledge`).
 *
 * Ce découpage vient d'une contrainte physique mesurée : le serveur est dans le
 * cloud et ne peut pas joindre l'imprimante du restaurant. C'est donc la caisse
 * qui vient chercher le travail, jamais le serveur qui l'y pousse.
 */
class PromoFlyerController extends Controller
{
    public function __construct(private readonly PromoFlyerService $service)
    {
        // `pos` = permission déjà portée par les écrans de caisse et par
        // l'exploitant. On ne crée pas de permission nouvelle : elle
        // n'existerait sur aucun rôle en base tant qu'un seeder ne l'aurait pas
        // distribuée, et l'écran serait donc inaccessible pour tout le monde.
        //
        // Ce socle suffit pour LIRE la file et CONFIRMER une impression : ce
        // sont des gestes de caisse, exécutés automatiquement par l'écran.
        $this->middleware('permission:pos|pos-orders');

        // [P1 2026-08-07 — audit adversarial] Deux actions sortent du geste de
        // caisse et méritent leur propre barrière.
        //
        // `store` CRÉE UN VRAI COUPON en base. Partout ailleurs, créer un
        // coupon exige `coupons_create` (CouponController:26). Laisser ce
        // pouvoir derrière `pos` permettait à n'importe quel caissier de
        // frapper autant de codes −10 % qu'il le souhaite, un POST à la fois.
        //
        // [OWNER 2026-08-13 « ameliore l'acces du caissier »] Le verrou ci-dessus a fermé
        // l'écran à TOUT LE MONDE sauf l'Admin — y compris le caissier pour qui il a été
        // construit (le bouton du tracker caisse existait, cliquer dessus rendait 403).
        // `pos-flyer-print` est une permission dédiée, distincte de `coupons_create` : elle
        // ne déverrouille RIEN d'autre (pas le CRUD coupons générique de CouponController).
        // Le risque de mint illimité que `coupons_create` bloquait est repris par un plafond
        // applicatif dans PromoFlyerService::create (voir DAILY_CAP_PER_USER).
        $this->middleware('permission:pos-flyer-print|coupons_create|settings')->only('store');

        // `updateSettings` écrit le POURCENTAGE de remise (jusqu'à 50 %) et
        // surtout l'ADRESSE encodée dans le QR imprimé sur des tickets remis
        // en main propre aux clients — soit une redirection de trafic, voire
        // un support d'hameçonnage distribué par le restaurant lui-même.
        // Tous les autres écrits de réglages du projet exigent `settings`.
        $this->middleware('permission:settings')->only('updateSettings');

        // Annuler un code et relancer une impression engagent de l'argent et du papier :
        // même barrière que la création — voir la note [OWNER 2026-08-13] ci-dessus.
        $this->middleware('permission:pos-flyer-print|coupons_create|settings')->only(['revoke', 'reprint']);
    }

    /**
     * Réglages du ticket (textes, pourcentage, validité) + état d'activation.
     */
    public function settings(): JsonResponse
    {
        $settings = $this->service->settings();

        return new JsonResponse([
            'settings' => $settings,
            // Un ticket promettant une remise inutilisable serait pire que pas
            // de ticket du tout : l'écran doit pouvoir le dire à l'exploitant.
            'coupon_redemption_enabled' => config('pos.coupon_codes_enabled') === true
                || config('pos.manual_discount_enabled') === true,
        ], 200);
    }

    /**
     * Enregistre les réglages.
     */
    public function updateSettings(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'headline'         => ['required', 'string', 'max:40'],
            'intro'            => ['required', 'string', 'max:400'],
            'savings_note'     => ['nullable', 'string', 'max:400'],
            'footer_note'      => ['nullable', 'string', 'max:200'],
            // Bornes volontaires : au-delà de 50 % la remise dépasserait la
            // marge sur la plupart des plats — ce n'est plus une acquisition,
            // c'est une perte.
            'discount_percent' => ['required', 'numeric', 'min:1', 'max:50'],
            'validity_days'    => ['required', 'integer', 'min:1', 'max:365'],
            'site_url'         => ['required', 'string', 'max:120'],
            'qr_url'           => ['required', 'string', 'max:200', 'url'],
            // Vide = salutation calculée à l'heure (« Bonjour » / « Bonsoir »).
            'greeting'         => ['nullable', 'string', 'max:30'],
            // Les points forts, un par ligne. Plafonné : au-delà, le ticket
            // devient un tract et personne ne le lit.
            'strengths'        => ['nullable', 'string', 'max:600'],
            // Chemin RELATIF à public/ : validé et re-vérifié côté service
            // (un chemin absolu ou remontant permettrait de faire imprimer
            // n'importe quel fichier de la machine).
            'logo_path'        => ['nullable', 'string', 'max:160', 'regex:/^[A-Za-z0-9._\/-]*$/'],
        ]);

        Settings::group(PromoFlyerService::SETTINGS_GROUP)->set($validated);

        return new JsonResponse([
            'message'  => trans('all.message.flyer_settings_saved'),
            'settings' => $this->service->settings(),
        ], 200);
    }

    /**
     * Crée un ticket : génère le code, crée le coupon, met en file d'impression.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // 60 = la taille du champ `orders.pos_customer_name` déjà utilisé
            // pour les noms clients ailleurs dans le projet : on reste cohérent.
            'customer_name' => ['required', 'string', 'min:1', 'max:60'],
            // Liste fermée : la civilité est imprimée telle quelle sur le
            // ticket, on n'y laisse pas passer du texte libre.
            'civility'      => ['nullable', 'string', Rule::in(['', 'Mme', 'M.'])],
            // Confirmation explicite de l'exploitant quand un doublon est signalé.
            'force'         => ['nullable', 'boolean'],
        ]);

        $user = $request->user();
        $branchId = (int) ($user->branch_id ?: 1);

        // [OWNER 2026-08-13 « ameliore l'acces du caissier »] Le plafond quotidien remplace le
        // verrou de rôle qui bloquait TOUT LE MONDE : un usage normal (un ticket par commande
        // plateforme) ne l'atteint jamais ; un abus délibéré devient lent et visible au lieu
        // d'être illimité. Admin non plafonné (compte de service / secours).
        if (! $user->hasRole('Admin') && $this->service->dailyCountForUser((int) $user->id) >= PromoFlyerService::DAILY_CAP_PER_USER) {
            Log::warning('[FLYER] plafond quotidien atteint', ['user' => (int) $user->id, 'branch' => $branchId]);

            return new JsonResponse([
                'message' => "Limite de tickets promo atteinte pour aujourd'hui. Demandez à un responsable.",
            ], 429);
        }

        // [DÉTAIL 2026-08-09] Deux appuis sur « Imprimer » — un doigt qui insiste, un écran qui
        // rame — et le client repartait avec DEUX codes : deux fois 10 % offerts, deux tickets,
        // et un client qui ne sait plus lequel utiliser. On ne BLOQUE pas (deux « Camille » dans
        // la même soirée, ça arrive) : on rend la main à l'exploitant avec le code déjà créé.
        if (! $request->boolean('force')) {
            $doublon = $this->service->recentDuplicate($validated['customer_name'], $branchId);

            if ($doublon) {
                return new JsonResponse([
                    'duplicate' => true,
                    'message'   => 'Un code vient deja d\'etre cree pour ce prenom.',
                    'flyer'     => $this->present($doublon),
                ], 200);
            }
        }

        try {
            $flyer = $this->service->create(
                $validated['customer_name'],
                $branchId,
                (int) $user->id,
                $this->service->deviceIdFrom($request),
                (string) ($validated['civility'] ?? ''),
            );
        } catch (\Throwable $e) {
            Log::error('[FLYER] création impossible', [
                'error' => $e->getMessage(),
                'user'  => (int) $user->id,
            ]);

            return new JsonResponse([
                'message' => "Impossible de créer le code promo. Réessayez.",
            ], 500);
        }

        return new JsonResponse([
            'message' => 'Ticket envoyé à la caisse.',
            'flyer'   => $this->present($flyer),
        ], 201);
    }

    /**
     * Historique + ce que les tickets ont RAPPORTÉ.
     *
     * [OWNER 2026-08-09] L'écran listait qui avait reçu un code, jamais si quelqu'un l'avait
     * utilisé. L'exploitant offrait 10 % et du papier sans le moindre retour : impossible de
     * décider s'il fallait continuer, augmenter la remise ou arrêter. C'est la seule mesure qui
     * transforme cette fonctionnalité en outil de gestion plutôt qu'en imprimante à coupons.
     */
    public function index(Request $request): JsonResponse
    {
        $flyers = PromoFlyer::query()
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        $couponIds = $flyers->pluck('coupon_id')->filter()->all();
        $usages = $this->service->redemptionsFor($couponIds);
        $actifs = $this->couponsActifs($couponIds);

        $lignes = $flyers->map(function (PromoFlyer $f) use ($usages, $actifs) {
            $usage = $usages[(int) $f->coupon_id] ?? null;
            $revoque = ! isset($actifs[(int) $f->coupon_id]);

            return $this->present($f, $revoque) + [
                'used'        => $usage !== null,
                'used_at'     => $usage['used_at'] ?? null,
                'order_total' => $usage['order_total'] ?? null,
                'saved'       => $usage['discount'] ?? null,
            ];
        })->values();

        $utilises = $lignes->where('used', true);
        $imprimes = $lignes->where('status', PromoFlyer::STATUS_PRINTED)->count();

        return new JsonResponse([
            'flyers' => $lignes,
            // Le taux se calcule sur les tickets RÉELLEMENT IMPRIMÉS : un ticket resté en file
            // n'a jamais atteint personne, le compter comme un échec commercial serait faux et
            // ferait renoncer à une idée qui marche.
            'stats'  => [
                'total'       => $lignes->count(),
                'printed'     => $imprimes,
                'used'        => $utilises->count(),
                'rate'        => $imprimes > 0 ? round($utilises->count() * 100 / $imprimes, 1) : null,
                'revenue'     => round((float) $utilises->sum('order_total'), 2),
                'given_away'  => round((float) $utilises->sum('saved'), 2),
            ],
        ], 200);
    }

    /**
     * Remet un ticket dans la file.
     *
     * [OWNER 2026-08-09] Une impression échouée (papier épuisé, caisse éteinte, plafond de
     * tentatives atteint) laissait le code créé mais JAMAIS remis au client : un cadeau promis
     * à personne, et un coupon qui traîne en base jusqu'à expiration. Il fallait pouvoir
     * relancer sans recréer un second code au même nom.
     */
    public function reprint(Request $request, int $flyer): JsonResponse
    {
        $model = $this->findForBranch($request, $flyer);

        if (! $model) {
            return new JsonResponse(['message' => trans('all.message.device_not_found')], 404);
        }

        $this->service->requeue($model);

        return new JsonResponse([
            'message' => 'Ticket remis en file d\'impression.',
            'flyer'   => $this->present($model->fresh()),
        ], 200);
    }

    /**
     * Annule un code.
     *
     * [OWNER 2026-08-09] Un prénom mal tapé, un ticket imprimé deux fois, un client qui repart
     * sans son sac : le code existe et reste utilisable par quiconque le lit. Il fallait pouvoir
     * le neutraliser. On DÉSACTIVE le coupon (statut) au lieu de le supprimer : la trace de ce
     * qui a été offert, à qui et quand, doit survivre — c'est elle qui rend les statistiques
     * ci-dessus honnêtes.
     */
    public function revoke(Request $request, int $flyer): JsonResponse
    {
        $model = $this->findForBranch($request, $flyer);

        if (! $model) {
            return new JsonResponse(['message' => trans('all.message.device_not_found')], 404);
        }

        $this->service->revoke($model);

        return new JsonResponse([
            'message' => 'Code annule.',
            'flyer'   => $this->present($model->fresh()),
        ], 200);
    }

    /**
     * La caisse réclame ses ordres d'impression.
     *
     * Réclamer INCRÉMENTE le compteur de tentatives et pose un verrou : deux
     * onglets ouverts sur le même PC ne peuvent pas sortir le même ticket deux
     * fois.
     */
    public function pending(Request $request): JsonResponse
    {
        $user = $request->user();
        $branchId = (int) ($user->branch_id ?: 1);

        $claimed = $this->service->claimPending(
            $branchId,
            $this->service->deviceIdFrom($request),
        );

        return new JsonResponse([
            'flyers' => array_map(fn (PromoFlyer $f) => $this->present($f), $claimed),
        ], 200);
    }

    /**
     * Octets ESC/POS d'un ticket, en base64 — même contrat que les tickets de
     * commande, que le pont local de la caisse sait déjà consommer.
     */
    public function escpos(Request $request, int $flyer): JsonResponse
    {
        $model = $this->findForBranch($request, $flyer);

        if (! $model) {
            return new JsonResponse(['message' => 'Ticket introuvable.'], 404);
        }

        // [PHOTO OWNER IMG_2090 · 2026-08-08] Cette liste blanche N'ADMETTAIT PAS 42 — la largeur
        // réelle de la caisse de production. Toute demande à 42 était donc silencieusement ramenée
        // à 48, et le ticket se réenroulait au caractère (« pour t » / « oi »). On accepte
        // désormais une PLAGE physique plausible, et l'absence de paramètre veut dire « prends la
        // largeur configurée » — c'est le service qui la résout, comme pour le ticket de commande.
        $width = (int) $request->query('width', 0);
        $width = ($width >= 24 && $width <= 64) ? $width : 0;

        return new JsonResponse([
            'escpos_b64' => base64_encode($this->service->renderBytes($model, $width)),
        ], 200);
    }

    /**
     * La caisse confirme le résultat de l'impression.
     */
    public function acknowledge(Request $request, int $flyer): JsonResponse
    {
        $validated = $request->validate([
            'success' => ['required', 'boolean'],
            'error'   => ['nullable', 'string', 'max:255'],
        ]);

        $model = $this->findForBranch($request, $flyer);

        if (! $model) {
            return new JsonResponse(['message' => 'Ticket introuvable.'], 404);
        }

        $this->service->acknowledge(
            $model,
            (bool) $validated['success'],
            $validated['error'] ?? null,
        );

        return new JsonResponse(['flyer' => $this->present($model->fresh())], 200);
    }

    /**
     * Recherche bornée à la branche de l'utilisateur.
     *
     * On contourne explicitement le scope global puis on refiltre à la main :
     * un administrateur porte `branch_id = 0`, le scope le laisserait donc tout
     * voir, alors qu'un ordre d'impression doit rester rattaché à SA caisse.
     */
    private function findForBranch(Request $request, int $id): ?PromoFlyer
    {
        $branchId = (int) ($request->user()->branch_id ?: 1);

        return PromoFlyer::withoutGlobalScope(BranchScope::class)
            ->where('branch_id', $branchId)
            ->whereKey($id)
            ->first();
    }

    /**
     * Codes ENCORE actifs parmi ceux fournis, en UNE requête.
     *
     * Première écriture faite dans `present()`, donc une requête PAR LIGNE : 100 tickets
     * affichés = 100 requêtes, sur un écran qu'on rafraîchit en plein service. Le défaut le
     * plus banal et le plus coûteux qui soit — corrigé avant d'atteindre l'écran.
     *
     * @param  array<int,int|null>  $couponIds
     * @return array<int,bool>  coupon_id => actif
     */
    private function couponsActifs(array $couponIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $couponIds))));
        if ($ids === []) {
            return [];
        }

        // Singulier : un coupon SUPPRIMÉ n'est pas « actif ». Le pluriel le comptait comme tel.
        return \App\Models\Coupon::withoutGlobalScope(\App\Models\Scopes\BranchScope::class)
            ->whereIn('id', $ids)
            ->where('status', \App\Enums\Status::ACTIVE)
            ->pluck('id')
            ->flip()
            ->map(fn () => true)
            ->all();
    }

    private function estRevoque(PromoFlyer $flyer): bool
    {
        if (! $flyer->coupon_id) {
            return true;
        }

        // Singulier : un coupon SUPPRIMÉ n'est pas « actif ».
        return ! \App\Models\Coupon::withoutGlobalScope(\App\Models\Scopes\BranchScope::class)
            ->whereKey($flyer->coupon_id)
            ->where('status', \App\Enums\Status::ACTIVE)
            ->exists();
    }

    private function present(PromoFlyer $flyer, ?bool $revoque = null): array
    {
        return [
            'id'            => (int) $flyer->id,
            'customer_name' => $flyer->customer_name,
            'code'          => $flyer->code,
            'status'        => $flyer->status,
            'attempts'      => (int) $flyer->attempts,
            'printed_at'    => optional($flyer->printed_at)->toIso8601String(),
            'created_at'    => optional($flyer->created_at)->toIso8601String(),
            'last_error'    => $flyer->last_error,
            'valid_until'   => $flyer->rendered_payload['valid_until'] ?? null,
            'revoked'       => $revoque ?? $this->estRevoque($flyer),
            'discount'      => $flyer->rendered_payload['discount_percent'] ?? null,
        ];
    }
}
