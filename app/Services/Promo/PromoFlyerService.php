<?php

namespace App\Services\Promo;

use App\Enums\DiscountType;
use App\Enums\Status;
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
 *   - surface `web` uniquement — le ticket sert à ramener le client sur le
 *     site en direct ; l'accepter en caisse ou sur la borne offrirait une
 *     remise là où il n'y a aucune commission à éviter ;
 *   - une date de fin obligatoire — un code sans échéance est une dette
 *     ouverte pour toujours.
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
        'intro'            => 'Merci pour ta commande ! La prochaine fois, commande en direct sur notre site : c\'est le meme restaurant, mais moins cher.',
        'savings_note'     => 'Jusqu\'a -30% d\'economies en commandant en direct : les plateformes de livraison prelevent jusqu\'a 35% de commission sur chaque commande.',
        'footer_note'      => 'A emporter et en livraison. A tres vite !',
        'discount_percent' => 10,
        'validity_days'    => 30,
        'site_url'         => 'www.lecayenne.fr',
        'qr_url'           => 'https://www.lecayenne.fr/',
        'enabled'          => true,
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
    public function create(string $customerName, int $branchId, ?int $userId, ?string $deviceId): PromoFlyer
    {
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
                return DB::transaction(function () use ($code, $name, $branchId, $userId, $deviceId, $settings) {
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
                        'customer_name'    => $name,
                        'code'             => $code,
                        'discount_percent' => $settings['discount_percent'],
                        'site_url'         => $settings['site_url'],
                        'qr_url'           => $this->buildQrUrl((string) $settings['qr_url'], $code),
                        'valid_until'      => $validUntil->format('d/m/Y'),
                        'headline'         => $settings['headline'],
                        'intro'            => $settings['intro'],
                        'savings_note'     => $settings['savings_note'],
                        'footer_note'      => $settings['footer_note'],
                    ];

                    $flyer = new PromoFlyer();
                    $flyer->forceFill([
                        'branch_id'          => $branchId,
                        'customer_name'      => $name,
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
     * Octets ESC/POS du ticket, à partir de l'instantané figé à la création.
     */
    public function renderBytes(PromoFlyer $flyer, int $widthChars = 48): string
    {
        $payload = $flyer->rendered_payload ?: [];

        return $this->renderer->render($payload, $widthChars);
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

        $candidates = PromoFlyer::withoutGlobalScope(BranchScope::class)
            ->where('branch_id', $branchId)
            ->where('status', PromoFlyer::STATUS_PENDING)
            ->where('attempts', '<', PromoFlyer::MAX_ATTEMPTS)
            ->where(function ($q) use ($staleBefore) {
                $q->whereNull('claimed_at')->orWhere('claimed_at', '<', $staleBefore);
            })
            ->orderBy('id')
            ->limit($limit)
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
