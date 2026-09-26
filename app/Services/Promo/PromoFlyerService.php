<?php

namespace App\Services\Promo;

use App\Enums\DiscountType;
use App\Enums\Status;
use App\Models\Branch;
use App\Models\Coupon;
use App\Models\PromoFlyer;
use App\Models\Scopes\BranchScope;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Smartisan\Settings\Facades\Settings;

/**
 * [FLYER PROMO UBER 2026-08-07] Crée le coupon nominatif et dépose l'ordre
 * d'impression du ticket.
 *
 * Le coupon est volontairement le plus restrictif possible :
 *   - une seule utilisation au total (`max_uses_global = 1`) — c'est un cadeau
 *     nominatif, pas un code à faire circuler sur les réseaux ;
 *   - une par client (`limit_per_user = 1`) — ceinture et bretelles ;
 *   - une date de fin obligatoire — un code sans échéance est une dette
 *     ouverte pour toujours.
 *
 * ⚠️ La restriction « site uniquement » a été RETIRÉE le 2026-08-07 : elle
 * rendait le code inutilisable PARTOUT, y compris sur le site (voir le
 * commentaire détaillé sur `surfaces` plus bas). Ce qui protège réellement est
 * l'usage unique, doublé des interrupteurs fermés en caisse et sur la borne.
 */
class PromoFlyerService
{
    public const SETTINGS_GROUP = 'promo_flyer';

    /**
     * Valeurs par défaut. Elles sont TOUTES surchargeables depuis l'admin :
     * l'exploitant doit pouvoir réécrire son message sans redéploiement.
     */
    public const DEFAULTS = [
        'headline'         => 'LE CAYENNE',
        // Vide = « prends le numéro de la branche ». On ne code JAMAIS un numéro en dur : un
        // faux numéro sur un ticket est pire que pas de numéro du tout.
        'order_phone'      => '',
        'intro'            => 'Merci pour ta commande ! La prochaine fois, commande en direct sur notre site : c\'est le même restaurant, mais moins cher.',
        // [CONVERSION 2026-08-08] L'ancien texte annonçait « jusqu'a -30% d'economies » —
        // en contradiction directe avec le -10% du code juste au-dessus. Le client lit -30,
        // reçoit -10, et se sent floué : deux nombres qui se contredisent sur le même bout de
        // papier détruisent la promesse au lieu de la renforcer.
        //
        // [2026-08-09] VIDÉ. En ajoutant la liste des points forts la veille, j'ai laissé ce
        // bloc en place : le ticket disait donc DEUX FOIS « Même cuisine, même équipe », à six
        // lignes d'intervalle. Quatre blocs de prose répétaient la même idée, ce qui dilue
        // l'argument au lieu de l'appuyer — et coûte du papier. L'idée unique de ce texte
        // (« tu paies le repas, pas la commission ») a rejoint la liste, à sa place.
        // Le réglage reste disponible pour qui veut ajouter un mot de fin propre à lui.
        'savings_note'     => '',
        'footer_note'      => 'À emporter et en livraison. À très vite !',
        'discount_percent' => 10,
        'validity_days'    => 30,
        'site_url'         => 'www.lecayenne.fr',
        'qr_url'           => 'https://www.lecayenne.fr/',
        // Salutation d'ouverture. VIDE = calculée à l'heure de création
        // (« Bonjour » / « Bonsoir ») — voir `resolveGreeting()`. Une valeur
        // saisie ici la fige : l'exploitant peut écrire « Merci » ou « Salut »
        // s'il préfère, au prix de la justesse horaire.
        'greeting'         => '',
        // [OWNER 2026-08-09] Les points forts, un par ligne. Ceux fournis par
        // défaut sont VÉRIFIABLES dans ce dépôt : les catégories viennent du
        // logo du restaurant, la fidélité est réellement active
        // (`pos.loyalty_enabled`), le paiement en ligne est branché (Mollie).
        // Aucune promesse inventée : un argument faux sur un ticket papier se
        // retourne contre le restaurant, et il n'est pas rattrapable.
        'strengths'        => "Même cuisine, même équipe : tu paies le repas, pas la commission\n"
            . "Tacos, burgers, sandwichs et bowls\n"
            . "Des points fidélité à chaque commande\n"
            . "Paiement en ligne sécurisé",
        // Logo imprimé en haut du ticket. Chemin RELATIF à public/ : un chemin
        // absolu saisi depuis l'admin serait une lecture de fichier arbitraire.
        'logo_path'        => 'images/kiosk-attract/logo.png',
    ];

