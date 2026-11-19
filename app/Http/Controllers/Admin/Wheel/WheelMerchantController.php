<?php

namespace App\Http\Controllers\Admin\Wheel;

use App\Enums\Status;
use App\Http\Controllers\Controller;
use App\Models\Item;
use App\Models\Scopes\BranchScope;
use App\Models\WheelSpin;
use App\Services\Pilotage\InterrupteurService;
use App\Services\Wheel\WheelDeliveryService;
use App\Services\Wheel\WheelReportService;
use App\Services\Wheel\WheelService;
use App\Services\Wheel\WheelSettingsService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * L'ESPACE COMMERÇANT DE LA ROUE — un seul écran privé pour tout piloter.
 *
 * [2026-11-19 · propriétaire : « je veux une interface, un accès privé seul, tout le Dashboard,
 * la roue, la gestion des produits, les avis, les liens des réseaux sociaux — modifiable et
 * fonctionnel à 100 % ; l'interface admin, je ne l'aime pas »]
 *
 * ── CE QUE CET ÉCRAN EST, ET CE QU'IL N'EST PAS ──────────────────────────────────────────────
 * C'est une FAÇADE sur le moteur existant, pas un second moteur. Le tirage, la remise d'un lot, le
 * calcul des coûts, les gardes (quantités, plafonds, rupture, codes éteints) restent dans
 * `WheelService`, `WheelDeliveryService`, `WheelReportService` — ceux que vingt-cinq suites de tests
 * tiennent déjà. Cet écran lit leurs réponses et écrit leurs réglages ; il ne décide rien à leur
 * place.
 *
 * ── LA PORTE ─────────────────────────────────────────────────────────────────────────────────
 * Derrière `wheel.access`, comme les écrans de comptoir : session caisse habilitée `pos` (passe
 * signée depuis la caisse) ou code de la maison. La caisse et l'auteur viennent de la porte,
 * JAMAIS du corps de la requête.
 */
class WheelMerchantController extends Controller
{
    /** Types de lots que le moteur sait remettre (voir `WheelService::award()`). */
    private const TYPES = ['free_item', 'points', 'coupon_percent', 'coupon_fixed'];

    public function __construct(
        private readonly WheelService $roue,
        private readonly WheelSettingsService $reglages,
        private readonly WheelReportService $rapport,
        private readonly WheelDeliveryService $remise,
    ) {}

    /** La page : une coquille, les données arrivent par `donnees()`. */
    public function page(Request $request)
    {
        return view('admin.wheel.espace', [
            'branchId' => $this->branchId($request),
            'ouvertParCode' => $request->attributes->get('wheel_actor_id') === null,
        ]);
    }

    /** Tout ce que l'espace affiche, en un appel — rechargé après chaque enregistrement. */
    public function donnees(Request $request): JsonResponse
    {
        $branchId = $this->branchId($request);

        return response()->json([
            'status' => true,
            'data' => [
                'jeu' => $this->etatDuJeu(),
                'bilan' => $this->rapport->tableau($branchId),
                'derniers' => $this->rapport->derniersGagnants($branchId, 8, 24 * 7),
                'serie' => $this->serieParJour($branchId, 30),
                'lots' => $this->lotsEditables($branchId),
                'parLot' => $this->statsParLot($branchId),
                'abandons' => $this->abandons($branchId),
                'liens' => [
                    'valeurs' => array_intersect_key(
                        $this->reglages->all(),
                        array_flip(['review_url', 'instagram_url', 'snapchat_url', 'facebook_url',
                            'review_required', 'follow_required', 'review_dwell', 'follow_dwell', 'min_order'])
                    ),
                    'etats' => $this->reglages->linkStatuses(),
                    'avisEffectif' => $this->reglages->reviewUrl(),
                    'avisDerive' => $this->reglages->reviewUrlIsDerived(),
                ],
                'produits' => $this->produits(),
                'remisesAcceptees' => $this->roue->remisesAcceptees(),
                // Ce qui est VRAIMENT à tendre : non remis ET encore valable. Le « lots dus » du
                // rapport compte aussi les expirés — affiché en pastille, il ferait chercher au
                // comptoir des lots que la remise refusera.
                'aRemettre' => WheelSpin::query()
                    ->withoutGlobalScope(BranchScope::class)
                    ->where('branch_id', $branchId)
                    ->whereNull('delivered_at')
                    ->whereIn('prize_type', ['free_item', 'points'])
                    ->where('created_at', '>=', Carbon::today()->subDays(max(1, (int) config('wheel.prize_validity_days', 30))))
                    ->count(),
            ],
        ]);
    }

