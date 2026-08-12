<?php

/*
 * [Sprint H4 Z3-NEW-006 2026-05-17] KDS V2 kill-switch and adjacent runtime config.
 *
 * V2 KDS unified-queue layout is the default rollout (Wave Z 5C made the
 * flip). This config provides the org-wide enable/disable lever so an
 * operator can rollback all devices to legacy without per-tab flipping.
 *
 * Precedence resolved in KitchenDisplaySystemComponent::useV2Layout:
 *   1. URL `?v2=1`/`?v2=0`             — operator emergency, single tab
 *   2. localStorage `kds.v2_enabled`   — per-tab sticky
 *   3. window.FK_KDS_V2_DEFAULT_ENABLED — org config (this key)
 *   4. true                            — final fallback (V2 is default)
 *
 * Override via `.env`:
 *   KDS_V2_DEFAULT_ENABLED=false
 *
 * to rollback the whole org to legacy KDS until you can re-test the
 * upgrade. CLAUDE.md §11 (memory discipline for runtime config).
 */

return [
    'v2_default_enabled' => filter_var(
        env('KDS_V2_DEFAULT_ENABLED', true),
        FILTER_VALIDATE_BOOLEAN,
        FILTER_NULL_ON_FAILURE
    ) ?? true,

    /*
     * [PR-02 core-bulletproof 2026-06-04] KDS sync-degradation banner contract.
     *
     * Owner mandate: a silent degradation is GRAVE. When soketi drops and the
     * KDS falls back to ~30-60s polling, the kitchen MUST see it. The real
     * Le Cayenne box runs APP_ENV=local, so the old "hide in local" gate left
     * the kitchen blind. This key makes the contract explicit and FAIL-SAFE:
     *
     *   - BOX DEFAULT = VISIBLE (true). No override → kitchen always warned.
     *   - DEV OPT-OUT  = set KDS_SHOW_FALLBACK_BANNER=false in .env to silence
     *     the permanent banner that appears when soketi is intentionally off
     *     in development. The risky state is opt-OUT, never opt-in.
     *
     * Precedence in KitchenDisplaySystemComponent::kdsSuppressFallbackBanner:
     *   suppress ONLY IF (appEnv === 'local' AND
     *                     window.FK_KDS_SHOW_FALLBACK_BANNER === false)
     *
     * NOTE: the frontend currently reads window.FK_KDS_SHOW_FALLBACK_BANNER.
     * Wiring THIS config value through master.blade.php into that global is
     * DEFERRED to avoid a collision with a parallel session editing
     * master.blade.php. Until that wiring lands, the box default (flag
     * undefined → banner VISIBLE) already satisfies the mandate; this key
     * documents the intended contract and is the .env knob's home.
     *
     * Override via `.env`:
     *   KDS_SHOW_FALLBACK_BANNER=false
     */
    'show_fallback_banner' => filter_var(
        env('KDS_SHOW_FALLBACK_BANNER', true),
        FILTER_VALIDATE_BOOLEAN
    ),

    /*
     * [Wave R-1 P-OWNER 2026-05-20] KDS bump CTA rate-limit per-minute ceiling.
     * Owner mandate: chef chains multiple orders rapidly in fast-food kitchen.
     * Local dev raises to 1000/min in `.env` (owner manual-test bursts).
     * Production default 120/min — generous for any realistic kitchen pace.
     */
    'rate_limit_bump' => max(1, (int) env('KDS_RATE_LIMIT_BUMP', 120)),

    /*
     * [GOAL ULTRA-SYNC W4 2026-07-20] Commandes programmées (scheduled_at).
     * Une commande programmée n'apparaît sur le board cuisine (KDS + OSS) que
     * `scheduled_lead_minutes` avant son heure cible ; avant ça, elle vit dans
     * le bandeau « ⏰ programmées à venir » (meta scheduled_upcoming). Gate
     * évalué SERVEUR à chaque poll (5-60 s) — pas de cron. Lead par défaut :
     * 20 min (mandat owner « par exemple avant 20 minutes »).
     */
    'scheduled_lead_minutes' => max(1, (int) env('KDS_SCHEDULED_LEAD_MINUTES', 20)),

    /*
     * [KDS-SCHEDULED-NO-UPPER-BOUND 2026-07-22] Borne HAUTE d'une programmée sur
     * le board actif (KDS + OSS + sync). Sans elle, une programmée no-show (jamais
     * bumpée / livrée / annulée) restait à VIE : applyScheduledBoardFilter admettait
     * `scheduled_at <= now + lead` SANS plancher. On la retire du board actif
     * `scheduled_grace_hours` APRÈS son heure cible (défaut 2 h) — au-delà elle a
     * manifestement été abandonnée. Généreux : une programmée légitimement en retard
     * a déjà été bumpée près de son heure cible (dans la grâce). NULL = ASAP (borne
     * inapplicable, inchangé). Gate SELECT-only côté board, zéro impact NF525.
     */
    'scheduled_grace_hours' => max(1, (int) env('KDS_SCHEDULED_GRACE_HOURS', 2)),

    /*
     * [E4 SCHEDULED-INTAKE 2026-07-20] Fenêtre de service des commandes
     * programmées (validée à l'intake par OrderRequest::withValidator).
     * Le Cayenne sert 18h → minuit et demie : la fenêtre ENJAMBE minuit
     * (open '18:00' > close '00:30' = wrap), la validation compare l'heure
     * cible en 'H:i' 24 h. Un créneau à 00:00-00:30 est accepté (lendemain).
     */
    'scheduled_window_open' => (string) env('KDS_SCHEDULED_WINDOW_OPEN', '18:00'),
    'scheduled_window_close' => (string) env('KDS_SCHEDULED_WINDOW_CLOSE', '00:30'),

    /*
     * [WEB-PAYEE-MUETTE 2026-08-10] Âge maximum d'une commande encore éligible à
     * l'impression AUTOMATIQUE de son ticket cuisine par le pont caisse
     * (KitchenTicketQueueController).
     *
     * Cette borne n'est pas un réglage de confort, c'est un garde-fou. À la première
     * mise en service, TOUTES les commandes de l'historique ont
     * `kitchen_ticket_printed_at` à NULL : sans borne basse, le premier sondage du
     * poste caisse réclamerait des centaines de tickets et viderait le rouleau. Et
     * passé quelques dizaines de minutes, un ticket cuisine n'a plus d'usage — le
     * plat est fait, ou la commande est un problème d'exploitation, pas d'impression.
     */
    'bridge_print_window_minutes' => max(1, (int) env('KDS_BRIDGE_PRINT_WINDOW_MINUTES', 30)),

    /*
     * [RÉCLAMATION ORPHELINE 2026-08-12] Durée de vie d'une réclamation NON CONFIRMÉE.
     *
     * Un poste réclame un ticket puis meurt avant d'accuser : onglet fermé, PC redémarré, `ack`
     * parti dans un réseau coupé. Sans cette borne, la ligne de réclamation reste à vie et la
     * file exclut la commande pour toujours — le ticket est perdu, et en cuisine cela veut dire
     * un plat oublié. Trouvé en abusant de la file pendant l'audit : cinq tickets détruits en une
     * seule requête.
     *
     * Passé ce délai, une réclamation sans accusé est considérée comme abandonnée et le ticket
     * est re-proposé. La valeur reprend celle du ticket promo (`PromoFlyer::CLAIM_TTL_SECONDS`),
     * dont ce mécanisme est le jumeau : assez long pour qu'un pont lent finisse son travail,
     * assez court pour qu'un poste mort ne retienne pas le papier.
     *
     * L'arbitrage est celui que le dépôt a déjà tranché dans KitchenTicketAutoPrinter : « Mieux
     * vaut un risque de doublon qu'un ticket perdu en cuisine. »
     */
    'bridge_claim_ttl_seconds' => max(10, (int) env('KDS_BRIDGE_CLAIM_TTL_SECONDS', 90)),
];