    public function __construct(
        private readonly PromoCodeGenerator $codeGenerator,
        private readonly PromoFlyerEscPosRenderer $renderer,
    ) {
    }

    /**
     * Réglages effectifs = défauts écrasés par ce que l'exploitant a saisi.
     */
    public function settings(): array
    {
        $stored = Settings::group(self::SETTINGS_GROUP)->all();
        $stored = is_array($stored) ? array_filter($stored, static fn ($v) => $v !== null && $v !== '') : [];

        return array_merge(self::DEFAULTS, $stored);
    }

    /**
     * Crée le coupon + l'ordre d'impression, en une transaction.
     *
     * @throws \RuntimeException si aucun code libre n'a pu être généré
     */
    public function create(
        string $customerName,
        int $branchId,
        ?int $userId,
        ?string $deviceId,
        string $civility = ''
    ): PromoFlyer {
        $settings = $this->settings();
        $name = trim($customerName);

        // La collision de code est déjà quasi impossible (le générateur teste
        // avant de rendre), mais deux appareils qui créent au même instant
        // peuvent tomber sur le même tirage : l'index UNIQUE en base tranche,
        // et on retente. C'est la seule façon d'être VRAIMENT sûr.
        $lastError = null;

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $code = $this->codeGenerator->generate($name);

            try {
                return DB::transaction(function () use ($code, $name, $branchId, $userId, $deviceId, $settings, $civility) {
                    $validUntil = Carbon::now()->addDays((int) $settings['validity_days'])->endOfDay();

                    $coupon = new Coupon();
                    $coupon->forceFill([
                        'name'             => 'Flyer ' . $name,
                        'description'      => 'Code nominatif imprime sur ticket promotionnel (commande plateforme).',
                        'code'             => $code,
                        'discount'         => (float) $settings['discount_percent'],
                        'discount_type'    => DiscountType::PERCENTAGE,
                        'start_date'       => Carbon::now()->startOfDay(),
                        'end_date'         => $validUntil,
                        'minimum_order'    => 0,
                        'maximum_discount' => 0,
                        'limit_per_user'   => 1,
                        'max_uses_global'  => 1,

                        // [P0 CORRIGÉ 2026-08-07 — trouvé par test de commande réelle]
                        // `surfaces` et `branch_scope` sont volontairement LAISSÉS VIDES.
                        //
                        // Les renseigner rendait le code IMPOSSIBLE à utiliser, y compris
                        // sur sa propre surface : le chemin de tarification (zone gelée
                        // `PricingService`) résout le coupon SANS transmettre la surface ni
                        // la branche, et `Coupon::isUsableNow()` échoue en mode FERMÉ quand
                        // la surface vaut null. Comportement connu et documenté par
                        // `CouponSurfaceEnforcedAtCommitTest` : « a restricted coupon is
                        // REFUSED everywhere at commit — including on its own surface ».
                        //
                        // Le client voyait donc « −2,50 € appliqué », puis sa commande était
                        // refusée au dernier clic — le pire scénario possible, et justement
                        // celui que cette fonctionnalité prétend éviter.
                        //
                        // Ce qui protège RÉELLEMENT, sans ces champs :
                        //   - usage unique (`max_uses_global = 1`) : la garantie qui compte,
                        //     le code meurt après une utilisation quelle que soit la surface ;
                        //   - la caisse ne peut pas appliquer de coupon (`manual_discount_enabled`
                        //     est fermé, et l'ouvrir est une décision distincte) ;
                        //   - la borne non plus (`kiosk.promo_enabled` est fermé) ;
                        //   - la date de fin borne l'engagement dans le temps.
                        //
                        // Rétablir la restriction de surface suppose de faire passer branche
                        // et surface par `PricingService` — fichier GELÉ (CLAUDE.md §7), donc
                        // gate owner. C'est un défaut du système de coupons, pas du ticket :
                        // il touche TOUT coupon restreint par surface ou par branche.
                        'usage_count'      => 0,
                        'status'           => Status::ACTIVE,
                    ])->save();

                    $payload = [
                        // Nom MIS EN FORME pour l'impression ; la ligne `promo_flyers` garde
                        // la saisie brute de l'exploitant.
                        'customer_name'    => $this->displayName($name),
                        'civility'         => $civility,
                        'greeting'         => $this->resolveGreeting((string) ($settings['greeting'] ?? '')),
                        'strengths'        => $settings['strengths'] ?? '',
                        'logo_path'        => $this->resolveLogoPath((string) ($settings['logo_path'] ?? '')),
                        'code'             => $code,
                        'discount_percent' => $settings['discount_percent'],
                        'site_url'         => $settings['site_url'],
                        'qr_url'           => $this->buildQrUrl((string) $settings['qr_url'], $code),
                        'valid_until'      => $validUntil->format('d/m/Y'),
                        'headline'         => $settings['headline'],
                        'intro'            => $settings['intro'],
                        'order_phone'      => $this->resolveOrderPhone(
                            (string) ($settings['order_phone'] ?? ''),
                            $branchId
                        ),
                        'savings_note'     => $settings['savings_note'],
                        'footer_note'      => $settings['footer_note'],
                    ];

                    $flyer = new PromoFlyer();
                    $flyer->forceFill([
                        'branch_id'          => $branchId,
                        // Nom MIS EN FORME : c'est lui qui est affiché dans l'historique et
                        // qui sert à repérer un doublon (« camille » et « Camille » sont la
                        // même personne à dix minutes d'intervalle).
                        'customer_name'      => $this->displayName($name),
                        'code'               => $code,
                        'coupon_id'          => $coupon->id,
                        'status'             => PromoFlyer::STATUS_PENDING,
                        'created_by_user_id' => $userId,
                        'created_by_device'  => $deviceId,
                        // On fige le texte MAINTENANT : si l'exploitant change
                        // son message demain, on doit toujours pouvoir dire ce
                        // qui est réellement parti chez ce client.
                        'rendered_payload'   => $payload,
                    ])->save();

                    return $flyer;
                });
            } catch (QueryException $e) {
                // 23000 = violation de contrainte d'unicité : un autre appareil
                // a pris ce code entre notre vérification et notre insertion.
                if (! str_contains((string) $e->getCode(), '23')) {
                    throw $e;
                }

                $lastError = $e;
                Log::info('[FLYER] collision de code, nouvelle tentative', ['code' => $code]);
            }
        }

