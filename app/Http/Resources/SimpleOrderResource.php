<?php

namespace App\Http\Resources;


use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Enums\PosPaymentMethod;
use App\Libraries\AppLibrary;
use App\Support\Order\CompositionCompactor;
use Illuminate\Http\Resources\Json\JsonResource;

class SimpleOrderResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param \Illuminate\Http\Request $request
     * @return array
     */
    public function toArray($request): array
    {
        // [Wave S-4 P-OWNER 2026-05-20] Cash-pending detection.
        // Canonical signal: order created by the kiosk paid-at-counter flow
        // (`FrontendOrderService` sets payment_status = PENDING_COUNTER (15)
        // AND pos_payment_method = COUNTER_DEFERRED (6) — see lines 264-266).
        // The POS tracker uses this flag to surface "À ENCAISSER" cards so
        // the cashier sees ONLY orders that genuinely require cash collection;
        // POS cash paid + Kiosk TPE paid + Stripe paid all fall through with
        // is_cash_pending=false → they skip directly to PRÉPARATION (Wave S-1).
        $isCashPending = (int) $this->payment_status === PaymentStatus::PENDING_COUNTER
            && (int) $this->pos_payment_method === PosPaymentMethod::COUNTER_DEFERRED;

        return [
            'id'                           => $this->id,
            'order_serial_no'              => $this->order_serial_no,
            'queue_number'                 => $this->queue_number,
            'order_datetime'               => AppLibrary::datetime($this->order_datetime),
            // [WT-D-R1-F4 2026-05-20] Raw numeric `total` for canonical FR EUR
            // rendering via the shared `formatPrice()` helper on admin surfaces
            // (PosOrderListComponent, tracker). Mirrors OrderDetailsResource
            // shape — `*_currency_price` / `*_amount_price` strings stay for
            // backward-compat consumers (exports, legacy templates) but new
            // admin renders should prefer the raw numeric so every surface
            // produces the same "19,00 €" instead of "19.00" / "19.00€".
            'total'                        => round((float) ($this->total ?? 0), 2),
            'subtotal'                     => round((float) ($this->subtotal ?? 0), 2),
            'discount'                     => round((float) ($this->discount ?? 0), 2),
            'delivery_charge'              => round((float) ($this->delivery_charge ?? 0), 2),
            "total_currency_price"         => AppLibrary::currencyAmountFormat($this->total),
            "total_amount_price"           => AppLibrary::flatAmountFormat($this->total),
            "discount_amount_price"        => AppLibrary::flatAmountFormat($this->discount),
            // [FR-MONEY 2026-06-27] variantes FR « 0,00 € » pour l'affichage liste (sales-report
            // rows, online/table orders) — le flat reste pour les inputs. Le brut « 0.00 » était
            // rendu au commerçant (SalesReportListComponent:197-198).
            "discount_currency_price"      => AppLibrary::currencyAmountFormat($this->discount),
            "delivery_charge_amount_price" => AppLibrary::flatAmountFormat($this->delivery_charge),
            "delivery_charge_currency_price" => AppLibrary::currencyAmountFormat($this->delivery_charge),
            'payment_method'               => $this->payment_method,
            'payment_status'               => $this->payment_status,
            'transaction'                  => $this->transaction ? strtoupper($this->transaction?->payment_method) : null,
            'order_type'                   => $this->order_type,
            'source'                       => $this->source,
            'source_surface'               => $this->source_surface,
            'pos_payment_method'           => $this->pos_payment_method,
            // [GOAL-CAISSE-UNIFIED W-HIST 2026-05-30] NF525 traceability columns
            // for the unified /admin/historique page. Pure projection — both are
            // existing nullable `orders` columns (fiscal_sequence_no allocated at
            // collection per Plan B; parent_order_id links a refund/counter-entry
            // to its origin sale). Exposed so the history table can show the
            // gap-free fiscal number + flag refunds without a second round-trip.
            'fiscal_sequence_no'           => $this->fiscal_sequence_no,
            'parent_order_id'              => $this->parent_order_id,
            'status'                       => $this->status,
            'status_name'                  => trans('orderStatus.' . $this->status),
            // [CAISSE-WEB-INTEL 2026-08-06] Temporel BRUT pour le tracker POS.
            // Le tracker calcule âge / tri oldest-first / aging 5-10 min sur
            // `order.created_at` (_tsOf, elapsedShort, trackerAgeClass) mais le
            // resource ne shippait que `order_datetime` FORMATÉ → sur données
            // réelles tout le temporel tournait à vide (les specs l'injectaient
            // en fixture — pattern « fixture qui encode le bug »). Additif.
            'created_at'                   => $this->created_at?->toIso8601String(),
            // [CAISSE-WEB-INTEL 2026-08-06] Commande PROGRAMMÉE (web scheduled_at
            // via OrderRequest:181). Le KDS l'exploite déjà (KitchenReleaseRule
            // hold + lead) mais le tracker caisse la peignait comme un ASAP —
            // fausse urgence aging + tri trompeur. Miroir KDSOrderDetailsResource.
            'scheduled_at'                 => $this->scheduled_at?->toIso8601String(),
            'scheduled_hm'                 => $this->scheduled_at?->format('H:i'),
            // [RED heal P3 2026-08-06] is_advance_order RETIRÉ du payload : enum Ask
            // (YES=5, NO=10 — jamais 0) ⇒ tout futur `if (o.is_advance_order)` JS serait
            // vrai pour TOUTES les commandes (piège documenté en mémoire). scheduled_at
            // couvre le besoin ; ne shipper l'enum brut à aucun consommateur JS.
            // [UBER-PHOTO 2026-08-10 · owner « le nom du client »] Le nom saisi/lu POUR CETTE
            // COMMANDE prime sur le compte porteur — même règle que la carte de cuisine
            // (KDSOrderDetailsResource::customerForKds) et que le ticket imprimé. Une commande
            // d'agrégateur est ancrée sur un utilisateur TECHNIQUE : la caisse annonçait
            // « Uber Eats » alors que le prénom du client était déjà scellé sur la commande.
            'customer_name'                => $this->displayCustomerName(),
            // [Wave S-4 P-OWNER 2026-05-20] Suivi commandes "À ENCAISSER"
            // column filter. Pure projection — no business rule changes here:
            // upstream FrontendOrderService stamps the canonical signals at
            // order creation (PENDING_COUNTER + COUNTER_DEFERRED), and the
            // POS encaissement flow (Wave S-5) flips both back to PAID once
            // the cashier collects cash. Frontend reads `is_cash_pending`
            // to filter cards into the À ENCAISSER lane and renders
            // `cash_pending_amount` as the amount due in EUR.
            'is_cash_pending'              => $isCashPending,
            // [BOUTON SCELLÉ 2026-08-19] La commande est-elle enfermée dans un Z CLOS ?
            // Calculé SERVEUR (SealedOrderGuard::sealedOrderIds, prédicat unique) et posé sur
            // le modèle par l'appelant AVANT la sérialisation — le client ne re-dérive rien.
            // ABSENT ⇒ false : les endpoints qui ne le calculent pas n'ont AUCUN comportement
            // modifié, et le serveur reste de toute façon l'autorité (l'endpoint de
            // remboursement re-teste le sceau à chaque appel). C'est un indice d'AFFICHAGE,
            // jamais une autorisation.
            'is_sealed'                    => (bool) ($this->is_sealed ?? false),
            'cash_pending_amount'          => $isCashPending
                ? AppLibrary::flatAmountFormat($this->total)
                : null,
            // [Sprint 2A DEL-3 2026-05-16] Delivery enrichment subset for the
            // admin orders list / online orders / POS sales report screens that
            // consume SimpleOrderResource. Mirrors KDSOrderDetailsResource shape
            // for downstream JS consumers (KdsOrderCard delivery block, mobile
            // courier app). schema-anchored: only fields backed by columns —
            // `apartment` is nullable, `instructions`/`floor` columns do NOT
            // exist (see migration 2023_02_20_180253).
            'order_address'                => $this->whenLoaded('address', fn () => $this->address ? [
                'label'     => $this->address->label,
                'address'   => $this->address->address,
                'apartment' => $this->address->apartment,
                'latitude'  => $this->address->latitude,
                'longitude' => $this->address->longitude,
            ] : null),
            // [Sprint 5A Z9-P0-03] GDPR data-minimization: ship customer phone
            // ONLY for DELIVERY orders. The KDS/livreur surfaces need it; the
            // admin sales-report / online-orders / POS surfaces do not, and
            // shipping PII unconditionally over the wire is a data-protection
            // defect even though the Vue UI already gated rendering.
            // [OWNER 2026-07-31] AUSSI pour les commandes WEB (source_surface='web') : une commande web est
            // DISTANTE — le caissier DOIT voir le téléphone du client pour la CONFIRMER (l'appeler = vérifier
            // que c'est une vraie commande d'une vraie personne, anti « commande nulle »). Le client web a
            // fourni un email VÉRIFIÉ (OTP) + un téléphone à l'inscription. Finalité légitime (fulfillment/
            // vérification) → la minimisation reste pour borne/walk-in (client physiquement présent).
            // [UBER-PHOTO 2026-08-10] Le téléphone de l'ANCRE TECHNIQUE d'un agrégateur
            // (« 0000000042 ») n'est le numéro de personne : affiché à côté d'une commande, il
            // finit par être composé. On rend le numéro porté par la commande s'il existe, jamais
            // celui du compte système.
            'customer_phone'               => $this->displayCustomerPhone(),
            // [Wave Q-1 P-OWNER 2026-05-19] Items summary for the POS tracker
            // cards (suivi commandes). Without this, `PosOrdersTrackerComponent`
            // renders only N°/source/time — caissier ne voyait pas le contenu.
            // N+1 guard: `OrderService::list()` eager-loads
            // `orderItems.orderItem.media/category`; for callers that didn't
            // eager-load we return [] rather than triggering a lazy SELECT.
            // Branch isolation: `OrderItem` enforces BranchScope global.
            // Mirrors SimpleDeliveryBoyOrderResource::resolveItemsForDriver().
            // [GOAL-CAISSE-VISION 2026-08-24] `composition=1` : drapeau EXPLICITE.
            // Cette ressource sert 5 appelants (suivi caisse, liste POS, historique,
            // commandes en ligne, rapport de ventes) et `OrderService::list()` charge
            // `orderItems.orderItem` dans les DEUX jeux de relations — sans porte,
            // la composition partait aussi vers l'historique et le rapport, qui ne
            // l'affichent pas : +60 Ko mesurés sur un export non borné, pour rien.
            // Drapeau dédié plutôt que `lean` : un mode « allégé » qui renverrait
            // PLUS de données serait un piège pour le prochain lecteur.
            'order_items'                  => $this->resolveItemsForTracker($request),
            // [CAISSE-WEB-INTEL 2026-08-06] Une commande web portant une
            // instruction client (allergie, « sans crudités » en note…) doit
            // être VUE avant l'accept — l'info vivait uniquement dans le
            // détail (order_items.instruction). Flag léger : pas de payload
            // gonflé (POSPERF-07), le texte reste par ligne ci-dessus.
            'has_instruction'              => $this->resolveHasInstruction(),
        ];
    }

    /**
     * Lean item list — built from the eager-loaded `orderItems` relation so
     * the resource never executes its own SELECTs (N+1 protection). When the
     * caller has not eager-loaded the relation, we fall back to an empty
     * array rather than triggering a lazy load.
     *
     * Shape consumed by PosOrdersTrackerComponent::itemsPreview() — keys
     * `item_name` and `quantity` are mandatory; `item_id` is included for
     * future linkability without forcing a payload diff later.
     */
    /**
     * [UBER-PHOTO 2026-08-10] Un canal d'agrégateur est-il derrière cette commande ? Elle est
     * alors ancrée sur un utilisateur TECHNIQUE dont ni le nom ni le numéro ne désignent un
     * client réel. Même vocabulaire que la carte de cuisine et le ticket imprimé.
     */
    private function isAggregatorAnchoredOrder(): bool
    {
        return in_array(
            strtolower(trim((string) ($this->source_surface ?? ''))),
            ['uber_eats', 'uber', 'ubereats'],
            true
        );
    }

    /** Nom à afficher : celui porté par la commande d'abord, le compte porteur ensuite. */
    private function displayCustomerName(): ?string
    {
        $nomCommande = trim((string) ($this->pos_customer_name ?? ''));
        if ($nomCommande !== '') {
            return $nomCommande;
        }

        return $this->user?->name;
    }

    /**
     * Téléphone à afficher. Reste réservé aux LIVRAISONS et aux commandes WEB (minimisation des
     * données, Z9-P0-03 + décision owner 2026-07-31), et n'est JAMAIS emprunté à l'ancre
     * technique d'un agrégateur.
     */
    private function displayCustomerPhone(): ?string
    {
        $autorise = ((int) $this->order_type === OrderType::DELIVERY) || $this->source_surface === 'web';
        if (! $autorise) {
            return null;
        }

        $telCommande = trim((string) ($this->pos_customer_phone ?? ''));
        if ($telCommande !== '') {
            return $telCommande;
        }

        // [APPS 2026-08-19] `numeroJoignable()` : inclut le numéro DÉCLARÉ des comptes
        // ouverts par connexion Apple/Google (qui n'ont pas de numéro d'identité), et ne
        // laisse plus passer la sentinelle `PENDING_…` — laquelle remontait ici telle
        // quelle, jusque dans les listes vues en caisse.
        return $this->isAggregatorAnchoredOrder() ? null : $this->user?->numeroJoignable();
    }

    private function resolveItemsForTracker($request = null): array
    {
        $relation = $this->resource->relationLoaded('orderItems')
            ? $this->resource->getRelation('orderItems')
            : null;

        if ($relation === null) {
            return [];
        }

        // La composition ne voyage QUE si l'appelant l'a demandée (voir le
        // commentaire du drapeau plus haut). Le reste de la ligne — nom, quantité,
        // instruction — n'a jamais été conditionnel et ne le devient pas.
        $avecComposition = $request !== null
            && method_exists($request, 'boolean')
            && $request->boolean('composition');

        return $relation->map(function ($line) use ($avecComposition) {
            $ligne = [
                'item_id'     => (int) $line->item_id,
                // `orderItem` est nullable (article retiré du catalogue depuis la vente).
                // Le REPLI d'affichage est délibérément laissé à la vue : le catalogue
                // `label.*` vit dans `resources/js/languages/fr.json`, pas côté serveur —
                // un `__('label.…')` ici expédierait la clé brute au caissier.
                // Voir `PosOrdersTrackerComponent::nomProduit()`.
                'item_name'   => $line->orderItem?->name,
                'quantity'    => (int) $line->quantity,
                // [CAISSE-WEB-INTEL 2026-08-06] Instruction client par ligne
                // (colonne order_items.instruction) — le caissier doit voir une
                // allergie AVANT d'accepter une commande web. Null si absente.
                'instruction' => $line->instruction ?: null,
            ];

            // [GOAL-CAISSE-VISION 2026-08-24] La COMPOSITION, en forme compacte.
            // Besoin propriétaire : « si j'ai un client devant moi, j'ai pas pris son nom,
            // je peux voir ce qu'il a pris et toutes les personnalisations qu'il a fait ».
            // Coût SQL : ZÉRO — variations/extras/instantané sont des COLONNES de
            // `order_items`, déjà rapatriées par le `select *` existant. Les clés vides
            // sont absentes : une commande sans personnalisation n'ajoute pas un octet.
            return $avecComposition ? $ligne + CompositionCompactor::forLine($line) : $ligne;
        })->values()->all();
    }

    /**
     * [CAISSE-WEB-INTEL 2026-08-06] Au moins une ligne porte une instruction
     * client. Même garde N+1 que resolveItemsForTracker : relation absente ⇒
     * false, jamais de lazy SELECT.
     */
    private function resolveHasInstruction(): bool
    {
        $relation = $this->resource->relationLoaded('orderItems')
            ? $this->resource->getRelation('orderItems')
            : null;

        if ($relation === null) {
            return false;
        }

        return $relation->contains(fn ($line) => trim((string) $line->instruction) !== '');
    }
}