    /**
     * Les lots gagnés, filtrables à l'écran (90 jours au moins, 500 lignes). La fenêtre couvre
     * TOUJOURS la validité des lots : avec une validité de 180 jours et une liste de 90, la pastille
     * « à remettre » annonçait des lots que la liste ne montrait pas — donc impossibles à remettre.
     */
    public function lots(Request $request): JsonResponse
    {
        $branchId = $this->branchId($request);
        $jours = max(90, (int) config('wheel.prize_validity_days', 30) + 1);
        $lignes = $this->rapport->historique($branchId, $jours, 500);

        return response()->json(['status' => true, 'data' => array_map(static function ($l) {
            return [
                'id' => $l['id'] ?? null,
                'quand' => $l['quand']?->toIso8601String(),
                'lot' => $l['lot'],
                'type' => $l['type'],
                'etat' => $l['etat'],
                'tel_fin' => $l['tel_fin'],
                'prenom' => $l['prenom'],
                'code' => $l['code'],
                'remis_le' => $l['remis_le']?->toIso8601String(),
                'remis_par' => $l['remis_par'],
                'expire_le' => $l['expire_le']?->toIso8601String(),
            ];
        }, $lignes)]);
    }

    /**
     * REMETTRE UN LOT depuis la liste.
     *
     * L'identifiant seul ne suffit PAS : c'est un champ que le navigateur envoie, donc une valeur
     * qu'on ne peut pas croire seule (même règle que l'écran `/admin/roue-lot`). L'équipe doit
     * présenter une preuve que le client est bien devant elle : son CODE de lot, ou son NUMÉRO.
     */
    public function remettre(Request $request, int $id): JsonResponse
    {
        $branchId = $this->branchId($request);
        $data = $request->validate([
            'code' => ['nullable', 'string', 'max:32'],
            'phone' => ['nullable', 'string', 'max:32'],
        ], [
            'code.max' => 'Le code est trop long.',
            'phone.max' => 'Le numéro est trop long.',
        ]);

        $code = trim((string) ($data['code'] ?? ''));
        $phone = trim((string) ($data['phone'] ?? ''));

        if ($code === '' && $phone === '') {
            return response()->json([
                'status' => false,
                'message' => 'Saisis le code du lot ou le numéro du client pour confirmer que c\'est bien lui.',
            ], 422);
        }

        if ($code === '') {
            // [Relecture adverse 2026-11-19 · P1] Un numéro qui ne contient AUCUN chiffre (« - »,
            // « abc ») se normalise en chaîne vide, et le service saute alors sa comparaison : la
            // « preuve » ne prouvait rien. On exige un numéro réel ET identique, ici, avant d'appeler
            // le service.
            $tel = $this->roue->normalizePhone($phone);
            $gain = WheelSpin::query()->withoutGlobalScope(BranchScope::class)
                ->where('branch_id', $branchId)->whereKey($id)->first(['id', 'phone']);
            if ($tel === '' || $gain === null || ! hash_equals((string) $gain->phone, $tel)) {
                return response()->json([
                    'status' => false,
                    'message' => 'Ce numéro n\'est pas celui du gagnant. Vérifie le numéro avec le client.',
                ], 422);
            }
            $phone = $tel;
        } else {
            $parCode = WheelSpin::parCode($branchId, $code);
            if ($parCode === null || (int) $parCode->id !== $id) {
                return response()->json([
                    'status' => false,
                    'message' => 'Ce code ne correspond pas à ce lot. Vérifie le code sur le téléphone du client.',
                ], 422);
            }
            // Le code a prouvé l'identité : on passe le numéro du lot lui-même au service, qui
            // refait sa propre vérification (caisse, validité, déjà remis).
            $phone = (string) $parCode->phone;
        }

        $r = $this->remise->deliver($id, $this->actorId($request), $branchId, $phone);

        return response()->json([
            'status' => (bool) $r['ok'],
            'message' => (string) $r['message'],
        ], $r['ok'] ? 200 : 422);
    }