        throw new \RuntimeException(
            'Impossible de créer un code promo unique après 3 tentatives.',
            0,
            $lastError
        );
    }

    /**
     * Fenêtre pendant laquelle un même prénom est considéré comme la MÊME commande.
     *
     * 10 minutes : assez large pour couvrir un double appui, une hésitation ou un retour en
     * arrière ; assez court pour que deux clients réellement différents du même prénom, en
     * plein coup de feu, ne se marchent pas dessus.
     */
    public const DOUBLON_MINUTES = 10;

    /**
     * [OWNER 2026-08-13 « ameliore l'acces du caissier »] Plafond quotidien PAR CAISSIER.
     *
     * `store` était derrière `coupons_create|settings` (Admin uniquement) précisément pour
     * empêcher un caissier de frapper des codes −10 % sans limite (commit a4b9a2b46). En rendant
     * l'écran réellement utilisable par le rôle POS Operator (permission `pos-flyer-print`), ce
     * garde-fou disparaissait — il fallait le reprendre ailleurs plutôt que de le supprimer.
     *
     * 40 = large pour un usage normal (un ticket par commande plateforme, sur tout un service),
     * suffisamment bas pour rendre un abus délibéré visible et lent à exploiter. Ce n'est PAS un
     * blocage dur du métier : un plafond dépassé se voit dans les logs et peut être ajusté.
     */
    public const DAILY_CAP_PER_USER = 40;

    /**
     * Nombre de tickets déjà créés AUJOURD'HUI par ce caissier, toutes branches confondues
     * (un utilisateur n'appartient qu'à une branche en V1 mono-poste, mais on ne suppose pas).
     */
    public function dailyCountForUser(int $userId): int
    {
        return PromoFlyer::withoutGlobalScope(BranchScope::class)
            ->where('created_by_user_id', $userId)
            ->where('created_at', '>=', Carbon::now()->startOfDay())
            ->count();
    }

    /**
     * Un code a-t-il déjà été créé pour ce prénom, à l'instant ?
     *
     * [DÉTAIL 2026-08-09] Deux appuis sur « Imprimer » — un doigt qui insiste, un écran qui
     * rame — et le client repartait avec DEUX codes : deux fois 10 % offerts, deux tickets de
     * papier, et un client qui ne sait plus lequel utiliser. Rien ne l'empêchait.
     *
     * On ne BLOQUE pas : deux « Camille » dans la même soirée, ça existe. On signale, et
     * l'exploitant décide (le contrôleur laisse passer avec `force`).
     */
    public function recentDuplicate(string $customerName, int $branchId): ?PromoFlyer
    {
        $nom = $this->displayName($customerName);
        if ($nom === '') {
            return null;
        }

        return PromoFlyer::withoutGlobalScope(BranchScope::class)
            ->where('branch_id', $branchId)
            ->where('customer_name', $nom)
            ->where('created_at', '>=', Carbon::now()->subMinutes(self::DOUBLON_MINUTES))
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Prénom tel qu'il sera IMPRIMÉ.
     *
     * [DÉTAIL 2026-08-09] En plein service on tape vite : « camille », « CAMILLE », « camille  ».
     * Le ticket sortait alors « Bonsoir camille, » — négligé, sur le seul objet que le client
     * emporte chez lui. Le prénom est donc mis en forme pour l'impression.
     *
     * Les prénoms composés sont traités (« jean-luc » → « Jean-Luc », « marie claire » →
     * « Marie Claire ») : couper au premier caractère seulement produirait « Jean-luc ».
     * On ne touche PAS à ce qui est enregistré en base — la saisie de l'exploitant reste la
     * vérité, on n'embellit que le rendu.
     */
    public function displayName(string $raw): string
    {
        $nom = preg_replace('/\s+/u', ' ', trim($raw)) ?? '';
        if ($nom === '') {
            return '';
        }

        // mb_convert_case gère les accents (« élodie » → « Élodie »), contrairement à ucfirst.
        $nom = mb_convert_case($nom, MB_CASE_TITLE, 'UTF-8');

        // MB_CASE_TITLE ne repart pas après un tiret ni une apostrophe.
        return preg_replace_callback(
            "/([-'\x{2019}])(\p{L})/u",
            static fn ($m) => $m[1] . mb_strtoupper($m[2], 'UTF-8'),
            $nom
        ) ?? $nom;
    }

    /**
     * Salutation d'ouverture : « Bonjour » le jour, « Bonsoir » le soir.
     *
     * [OWNER 2026-08-09] « au debut bonsoir (prenom) ». Elle est figée ICI, à la création,
     * et non au rendu : le ticket sort quelques secondes plus tard, et l'instantané du
     * message doit rester fidèle à ce qui est réellement parti chez le client — c'est la
     * même règle que pour le reste du texte.
     *
     * Bascule à 18 h, l'usage français. Le fuseau vient de l'application (Europe/Paris) et
     * non du serveur : un VPS en UTC dirait « Bonjour » à 20 h locales.
     *
     * Une valeur saisie dans les réglages l'emporte : l'exploitant reste maître de son ton.
     */
    public function resolveGreeting(string $configured): string
    {
        $configured = trim($configured);
        if ($configured !== '') {
            return $configured;
        }

        return Carbon::now(config('app.timezone'))->hour >= 18 ? 'Bonsoir' : 'Bonjour';
    }

    /**
     * Chemin absolu du logo à imprimer, à partir d'un chemin RELATIF à `public/`.
     *
     * La valeur vient des réglages, donc d'une saisie d'administration. Elle est
     * traitée comme non fiable : un chemin absolu ou remontant (`../`) permettrait
     * de faire lire au serveur n'importe quel fichier de la machine et d'en
     * imprimer le contenu — une fuite discrète mais bien réelle. On n'accepte
     * donc qu'un chemin sage, sous `public/`, avec une extension d'image.
     */
    public function resolveLogoPath(string $relative): string
    {
        $relative = trim($relative);
        if ($relative === '') {
            return '';
        }

        if (str_contains($relative, '..') || str_starts_with($relative, '/') || preg_match('#^[a-zA-Z]:#', $relative)) {
            Log::warning('[FLYER] chemin de logo refuse (hors de public/)', ['path' => $relative]);
            return '';
        }

        if (! preg_match('/\.(png|jpe?g|gif|webp|bmp)$/i', $relative)) {
            return '';
        }

        $absolute = public_path($relative);
        $realPublic = realpath(public_path());
        $realTarget = realpath($absolute);

        // Ceinture et bretelles : même après nettoyage, on vérifie que le
        // fichier résolu vit bien SOUS public/ (liens symboliques compris).
        if ($realTarget === false || $realPublic === false || ! str_starts_with($realTarget, $realPublic)) {
            return '';
        }

        return $realTarget;
    }

    /**
     * Octets ESC/POS du ticket, à partir de l'instantané figé à la création.
     */
    /**
     * [PHOTO OWNER IMG_2090 · 2026-08-08] La largeur par défaut était 48 EN DUR, alors que la
     * caisse de production imprime 42 colonnes (`RECEIPT_WIDTH_CHARS=42`, calé en juillet sur la
     * photo IMG_1709 pour le ticket de COMMANDE). Le ticket promo est le jumeau qu'on avait
     * oublié de brancher sur ce réglage. Conséquences visibles sur le papier :
     *   · les 48 « = » du séparateur débordaient en 42 + 6 → une seconde ligne « ====== » orpheline ;
     *   · « rien que pour toi » se coupait en « pour t » / « oi », et « prelevent jusqu'a » en
     *     « prelevent j » / « usqu'a » — l'imprimante réenroule au caractère, pas au mot ;
     *   · le message d'introduction laissait un mot seul au milieu d'une ligne.
     * `0` signifie « résous-la toi-même » : config d'abord, 48 en dernier recours. Un appelant
     * peut toujours imposer une largeur, mais il n'a plus à la connaître pour obtenir la bonne.
     */
    /**
     * Numéro à composer pour commander. Priorité : réglage explicite → téléphone de la BRANCHE.
     *
     * On ne code jamais un numéro en dur, et on REFUSE les gabarits : `settings.company_phone`
     * valait « +33600000000 » sur cette installation — un numéro d'exemple. Imprimer un faux
     * numéro est PIRE que n'en imprimer aucun : le client appelle, tombe dans le vide, et c'est la
     * commande ET la confiance qui sont perdues. Le test de gabarit est donc volontairement large
     * (au moins 4 chiffres identiques d'affilée, ou une suite 123456).
     *
     * Rendu final par paires — comme on dicte un numéro à voix haute.
     */
    private function resolveOrderPhone(string $configured, int $branchId): string
    {
        $brut = trim($configured);
        if ($brut === '') {
            $brut = (string) (Branch::query()
                ->withoutGlobalScope(BranchScope::class)
                ->whereKey($branchId)
                ->value('phone') ?? '');
        }

        $chiffres = preg_replace('/\D+/', '', $brut) ?? '';
        if (strlen($chiffres) < 9) {
            return '';
        }
        if (preg_match('/(\d)\1{3,}/', $chiffres) || str_contains($chiffres, '123456')) {
            return '';
        }

        if (strlen($chiffres) === 10 && $chiffres[0] === '0') {
            return trim(chunk_split($chiffres, 2, ' '));
        }

        return $brut;
    }

    public function renderBytes(PromoFlyer $flyer, int $widthChars = 0): string
    {
        $payload = $flyer->rendered_payload ?: [];

        $w = $widthChars > 0
            ? $widthChars
            : ((int) config('printing.receipt.width_chars', 0) ?: 48);

        return $this->renderer->render($payload, $w);
    }

    /**
     * Remet un ticket dans la file d'impression.
     *
     * On remet le compteur de tentatives à zéro : le motif d'échec précédent (papier épuisé,
     * caisse éteinte) est justement ce que l'exploitant vient de corriger. Repartir de 5/5
     * ferait abandonner la file au premier cycle et le geste n'aurait servi à rien.
     *
     * Le texte du ticket n'est PAS recalculé : c'est le même cadeau, avec le même code et la
     * même échéance. Le régénérer changerait la salutation (« Bonjour » devenu « Bonsoir ») et
     * ferait mentir l'instantané conservé pour la traçabilité.
     */
    public function requeue(PromoFlyer $flyer): void
    {
        $flyer->forceFill([
            'status'     => PromoFlyer::STATUS_PENDING,
            'claimed_at' => null,
            'printed_at' => null,
            'attempts'   => 0,
            'last_error' => null,
        ])->save();
    }

    /**
     * Neutralise le code sans effacer la trace.
     *
     * DÉSACTIVATION et non suppression : savoir ce qui a été offert, à qui et quand, doit
     * survivre à l'annulation — c'est cette trace qui rend les statistiques de conversion
     * honnêtes, et c'est elle qu'on regarde si un client se présente avec un code refusé.
     *
     * Le ticket lui-même sort de la file : réimprimer un code annulé n'aurait aucun sens.
     */
    public function revoke(PromoFlyer $flyer): void
    {
        if ($flyer->coupon_id) {
            // Révoquer doit marcher même sur un coupon déjà supprimé — dit explicitement.
            Coupon::withoutGlobalScope(\App\Models\Scopes\BranchScope::class)->withTrashed()
                ->whereKey($flyer->coupon_id)
                ->update(['status' => Status::INACTIVE]);
        }

        if ($flyer->status === PromoFlyer::STATUS_PENDING) {
            $flyer->forceFill([
                'status'     => PromoFlyer::STATUS_FAILED,
                'claimed_at' => null,
                'last_error' => 'Code annulé avant impression',
            ])->save();
        }
    }

    /**
     * Ce qu'un ticket a RÉELLEMENT rapporté.
     *
     * [OWNER 2026-08-09 « ameliore … la gestion »] Le ticket coûte du papier et 10 % de marge,
     * et jusqu'ici rien ne disait s'il ramenait quelqu'un. L'exploitant dépensait à l'aveugle :
     * il pouvait imprimer cinq cents tickets sans jamais savoir si un seul client était revenu.
     * C'est la seule question qui décide de continuer, d'augmenter la remise ou d'arrêter.
     *
     * Une utilisation = une ligne `order_coupons` dont la commande n'est pas terminale-annulée.
     * C'est EXACTEMENT la règle qui décide si le coupon est consommé côté `CouponService` : si
     * on comptait autrement, le tableau de bord et le moteur de coupons se contrediraient, et
     * l'exploitant croirait à un bug de l'un ou de l'autre.
     *
     * @return array<int, array{used_at:string, order_id:int, order_total:float, discount:float}>
     *         indexé par `coupon_id`
     */
    public function redemptionsFor(array $couponIds): array
    {
        $couponIds = array_values(array_filter(array_map('intval', $couponIds)));
        if ($couponIds === []) {
            return [];
        }

        $lignes = DB::table('order_coupons')
            ->join('orders', 'orders.id', '=', 'order_coupons.order_id')
            ->whereIn('order_coupons.coupon_id', $couponIds)
            ->whereNotIn('orders.status', [
                \App\Enums\OrderStatus::CANCELED,
                \App\Enums\OrderStatus::REJECTED,
                \App\Enums\OrderStatus::RETURNED,
            ])
            ->orderBy('order_coupons.id')
            ->get([
                'order_coupons.coupon_id',
                'order_coupons.order_id',
                'order_coupons.discount',
                'order_coupons.created_at',
                'orders.total',
            ]);

        $par = [];
        foreach ($lignes as $l) {
            // Un code est à usage unique : la première utilisation valable fait foi.
            $par[(int) $l->coupon_id] ??= [
                'used_at'     => (string) $l->created_at,
                'order_id'    => (int) $l->order_id,
                'order_total' => (float) $l->total,
                'discount'    => (float) $l->discount,
            ];
        }

        return $par;
    }

    /**
     * Réclame jusqu'à $limit tickets pour un appareil donné.
     *
     * La réclamation est ATOMIQUE : deux onglets ouverts sur la caisse ne
     * doivent jamais imprimer le même ticket deux fois. On pose donc le
     * marqueur par un UPDATE conditionnel (le premier qui écrit gagne) plutôt
     * qu'en lisant puis écrivant.
     *
     * @return array<int,PromoFlyer>
     */
    public function claimPending(int $branchId, string $deviceId, int $limit = 3): array
    {
        $staleBefore = Carbon::now()->subSeconds(PromoFlyer::CLAIM_TTL_SECONDS);

        // UNE SEULE LECTURE pour le cas normal — celui qui se produit 99,99 % du temps.
        //
        // [OPTIMISATION 2026-08-09] La version précédente lançait un UPDATE de balayage AVANT
        // toute lecture, à chaque appel. Mesuré : un sondage à vide coûtait 1 ÉCRITURE + 1
        // lecture. Or cette méthode est appelée toutes les 5 s par CHAQUE écran d'administration
        // ouvert sur le PC caisse — soit ~17 000 écritures par jour et par onglet, pour ne rien
        // faire. On lit d'abord, et on n'écrit que s'il y a réellement quelque chose à écrire.
        $candidats = PromoFlyer::withoutGlobalScope(BranchScope::class)
            ->where('branch_id', $branchId)
            ->where('status', PromoFlyer::STATUS_PENDING)
            ->where(function ($q) use ($staleBefore) {
                // Réclamables : jamais pris, ou pris par un écran qui n'a pas confirmé.
                $q->whereNull('claimed_at')
                  ->orWhere('claimed_at', '<', $staleBefore)
                  // …ET les ÉPUISÉS, même encore marqués comme pris. Sans cette branche, un
                  // ticket à bout de tentatives attendait l'expiration du verrou (90 s) avant
                  // d'être signalé en échec — l'exploitant le voyait « en attente » alors qu'il
                  // était déjà abandonné. Le gain de la lecture unique ne doit pas se payer d'un
                  // silence, même court.
                  ->orWhere('attempts', '>=', PromoFlyer::MAX_ATTEMPTS);
            })
            ->orderBy('id')
            ->limit($limit + 5) // marge : les épuisés occupent des places dans la liste
            ->get(['id', 'attempts']);

        // Les tickets à bout de tentatives sortent de la file de façon VISIBLE. Sans ça ils
        // restaient « en attente » pour toujours et l'exploitant n'avait aucun moyen de savoir
        // que le ticket de son client n'était jamais sorti. On n'écrit que s'il y en a.
        $epuises = $candidats
            ->filter(fn ($f) => (int) $f->attempts >= PromoFlyer::MAX_ATTEMPTS)
            ->pluck('id')
            ->all();

        if ($epuises !== []) {
            PromoFlyer::withoutGlobalScope(BranchScope::class)
                ->whereIn('id', $epuises)
                ->update([
                    'status'     => PromoFlyer::STATUS_FAILED,
                    'claimed_at' => null,
                    'last_error' => 'Abandonne apres ' . PromoFlyer::MAX_ATTEMPTS . ' tentatives sans confirmation',
                    'updated_at' => Carbon::now(),
                ]);
        }

        $candidates = $candidats
            ->filter(fn ($f) => (int) $f->attempts < PromoFlyer::MAX_ATTEMPTS)
            ->take($limit)
            ->pluck('id');

        $claimed = [];

        foreach ($candidates as $id) {
            $affected = PromoFlyer::withoutGlobalScope(BranchScope::class)
                ->whereKey($id)
                ->where('status', PromoFlyer::STATUS_PENDING)
                ->where(function ($q) use ($staleBefore) {
                    $q->whereNull('claimed_at')->orWhere('claimed_at', '<', $staleBefore);
                })
                ->update([
                    'claimed_at'        => Carbon::now(),
                    'claimed_by_device' => substr($deviceId, 0, 64),
                    'attempts'          => DB::raw('attempts + 1'),
                    'updated_at'        => Carbon::now(),
                ]);

            if ($affected === 1) {
                $flyer = PromoFlyer::withoutGlobalScope(BranchScope::class)->find($id);
                if ($flyer) {
                    $claimed[] = $flyer;
                }
            }
        }

        return $claimed;
    }

    /**
     * La caisse confirme (ou non) l'impression.
     */
    public function acknowledge(PromoFlyer $flyer, bool $success, ?string $error = null): void
    {
        // [P1 2026-08-07 — audit adversarial] Un ticket DÉJÀ imprimé ne doit
        // jamais retourner dans la file. Sans cette garde, un accusé d'échec
        // envoyé après coup (onglet en retard, rejeu, appel forgé) repassait la
        // ligne en `pending` et la caisse la ré-imprimait au cycle suivant —
        // un second ticket, avec le même code, pour un client déjà servi.
        if ($flyer->status !== PromoFlyer::STATUS_PENDING) {
            return;
        }

        if ($success) {
            $flyer->forceFill([
                'status'     => PromoFlyer::STATUS_PRINTED,
                'printed_at' => Carbon::now(),
                'last_error' => null,
            ])->save();

            return;
        }

        // Tant qu'il reste des tentatives, le ticket retourne dans la file :
        // une imprimante à court de papier ne doit pas faire perdre le code
        // définitivement. Au-delà du plafond, il est marqué en échec pour que
        // l'exploitant le voie au lieu de le chercher.
        $exhausted = (int) $flyer->attempts >= PromoFlyer::MAX_ATTEMPTS;

        $flyer->forceFill([
            'status'     => $exhausted ? PromoFlyer::STATUS_FAILED : PromoFlyer::STATUS_PENDING,
            'claimed_at' => null,
            'last_error' => $error ? substr($error, 0, 255) : null,
        ])->save();
    }

    /**
     * URL du QR. Le code est passé en paramètre pour que le site puisse le
     * pré-remplir : scanner PUIS retaper le code à la main ferait perdre en
     * route une bonne partie des clients.
     */
    public function buildQrUrl(string $baseUrl, string $code): string
    {
        $base = trim($baseUrl);
        if ($base === '') {
            return '';
        }

        $separator = str_contains($base, '?') ? '&' : '?';

        return $base . $separator . 'promo=' . rawurlencode($code);
    }

    /**
     * Identifiant d'appareil de la requête — réutilise l'identité posée pour
     * le multi-terminaux, pour que la réclamation d'impression soit distincte
     * par écran ouvert.
     */
    public function deviceIdFrom(Request $request): string
    {
        $raw = trim((string) ($request->header('X-Device-Id') ?? ''));

        return $raw !== '' ? substr($raw, 0, 64) : 'inconnu';
    }
}