    /**
     * ENREGISTRER LA ROUE — la liste complète des lots.
     *
     * Ce qui est vérifié ici, et pourquoi :
     *   · un « produit offert » doit pointer un article RÉEL et actif de la carte. Jamais un nom tapé
     *     à la main : la roue ne doit pas pouvoir promettre ce que la cuisine n'a pas ;
     *   · une remise en pourcentage doit avoir un PLAFOND en euros (sans lui, -15 % sur une commande
     *     de groupe devient un cadeau à trois chiffres) ;
     *   · au moins un lot doit pouvoir sortir (probabilité > 0), sinon la roue tourne pour rien ;
     *   · les clés des lots déjà joués ne changent JAMAIS : elles relient les gains passés à leur lot.
     */
    public function enregistrerRoue(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'lots' => ['required', 'array', 'min:1', 'max:64'],
            'lots.*.key' => ['nullable', 'string', 'max:40', 'regex:/^[a-z0-9_]+$/'],
            'lots.*.label' => ['required', 'string', 'min:1', 'max:30'],
            'lots.*.type' => ['required', 'in:'.implode(',', self::TYPES)],
            'lots.*.value' => ['nullable', 'numeric', 'min:0', 'max:10000'],
            'lots.*.max_discount' => ['nullable', 'numeric', 'min:0', 'max:200'],
            'lots.*.cost_item_id' => ['nullable', 'integer', 'min:1'],
            'lots.*.weight' => ['required', 'integer', 'min:0', 'max:1000'],
            'lots.*.quantity' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'lots.*.daily_cap' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'lots.*.kitchen_prep' => ['nullable', 'boolean'],
            'lots.*.archived' => ['nullable', 'boolean'],
        ], [
            'lots.required' => 'La roue doit avoir au moins un lot.',
            'lots.max' => 'Seize lots au maximum : au-delà, la roue devient illisible.',
            'lots.*.label.required' => 'Chaque lot doit avoir un nom affiché sur la roue.',
            'lots.*.label.max' => 'Le nom affiché sur la roue fait 30 caractères au maximum.',
            'lots.*.type.in' => 'Type de lot inconnu.',
            'lots.*.weight.required' => 'Chaque lot doit avoir une probabilité (0 = affiché, jamais gagné).',
            'lots.*.weight.integer' => 'La probabilité se donne en nombre entier.',
            'lots.*.weight.max' => 'La probabilité va de 0 à 1000.',
            'lots.*.quantity.integer' => 'Le nombre de cadeaux se donne en nombre entier (0 = illimité).',
            'lots.*.daily_cap.integer' => 'Le plafond du jour se donne en nombre entier (0 = sans plafond).',
            'lots.*.key.regex' => 'Identifiant de lot invalide.',
        ]);

        if ($v->fails()) {
            return $this->refus($v->errors()->first());
        }

        $existants = [];
        foreach ($this->roue->catalogueComplet() as $s) {
            $existants[(string) $s['key']] = $s;
        }

        $produits = Item::query()
            ->withoutGlobalScope(BranchScope::class)
            ->whereNull('deleted_at')
            ->pluck('name', 'id');

        $sortie = [];
        $cles = [];
        $tirables = 0;

        foreach ((array) $request->input('lots') as $i => $l) {
            $n = $i + 1;
            $type = (string) $l['type'];
            $label = trim((string) $l['label']);
            $archive = (bool) ($l['archived'] ?? false);

            $cle = trim((string) ($l['key'] ?? ''));

            // UN LOT RETIRÉ QUI EXISTE DÉJÀ EST FIGÉ : on garde sa définition enregistrée telle
            // quelle (ses gagnants sont servis avec), sans la revalider — un produit depuis retiré
            // de la carte ne doit pas bloquer tout enregistrement de la roue.
            if ($archive && $cle !== '' && isset($existants[$cle])) {
                if (isset($cles[$cle])) {
                    return $this->refus("Le lot n°{$n} est en double.");
                }
                if ($this->aDejaEteJoue($cle)) {
                    $cles[$cle] = true;
                    $sortie[] = ['archived' => true] + $existants[$cle];
                }

                continue;
            }

            // CHANGER LE CADEAU D'UN LOT DÉJÀ GAGNÉ = CRÉER UN NOUVEAU LOT. Sinon les gagnants
            // d'hier recevraient le nouveau produit (coût, stock, cuisine relus sur la clé) et les
            // quantités mêleraient deux cadeaux. L'ancien reste en archive pour ses gagnants.
            if ($cle !== '' && isset($existants[$cle]) && $this->aDejaEteJoue($cle)
                && $this->cadeauChange($existants[$cle], $l)) {
                $cle = '';
            }

            if ($cle === '' || ! isset($existants[$cle])) {
                // Un lot NOUVEAU reçoit une clé neuve, jamais réutilisée : la recycler rattacherait
                // les gains d'un ancien lot à celui-ci (quantités, historique).
                $base = Str::slug($label, '_') ?: 'lot';
                $cle = substr($base, 0, 24).'_'.strtolower(Str::random(4));
            }
            if (isset($cles[$cle])) {
                return $this->refus("Le lot n°{$n} est en double.");
            }
            $cles[$cle] = true;

            $lot = [
                'key' => $cle,
                'label' => $label,
                'type' => $type,
                'value' => 0,
                'weight' => (int) $l['weight'],
                'quantity' => (int) ($l['quantity'] ?? 0),
                'daily_cap' => (int) ($l['daily_cap'] ?? 0),
            ];

            if ($type === 'free_item') {
                $id = (int) ($l['cost_item_id'] ?? 0);
                if ($id <= 0 || ! isset($produits[$id])) {
                    return $this->refus("« {$label} » : choisis le produit de la carte qui sera offert.");
                }
                $lot['cost_item_id'] = $id;
                $lot['cost_item_name'] = (string) $produits[$id];
                $lot['kitchen_prep'] = (bool) ($l['kitchen_prep'] ?? false);
            } elseif ($type === 'points') {
                $pts = (int) ($l['value'] ?? 0);
                if ($pts < 1 || $pts > 10000) {
                    return $this->refus("« {$label} » : le nombre de points va de 1 à 10 000.");
                }
                $lot['value'] = $pts;
            } elseif ($type === 'coupon_percent') {
                $pct = (float) ($l['value'] ?? 0);
                $plafond = (float) ($l['max_discount'] ?? 0);
                if ($pct < 1 || $pct > 100) {
                    return $this->refus("« {$label} » : la remise va de 1 à 100 %.");
                }
                if ($plafond <= 0) {
                    return $this->refus("« {$label} » : une remise en pourcentage doit avoir un plafond en euros.");
                }
                $lot['value'] = $pct;
                $lot['max_discount'] = $plafond;
            } else { // coupon_fixed
                $eur = (float) ($l['value'] ?? 0);
                if ($eur < 0.5 || $eur > 100) {
                    return $this->refus("« {$label} » : la remise va de 0,50 € à 100 €.");
                }
                $lot['value'] = $eur;
                $lot['max_discount'] = $eur;
            }

            if ($archive) {
                // Nouveau lot retiré avant même d'avoir été publié : il n'existe pas.
                continue;
            }
            if ($lot['weight'] > 0) {
                $tirables++;
            }

            $sortie[] = $lot;
        }

        $actifs = array_filter($sortie, static fn ($s) => ! ($s['archived'] ?? false));
        // Seize lots AFFICHÉS au maximum. Les archives n'y comptent pas : elles ne s'effacent jamais
        // (leurs gagnants doivent être servis), elles ne doivent donc pas finir par bloquer la roue.
        if (count($actifs) > 16) {
            return $this->refus('Seize cadeaux au maximum sur la roue : au-delà, elle devient illisible.');
        }
        if (count($actifs) < 2) {
            return $this->refus('La roue doit afficher au moins deux lots.');
        }
        if ($tirables === 0) {
            return $this->refus('Aucun lot ne peut être gagné : donne une probabilité à au moins un lot.');
        }

        // Un lot déjà joué et retiré de la liste envoyée est CONSERVÉ en archive : ses gagnants
        // doivent encore pouvoir le recevoir (produit de référence, préparation en cuisine).
        foreach ($existants as $cle => $s) {
            if (! isset($cles[$cle]) && $this->aDejaEteJoue($cle)) {
                $s['archived'] = true;
                $sortie[] = $s;
            }
        }

        $this->reglages->saveCatalogue($sortie);

        Log::channel('daily')->info('wheel.catalogue_saved', [
            'by' => $this->actorId($request),
            'lots' => array_map(static fn ($s) => [$s['key'], $s['weight'], $s['quantity'] ?? 0, $s['archived'] ?? false], $sortie),
        ]);

        return response()->json(['status' => true, 'message' => 'Roue publiée : les clients voient la nouvelle roue dès maintenant.']);
    }

    /** Liens d'avis et de réseaux, étapes du parcours, minimum de commande. */
    public function enregistrerLiens(Request $request): JsonResponse
    {
        [$regles, $messages] = WheelSettingsController::reglesEtMessagesLiens();
        $v = Validator::make($request->all(), $regles, $messages);

        if ($v->fails()) {
            return $this->refus($v->errors()->first());
        }

        $data = $v->validated();
        $data['review_required'] = $request->boolean('review_required') ? '1' : '0';
        $data['follow_required'] = $request->boolean('follow_required') ? '1' : '0';

        $this->reglages->save(array_map(
            static fn ($x) => $x === null ? '' : (string) $x,
            $data
        ));

        return response()->json(['status' => true, 'message' => 'Liens enregistrés : le parcours client les utilise dès maintenant.']);
    }

    /** Ouvrir / fermer le jeu au public, validité des lots, plafond du jour. */
    public function enregistrerJeu(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'ouvert' => ['nullable', 'boolean'],
            'prize_validity_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'daily_total_cap' => ['nullable', 'integer', 'min:1', 'max:100000'],
        ], [
            'prize_validity_days.min' => 'Un lot reste valable au moins 1 jour.',
            'prize_validity_days.max' => 'Un lot reste valable 365 jours au maximum.',
            'prize_validity_days.integer' => 'La validité se donne en jours entiers.',
            'daily_total_cap.integer' => 'Le nombre de parties par jour se donne en nombre entier.',
            // Le moteur lit « parties du jour >= plafond » : 0 fermerait la roue, il ne la libérerait pas.
            'daily_total_cap.min' => 'Au moins 1 partie par jour (pour fermer la roue, utilise « Jeu ouvert au public »).',
        ]);

        if ($v->fails()) {
            return $this->refus($v->errors()->first());
        }

        if ($request->has('ouvert')) {
            app(InterrupteurService::class)->regler('wheel', $request->boolean('ouvert'));
        }

        $this->reglages->saveJeu($request->only(['prize_validity_days', 'daily_total_cap']));

        Log::channel('daily')->info('wheel.game_settings_saved', [
            'by' => $this->actorId($request),
            'ouvert' => $request->has('ouvert') ? $request->boolean('ouvert') : null,
        ]);

        return response()->json(['status' => true, 'message' => 'Réglages du jeu enregistrés.']);
    }

    /**
     * NOUVELLE CAMPAGNE — les compteurs de cadeaux repartent à zéro, et chacun peut rejouer une fois.
     *
     * C'est le seul levier pour relancer le jeu sans effacer l'historique : les gains passés restent,
     * attachés à leur campagne, et les lots déjà gagnés restent dus.
     */
    public function nouvelleCampagne(Request $request): JsonResponse
    {
        $nom = 'c-'.now()->format('Ymd-His');
        $this->reglages->saveJeu(['campaign_key' => $nom]);

        Log::channel('daily')->info('wheel.campaign_started', ['by' => $this->actorId($request), 'key' => $nom]);

        return response()->json(['status' => true, 'message' => 'Nouvelle campagne lancée : les compteurs repartent à zéro.']);
    }

    // ── Lectures ─────────────────────────────────────────────────────────────────────────────

    private function etatDuJeu(): array
    {
        $publicUrl = (string) config('wheel.public_url', '');

        return $this->reglages->jeu() + [
            'ouvert' => app(InterrupteurService::class)->valeur('wheel'),
            'public_url' => $publicUrl,
            'roue_url' => $publicUrl !== '' ? $publicUrl.'/roue.html' : '',
            'pin_configure' => (string) config('wheel.access.pin', '') !== '',
            'session_minutes' => (int) config('wheel.access.session_minutes', 240),
            'min_order' => $this->reglages->minOrder(),
        ];
    }

    /** Les lots tels qu'ils tournent, enrichis de ce qu'il faut pour les régler sans calculer. */
    private function lotsEditables(int $branchId): array
    {
        $tous = $this->roue->catalogueComplet();
        $total = 0;
        foreach ($tous as $s) {
            if (! ($s['archived'] ?? false)) {
                $total += max(0, (int) ($s['weight'] ?? 0));
            }
        }

        $campagne = (string) config('wheel.campaign_key');
        $partis = WheelSpin::query()
            ->withoutGlobalScope(BranchScope::class)
            ->where('branch_id', $branchId)
            ->where('campaign_key', $campagne)
            ->select('prize_key', DB::raw('count(*) as n'))
            ->groupBy('prize_key')
            ->pluck('n', 'prize_key');

        $aujourdhui = WheelSpin::query()
            ->withoutGlobalScope(BranchScope::class)
            ->where('branch_id', $branchId)
            ->whereDate('created_at', now()->toDateString())
            ->select('prize_key', DB::raw('count(*) as n'))
            ->groupBy('prize_key')
            ->pluck('n', 'prize_key');

        $tirables = array_flip($this->roue->spinnableKeys($branchId));
        $photos = $this->roue->photosParLot();

        return array_map(function ($s) use ($total, $partis, $aujourdhui, $tirables, $photos) {
            $cle = (string) $s['key'];
            $poids = max(0, (int) ($s['weight'] ?? 0));
            $quantite = max(0, (int) ($s['quantity'] ?? 0));
            $donnes = (int) ($partis[$cle] ?? 0);
            $archive = (bool) ($s['archived'] ?? false);

            return [
                'key' => $cle,
                'label' => (string) ($s['label'] ?? $cle),
                'type' => (string) ($s['type'] ?? 'free_item'),
                'value' => (float) ($s['value'] ?? 0),
                'max_discount' => (float) ($s['max_discount'] ?? 0),
                'cost_item_id' => (int) ($s['cost_item_id'] ?? 0) ?: null,
                'cost_item_name' => (string) ($s['cost_item_name'] ?? ''),
                'weight' => $poids,
                'quantity' => $quantite,
                'daily_cap' => max(0, (int) ($s['daily_cap'] ?? 0)),
                'kitchen_prep' => (bool) ($s['kitchen_prep'] ?? false),
                'archived' => $archive,
                'photo' => $photos[$cle] ?? null,
                'chance' => (! $archive && $total > 0) ? round($poids * 100 / $total, 1) : 0.0,
                'donnes' => $donnes,
                'aujourdhui' => (int) ($aujourdhui[$cle] ?? 0),
                'restant' => $quantite > 0 ? max(0, $quantite - $donnes) : null,
                // Peut-il sortir MAINTENANT ? (quantité, plafond du jour, rupture, codes éteints)
                'tirable' => isset($tirables[$cle]),
            ];
        }, $tous);
    }

    /** Parties et remises par jour, jours vides compris (un graphique sans trous ment). */
    private function serieParJour(int $branchId, int $jours): array
    {
        $depuis = Carbon::today()->subDays($jours - 1);

        $tours = WheelSpin::query()
            ->withoutGlobalScope(BranchScope::class)
            ->where('branch_id', $branchId)
            ->where('created_at', '>=', $depuis)
            ->get(['created_at', 'delivered_at']);

        $parJour = [];
        for ($d = $depuis->copy(); $d->lte(Carbon::today()); $d->addDay()) {
            $parJour[$d->toDateString()] = ['jour' => $d->toDateString(), 'parties' => 0, 'remis' => 0];
        }
        foreach ($tours as $t) {
            $j = $t->created_at?->toDateString();
            if ($j !== null && isset($parJour[$j])) {
                $parJour[$j]['parties']++;
            }
            $r = $t->delivered_at?->toDateString();
            if ($r !== null && isset($parJour[$r])) {
                $parJour[$r]['remis']++;
            }
        }

        return array_values($parJour);
    }

    /** Par lot, sur la campagne en cours : gagnés, remis, taux de retrait. */
    private function statsParLot(int $branchId): array
    {
        $lignes = WheelSpin::query()
            ->withoutGlobalScope(BranchScope::class)
            ->where('branch_id', $branchId)
            ->where('campaign_key', (string) config('wheel.campaign_key'))
            ->select('prize_key', 'prize_label', DB::raw('count(*) as gagnes'),
                DB::raw('sum(case when delivered_at is not null then 1 else 0 end) as remis'))
            ->groupBy('prize_key', 'prize_label')
            ->orderByDesc('gagnes')
            ->get();

        return $lignes->map(static fn ($l) => [
            'lot' => (string) $l->prize_label,
            'gagnes' => (int) $l->gagnes,
            'remis' => (int) $l->remis,
            'taux' => (int) $l->gagnes > 0 ? (int) round(100 * (int) $l->remis / (int) $l->gagnes) : 0,
        ])->all();
    }

    /** Où les clients décrochent, sur 7 jours. */
    private function abandons(int $branchId): array
    {
        $etapes = [0 => 0, 1 => 0, 2 => 0, 3 => 0];
        foreach ($this->rapport->parcoursIncomplets($branchId, 7, 500) as $p) {
            $etapes[(int) ($p['rang'] ?? 0)] = ($etapes[(int) ($p['rang'] ?? 0)] ?? 0) + 1;
        }

        return [
            ['etape' => 'A scanné puis s\'est arrêté', 'n' => $etapes[0]],
            ['etape' => 'Parti laisser un avis, pas revenu', 'n' => $etapes[1]],
            ['etape' => 'Parti s\'abonner, pas revenu', 'n' => $etapes[2]],
            ['etape' => 'A gagné sans laisser ses coordonnées', 'n' => $etapes[3]],
        ];
    }

    /** Les articles actifs de la carte — la seule source des « produits offerts ». */
    private function produits(): array
    {
        return Item::query()
            ->withoutGlobalScope(BranchScope::class)
            ->with(['media', 'category'])
            ->whereNull('deleted_at')
            ->where('status', Status::ACTIVE)
            ->orderBy('name')
            ->get(['id', 'name', 'price', 'slug', 'item_category_id'])
            ->map(static fn ($i) => [
                'id' => (int) $i->id,
                'name' => (string) $i->name,
                'price' => (float) $i->price,
                'categorie' => (string) ($i->category->name ?? ''),
                'photo' => $i->thumb,
            ])->all();
    }

    /**
     * Un lot est « joué » dès qu'un client l'a TIRÉ — même s'il n'a pas encore laissé ses
     * coordonnées (tirage en attente dans `wheel_step_progress`). [Relecture adverse 2026-11-19 ·
     * P1] Ne regarder que `wheel_spins` effaçait un lot tiré il y a deux minutes : à la
     * réclamation, plus de plafond (remise illimitée), plus de produit de coût, plus de cuisine.
     */
    private function aDejaEteJoue(string $cle): bool
    {
        return WheelSpin::query()
            ->withoutGlobalScope(BranchScope::class)
            ->where('prize_key', $cle)
            ->exists()
            || DB::table('wheel_step_progress')->where('prize_key', $cle)->exists();
    }

    /** Le cadeau lui-même a-t-il changé (type, produit, valeur, plafond) ? Le texte et les chances, non. */
    private function cadeauChange(array $avant, array $apres): bool
    {
        $type = (string) ($apres['type'] ?? '');
        if ((string) ($avant['type'] ?? '') !== $type) {
            return true;
        }
        if ($type === 'free_item') {
            return (int) ($avant['cost_item_id'] ?? 0) !== (int) ($apres['cost_item_id'] ?? 0);
        }

        return abs((float) ($avant['value'] ?? 0) - (float) ($apres['value'] ?? 0)) > 0.001
            || ($type === 'coupon_percent'
                && abs((float) ($avant['max_discount'] ?? 0) - (float) ($apres['max_discount'] ?? 0)) > 0.001);
    }

    private function refus(string $message): JsonResponse
    {
        return response()->json(['status' => false, 'message' => $message], 422);
    }

    private function branchId(Request $request): int
    {
        $porte = (int) $request->attributes->get('wheel_branch_id', 0);

        return $porte > 0 ? $porte : 1;
    }

    private function actorId(Request $request): ?int
    {
        $porte = (int) $request->attributes->get('wheel_actor_id', 0);

        return $porte > 0 ? $porte : null;
    }
}
