<!--
  [kds/sprint-2 V-5 wrapper] Single FIFO 4×2 grid wrapper. Renders 8
  KdsOrderCard tiles sorted oldest top-left → newest bottom-right.

  Responsibilities (kept thin — the orchestrator passes orders + emits PATCH):
    - FIFO sort using created_at_iso/created_at (helpers/kdsDisplay::parseOrderCreatedMs)
    - Slot assignment for bump-bar shortcut [A]–[H]
    - Auto-transition watcher (single-chef ACCEPT → PREPARING) via shouldAutoTransition
    - Immediate PATCH on Prêt tap (Wave V 2026-05-21 — was 3s pending+undo,
      but single-slot serialization killed the previous order's PATCH when chef
      chained 3+ orders back-to-back; owner mandate "enlève cette sécurité").
    - Keyboard [A]–[H] to bump corresponding slot
    - aria-live region for new card insertions / state changes

  Feature flag: kds.v2_enabled (URL ?v2=1, localStorage, or owner settings).
  Old 4-column layout stays in KitchenDisplaySystemComponent.vue v-else branch
  for instant rollback.
-->
<template>
  <div class="kds-v2" :dir="dir">
    <!-- Single banner zone -->
    <KdsStatusBanner
      :offline-since="offlineSince"
      :list-at-cap="listAtCap"
      :near-cap="activeOrders.length"
      :fallback-mode="fallbackMode"
      :admin-polling-hint="adminPollingHint"
      :bump-local-only-notice="bumpLocalOnlyNotice"
      :reserve-right-gutter="overflowActiveCount > 0"
      :now="now"
      :sync-uncertain="syncUncertain"
      :error-message="errorMessage"
    />

    <!-- Empty state (only when NO active orders — served strip below renders independently) -->
    <div v-if="activeOrders.length === 0" class="kds-v2__empty">
      <div class="kds-v2__empty-illustration" aria-hidden="true">
        <div class="kds-v2__empty-glow"></div>
        <svg width="120" height="120" viewBox="0 0 64 64" fill="none" stroke="#9CA3AF" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
          <ellipse cx="32" cy="42" rx="22" ry="6" fill="#F3F4F6" stroke="#D1D5DB" />
          <path d="M10 38a22 8 0 0 1 44 0" fill="white" />
          <path d="M14 30c0-4 6-8 18-8s18 4 18 8" />
          <circle cx="22" cy="24" r="1.5" fill="#9CA3AF" />
          <circle cx="32" cy="22" r="1.5" fill="#9CA3AF" />
          <circle cx="42" cy="24" r="1.5" fill="#9CA3AF" />
        </svg>
      </div>
      <div class="kds-v2__empty-title">{{ $t('label.kds_empty_state') }}</div>
      <div class="kds-v2__empty-sub">{{ $t('label.kds_empty_state_sub') }}</div>
    </div>

    <!-- FIFO Grid 4×2 — ACTIVE only (ACCEPT + PREPARING). PREPARED → served strip below.
         [Wave U 2026-05-21] Owner-reported bug: PREPARED orders stayed greyed in grid
         (kds-card--ready opacity:0.7) with the elapsed timer still ticking, occupying
         a slot. Now they leave the active FIFO entirely and surface in a compact
         strip at the bottom for ~last 4 served, with elapsed-since-served. -->
    <!-- [KDS-SHOW-ALL 2026-07-01] Owner-gate levée : afficher TOUTES les commandes actives
         (plus de plafond 8 qui cachait les 9+). Grille multi-colonnes qui DÉFILE ; chaque
         carte prend la hauteur de son contenu → tous les produits d'une commande sont
         visibles, une grosse commande prend plus de hauteur. Ordre FIFO préservé. -->
    <!-- [KDS-6CARDS GOAL-8AXES 2026-08-05] Owner (/goal, révoque KDS-3CARDS c70b1e518) :
         6 commandes par écran, TOUTES rendues dans un flux horizontal défilable (barre
         visible + boutons ◀ ▶) — le chef voit ce qui arrive et lance les cuissons. -->
    <!-- [KDS-6CARDS 2026-08-05] ref pour les boutons ◀ ▶ (défilement programmatique). -->
    <!-- [KDS-COLONNES 2026-08-07 owner] Sélecteur « combien de commandes à la fois ».
         Discret, en haut à droite, cible tactile large : l'écran cuisine est en plein
         écran et se pilote au doigt. -->
    <!-- [KDS-BARRE-UNIQUE 2026-08-13 · owner] Ce sélecteur créait sa PROPRE rangée sous celle des
         boutons du parent : deux bandes pour quelques boutons, avant la première commande. Il est
         désormais PROJETÉ dans la barre unique du parent (#kds-toolbar-slot) plutôt que déplacé —
         son état et sa persistance restent ici, où ils vivent depuis toujours.

         `:disabled` est un filet : si la barre n'est pas montée (mode legacy, test isolé du
         composant, cible absente), le Teleport se neutralise et le sélecteur s'affiche là où il
         était. Mieux vaut une rangée de trop qu'un sélecteur qui disparaît. -->
    <Teleport to="#kds-toolbar-slot" :disabled="!barreUniquePresente">
    <div
      v-if="activeOrders.length > 0"
      class="kds-cols-picker"
      :class="{ 'kds-cols-picker--dans-barre': barreUniquePresente }"
      role="group"
      aria-label="Nombre de commandes affichées"
    >
      <!-- [KDS-UI-MULTI 2026-08-07] La pastille « +N en attente » vivait en absolu au-dessus
           de la grille et RECOUVRAIT une carte entière (mesuré : carte [D] masquée à 4
           colonnes). Elle est rapatriée ici, dans la barre de réglage, où elle ne cache rien. -->
      <span v-if="overflowActiveCount > 0" class="kds-cols-picker__waiting" role="status">
        +{{ overflowActiveCount }} en attente
      </span>
      <button
        v-for="n in choixCartes"
        :key="`cols-${n}`"
        type="button"
        class="kds-cols-picker__btn"
        :class="{ 'is-active': n === cartesParEcran }"
        :aria-pressed="n === cartesParEcran ? 'true' : 'false'"
        :data-testid="`kds-cols-${n}`"
        @click="choisirCartesParEcran(n)"
      >{{ n }}</button>
    </div>
    </Teleport>

    <div
      v-if="activeOrders.length > 0"
      ref="gridEl"
      class="kds-v2__grid"
      :class="{ 'has-overflow': overflowActiveCount > 0 }"
      :data-count="visibleActiveOrders.length"
      :data-cols="cartesParEcran"
      :style="{ '--kds-cols': cartesParEcran }"
    >
      <KdsOrderCard
        v-for="(o, idx) in visibleActiveOrders"
        :key="o.id"
        :order="o"
        :now="now"
        :shortcut="SHORTCUTS[idx]"
        :recall-active="isRecallActive(o)"
        @ready="onCtaTap(o.id, o.queue_number)"
        @reprint="$emit('reprint', o)"
      />
    </div>
    <!-- [KDS-6CARDS] Boutons de défilement — visibles seulement si la file déborde. -->
    <button
      v-if="overflowActiveCount > 0"
      type="button"
      class="kds-scroll-btn kds-scroll-btn--left"
      aria-label="Faire défiler vers la gauche"
      data-testid="kds-scroll-left"
      @click="scrollGrid(-1)"
    >◀</button>
    <button
      v-if="overflowActiveCount > 0"
      type="button"
      class="kds-scroll-btn kds-scroll-btn--right"
      aria-label="Faire défiler vers la droite"
      data-testid="kds-scroll-right"
      @click="scrollGrid(1)"
    >▶</button>

    <!-- [Wave U 2026-05-21] Récemment servies — compact archive strip.
         Renders the 4 most recently PREPARED orders with elapsed-since-served.
         Small footprint (60px row) so it never steals space from the active grid. -->
    <div v-if="recentlyServed.length > 0" class="kds-v2__served" role="region" :aria-label="$t('label.kds_recently_served')">
      <div class="kds-v2__served-label">{{ $t('label.kds_recently_served') }}</div>
      <div class="kds-v2__served-list">
        <div
          v-for="o in recentlyServed"
          :key="`served-${o.id}`"
          class="kds-v2__served-pill keep-latin"
          :title="$t('label.kds_served_pill_title', { queue: o.queue_number || o.id })"
        >
          <span class="kds-v2__served-pill-num">N°{{ o.queue_number || o.id }}</span>
          <span class="kds-v2__served-pill-ago">{{ servedAgoLabel(o) }}</span>
          <!-- [REMETTRE-EN-PRÉPARATION 2026-08-13 · owner] « Au cas où je valide une commande
               alors qu'elle n'est pas terminée. » C'est ICI que la commande se trouve quand le
               cuisinier s'en aperçoit : dès le « Prêt », elle quitte la grille active pour cette
               bande. Le bouton devait donc être sur la pastille, pas sur la carte — une carte
               PRÊTE n'existe jamais dans la grille, le bouton n'y aurait rien affiché. -->
          <button
            type="button"
            class="kds-v2__served-pill-reopen"
            :aria-label="$t('label.kds_reopen_aria', { queue: o.queue_number || o.id })"
            :title="$t('label.kds_reopen_aria', { queue: o.queue_number || o.id })"
            :data-testid="`kds-served-reopen-${o.id}`"
            @click.prevent.stop="$emit('reopen', o)"
          >
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
              <path d="M3 12a9 9 0 1 0 3-6.7" />
              <polyline points="3 4 3 10 9 10" />
            </svg>
          </button>
        </div>
      </div>
    </div>

    <!-- [Wave V 2026-05-21] KdsUndoToast removed — chef Prêt tap now PATCHes
         immediately. The 3s undo window + single-slot serialization
         (clearTimeout(pendingTimeoutId)) caused a cross-order race: when chef
         chained "Prêt" on 3+ orders within 3s, the previous order's PATCH
         was cancelled by the next click → only the LAST order transitioned,
         the rest stayed EN COURS until chef re-clicked (perceived as a 30s
         retry-after toast). Per owner mandate "enlève cette sécurité".
         Component file kept for instant rollback. -->

    <!-- aria-live region for screen readers -->
    <div class="sr-only" aria-live="polite" aria-atomic="true">{{ liveMessage }}</div>
  </div>
</template>

<script>
import KdsOrderCard from './KdsOrderCard.vue';
import KdsStatusBanner from './KdsStatusBanner.vue';
// [Wave V 2026-05-21] KdsUndoToast import removed — see template comment.
// File kept on disk for instant rollback (git revert single commit restores).
import {
    parseOrderCreatedMs,
} from '../../../helpers/kdsDisplay.js';
import {
    shouldAutoTransition,
    pickOldestAutoPromoteCandidate,
} from '../../../helpers/kdsAutoTransition.js';
import {
    ORDER_STATUS,
} from '../../../helpers/kdsState.js';

const SHORTCUTS = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H'];

// [KDS-COLONNES 2026-08-07 owner] Cartes par écran — désormais RÉGLABLE, plus figée.
//
// Le défaut signalé : à 1 ou 2 commandes les cartes occupaient tout l'écran, puis à la
// 3ᵉ elles tombaient d'un coup au sixième de la largeur — « trop trop petit, on ne voit
// plus rien ». La largeur d'une carte est maintenant CONSTANTE quel que soit le nombre de
// commandes, et le cuisinier choisit combien il en voit à la fois.
export const KDS_CHOIX_CARTES = [4, 6, 8];
export const KDS_CARTES_PAR_ECRAN_DEFAUT = 4;
export const KDS_PREF_CARTES = 'kds.cartesParEcran';
/** Conservé pour les appelants historiques : valeur par défaut, plus un plafond figé. */
export const KDS_VISIBLE_CARDS = KDS_CARTES_PAR_ECRAN_DEFAUT;
// Plafond de RENDU : au-delà, les cartes ne sont plus montées
// (perf écran cuisine — la dev-DB a montré 488 actives) ; la pastille « +N en
// attente » couvre le surplus. En service réel la file dépasse rarement 10.
export const KDS_RENDER_MAX = 24;

export default {
    name: 'KdsV2Grid',
    components: { KdsOrderCard, KdsStatusBanner },
    props: {
        orders: { type: Array, default: () => [] },
        dir: { type: String, default: 'ltr' },
        offlineSince: { type: Number, default: null },
        listAtCap: { type: Boolean, default: false },
        fallbackMode: { type: Boolean, default: false },
        adminPollingHint: { type: Boolean, default: false },
        bumpLocalOnlyNotice: { type: Boolean, default: false },
        // [KDS-V2-BLIND-BANNERS 2026-07-22] Pure pass-through to KdsStatusBanner
        // (no business logic here) — orchestrator feeds its legacy computeds.
        syncUncertain: { type: Boolean, default: false },
        errorMessage: { type: String, default: '' },
        // [Wave Q-2 2026-05-20] Default OFF. Owner override of the RESEARCH §4.3
        // single-chef auto-promote heuristic: cashier needs a consistent
        // CONFIRMÉE → EN PRÉPARATION → PRÊT flow across all tickets so the POS
        // suivi screen stays predictable. The parent KitchenDisplaySystemComponent
        // also pins `v2AutoTransitionEnabled = false` so this stays off
        // independently of the prop default for any caller that omits the binding.
        autoTransitionEnabled: { type: Boolean, default: false },
        // [Heal-5 / PROPOSAL KDS Archive Undo 2026-05-25 — Path B compensating action]
        // Ids of orders that are currently in the RAPPELÉ window (60s after a
        // chef "Annuler bump" click). Populated by the orchestrator from
        // `kdsRecalledMap` + the 60s TTL. Each card cross-references this list
        // to decide whether to render the RAPPELÉ badge overlay.
        recallActiveIds: { type: Array, default: () => [] },
    },
    emits: ['change-status', 'auto-promote', 'reprint', 'reopen'],
    data() {
        return {
            // [KDS-COLONNES 2026-08-07 owner] Combien de commandes le cuisinier voit d'un
            // coup. Persisté sur le poste : l'écran cuisine démarre en plein écran sans
            // qu'on le reconfigure chaque matin.
            // [KDS-BARRE-UNIQUE 2026-08-13] La barre unique du parent existe-t-elle dans le DOM ?
            // Évalué au montage : en mode legacy, en test isolé, ou si la cible disparaît, le
            // Teleport se neutralise et le sélecteur reste à sa place d'origine. Une cible
            // absente ne doit JAMAIS faire disparaître le réglage — le chef s'en sert.
            barreUniquePresente: false,
            cartesParEcran: KDS_CARTES_PAR_ECRAN_DEFAUT,
            choixCartes: KDS_CHOIX_CARTES,
            now: Date.now(),
            tickerId: null,
            // [Wave V 2026-05-21] activeToast + pendingTimeoutId removed — the
            // pending bump queue is gone (immediate PATCH). aria-live message
            // is still emitted via `liveMessage` so screen-reader announcements
            // remain functional.
            liveMessage: '',
        };
    },
    computed: {
        SHORTCUTS() {
            return SHORTCUTS;
        },
        // FIFO: oldest first, then by id for stable ties. Includes ALL statuses
        // the backend feed exposes (ACCEPT + PREPARING + PREPARED) so derived
        // computeds (activeOrders, recentlyServed) can partition without
        // re-fetching. Kept as the single sort surface for parity with
        // pre-Wave-U behavior. Auto-transition watcher and keyboard shortcut
        // now read activeOrders only — PREPARED orders no longer occupy a
        // grid slot.
        visibleOrders() {
            const arr = Array.isArray(this.orders) ? [...this.orders] : [];
            arr.sort((a, b) => {
                // [#15] Guard against NaN from an unparseable created_at: a NaN
                // comparator return is treated as 0 by sort, producing unstable
                // order and bypassing the id tiebreak. Coerce non-finite to
                // +Infinity (unknown-created sorts last, deterministically).
                const ra = parseOrderCreatedMs(a);
                const rb = parseOrderCreatedMs(b);
                const ta = Number.isFinite(ra) ? ra : Infinity;
                const tb = Number.isFinite(rb) ? rb : Infinity;
                if (ta !== tb) {
                    return ta - tb;
                }
                return (parseInt(a?.id, 10) || 0) - (parseInt(b?.id, 10) || 0);
            });
            return arr;
        },
        // [Wave U 2026-05-21] Active grid orders = ACCEPT (4) + PREPARING (7) only.
        // PREPARED (8) leaves the FIFO grid (was lingering greyed via
        // kds-card--ready opacity:0.7 with timer still ticking — owner-reported bug).
        //
        // [Heal-5 / PROPOSAL KDS Archive Undo 2026-05-25 — Path B compensating action]
        // PREPARED orders whose id appears in `recallActiveIds` (i.e. inside the
        // 60s post-recall window) are RE-INJECTED into the grid so the chef
        // sees the card with the RAPPELÉ badge alongside the live work. After
        // 60s the orchestrator drops the id from the prop and the card slides
        // back to the "Récemment servies" strip via the existing partitioning.
        activeOrders() {
            const recallIds = new Set(Array.isArray(this.recallActiveIds) ? this.recallActiveIds : []);
            return this.visibleOrders.filter((o) => {
                const s = parseInt(o?.status ?? o?.rawStatus, 10);
                if (s === ORDER_STATUS.ACCEPT || s === ORDER_STATUS.PREPARING) {
                    return true;
                }
                if (s === ORDER_STATUS.PREPARED && recallIds.has(o?.id)) {
                    return true;
                }
                return false;
            });
        },
        // [Wave U 2026-05-21] Récemment servies — last 4 PREPARED orders by
        // updated_at desc (updated_at = moment of the PREPARING→PREPARED PATCH
        // applied server-side, matches "il y a X min depuis Prêt" semantics).
        // Backend feed still returns PREPARED orders until OSS/POS flips them
        // to DELIVERED, so this list naturally compacts as orders are picked up.
        recentlyServed() {
            // [P3 2026-06-26 served-age-clamp] "Récemment servies" must show only the
            // genuinely-recent. Advance PREPARED orders (is_advance_order) linger in the
            // TODAY feed for days, so without an age cap they bubble to the top of this
            // strip and render "il y a 9601 min" (~6.6j). Cap at 8h so the strip reflects
            // real recent pickups; reads `now` reactively so pills age out live.
            const maxAgeMs = 8 * 60 * 60 * 1000;
            const nowTs = this.now || Date.parse(new Date().toISOString());
            const prepared = this.visibleOrders.filter((o) => {
                const s = parseInt(o?.status ?? o?.rawStatus, 10);
                if (s !== ORDER_STATUS.PREPARED) {
                    return false;
                }
                const stamp = Date.parse(o?.updated_at || '') || 0;
                return stamp > 0 && (nowTs - stamp) <= maxAgeMs;
            });
            prepared.sort((a, b) => {
                const ta = Date.parse(a?.updated_at || '') || 0;
                const tb = Date.parse(b?.updated_at || '') || 0;
                return tb - ta;
            });
            return prepared.slice(0, 4);
        },
        // [KDS-6CARDS GOAL-8AXES 2026-08-05] RÉVOCATION owner du mandat 3-cartes
        // (c70b1e518) par directive /goal : « je veux que ça affiche six à la fois
        // et encore on pourra se scroller horizontalement pour voir les autres ».
        // TOUTES les commandes actives sont rendues dans un flux HORIZONTAL
        // défilable ; KDS_VISIBLE_CARDS cartes tiennent par écran (largeur de
        // colonne = 1/6 du viewport). Le chef voit ce qui arrive ensuite et peut
        // lancer toutes les viandes d'un coup. FIFO préservé.
        visibleActiveOrders() {
            return this.activeOrders.slice(0, KDS_RENDER_MAX);
        },
        // Raccourcis clavier bornés aux cartes garanties à l'écran SANS scroll
        // (régression P2-k : jamais bumper une commande hors de vue).
        shortcutOrders() {
            return this.activeOrders.slice(0, this.cartesParEcran);
        },
        overflowActiveCount() {
            return Math.max(0, this.activeOrders.length - this.cartesParEcran);
        },
    },
    watch: {
        // Auto-transition watcher: when the queue updates AND no order is in
        // PREPARING AND there's a NEW order at the head, promote it.
        // [Wave U 2026-05-21] Switched from visibleOrders → activeOrders so the
        // candidate picker never sees PREPARED tickets (which are excluded from
        // the rendered grid).
        activeOrders: {
            handler(newQ) {
                if (!this.autoTransitionEnabled) {
                    return;
                }
                const candidate = pickOldestAutoPromoteCandidate(newQ);
                if (candidate && shouldAutoTransition(candidate, newQ, true)) {
                    // Emit so the orchestrator can dispatch the PATCH through
                    // the existing store action — no duplicate axios pathway.
                    this.$emit('auto-promote', candidate.id);
                    this.liveMessage = this.$t('label.kds_aria_live_preparing', { id: candidate.queue_number || candidate.id });
                }
            },
            deep: false,
        },
    },
    mounted() {
        // Restaure le réglage du poste avant le premier rendu utile.
        try {
            const memo = Number(window.localStorage.getItem(KDS_PREF_CARTES));
            if (KDS_CHOIX_CARTES.includes(memo)) this.cartesParEcran = memo;
        } catch (e) { /* stockage indisponible : on garde le défaut */ }

        // [KDS-BARRE-UNIQUE 2026-08-13] La barre du parent est-elle là pour accueillir le
        // sélecteur ? Vérifié APRÈS le montage du parent : sinon le Teleport viserait une cible
        // qui n'existe pas encore. Absente → on retombe sur le rendu d'origine, jamais sur rien.
        try {
            this.barreUniquePresente = !!document.getElementById('kds-toolbar-slot');
        } catch (e) { this.barreUniquePresente = false; }

        // Single global ticker — all cards read `this.now` reactively, no
        // per-card setInterval.
        this.tickerId = window.setInterval(() => {
            this.now = Date.now();
        }, 1000);
        window.addEventListener('keydown', this.onKey);
    },
    beforeUnmount() {
        if (this.tickerId) {
            window.clearInterval(this.tickerId);
            this.tickerId = null;
        }
        // [Wave V 2026-05-21] No pendingTimeoutId to clean — onCtaTap fires
        // synchronously, no in-flight setTimeout owned by this component.
        window.removeEventListener('keydown', this.onKey);
    },
    methods: {
        /**
         * [KDS-COLONNES 2026-08-07 owner] Change le nombre de commandes visibles à la fois.
         * Le choix est mémorisé sur le poste ; un stockage indisponible (mode privé, quota)
         * ne doit jamais empêcher le changement d'affichage — la cuisine passe avant.
         */
        choisirCartesParEcran(n) {
            const valeur = KDS_CHOIX_CARTES.includes(Number(n)) ? Number(n) : KDS_CARTES_PAR_ECRAN_DEFAUT;
            this.cartesParEcran = valeur;
            try {
                window.localStorage.setItem(KDS_PREF_CARTES, String(valeur));
            } catch (e) { /* stockage indisponible : le réglage vaut pour la session */ }
            // Le défilement horizontal doit repartir du début, sinon on reste bloqué au
            // milieu d'une file qui vient de changer de largeur.
            this.$nextTick(() => { if (this.$refs.gridEl) this.$refs.gridEl.scrollLeft = 0; });
        },

        // [KDS-6CARDS 2026-08-05] Défilement d'un « écran » (~3 cartes) par clic —
        // gros boutons ◀ ▶ utilisables au doigt OU à la souris.
        scrollGrid(dir) {
            const el = this.$refs.gridEl;
            if (!el) return;
            el.scrollBy({ left: dir * Math.round(el.clientWidth / 2), behavior: 'smooth' });
        },
        onKey(e) {
            // [A]–[H] bumps the nth slot. Enter/Esc handled by KdsOrderCard.
            // [Wave U 2026-05-21] Index against the rendered list so the shortcut
            // letter matches the on-card [A]–[H] badge after PREPARED orders are
            // partitioned out of the grid.
            // [P2-k 2026-07-18 REGISTRE_FINAL] Jamais bumper une commande hors de
            // vue. [KDS-6CARDS 2026-08-05] La grille rend désormais TOUTES les
            // actives (flux horizontal défilable) — les raccourcis restent bornés
            // à `shortcutOrders` (les 6 premières, garanties à l'écran sans
            // scroll) pour préserver exactement cette garantie.
            const idx = SHORTCUTS.indexOf(String(e.key || '').toUpperCase());
            if (idx >= 0 && idx < this.shortcutOrders.length) {
                const o = this.shortcutOrders[idx];
                if (o) {
                    // [GOAL-2026-05-30 D1 — OWNER REVERSAL of Wave S-2] Cash-pending orders
                    // MAY now be bumped (kitchen prepares before encashment); the [A]–[H]
                    // shortcut no longer skips them — it matches the now-always-present CTA.
                    // The "non encaissé" note stays visible on the card (KdsOrderCard).
                    e.preventDefault();
                    this.onCtaTap(o.id, o.queue_number);
                }
            }
        },
        // [Wave V 2026-05-21 P-OWNER] Chef taps Prêt → IMMEDIATE PATCH dispatch.
        //
        // Previous design (Wave Q-2 2026-05-20): optimistic toast 3s window
        // → PATCH after timer expired. The single-slot serialization (a
        // shared `pendingTimeoutId`) cancelled any in-flight pending bump
        // whenever the chef clicked Prêt on a SECOND order within 3s. Net
        // effect when chef chained 3 tickets back-to-back:
        //   t=0    click A → pending(A), timer A
        //   t=500  click B → clearTimeout(A), pending(B), timer B
        //   t=1000 click C → clearTimeout(B), pending(C), timer C
        //   t=3000+timer C fires → only C transitions; A & B never PATCHed.
        // Chef saw A & B still in queue, re-clicked → server pipeline kept
        // up with the natural cadence (no race when individual clicks),
        // but UX read "trop de requêtes, réessayer dans 30s" toast because
        // bootstrap.js maps any incidental 429 from upstream paths to the
        // generic rate-limited copy.
        //
        // Owner mandate: "enlève cette sécurité — je veux valider 3 commandes
        // en même temps, puis 3 commandes livrées." So we remove the 3s
        // undo window entirely. Each tap fires a PATCH immediately with
        // its own X-Idempotency-Key (UUID v4 generated by
        // buildIdempotencyHeaders), and the backend OrderStateMachine
        // serialises per-order via lockForUpdate — concurrent PATCHes on
        // DIFFERENT orders are fully independent. Duplicate PATCH on the
        // SAME order returns 409 (idempotency conflict OR state machine
        // InvalidTransition) and is silently swallowed by
        // KitchenDisplaySystemComponent::onV2ChangeStatus → refresh.
        //
        // Step-ladder logic preserved from Wave Q-2: a single tap on a
        // CONFIRMÉE (ACCEPT=4) ticket advances to EN PRÉPARATION (PREPARING=7);
        // a second tap advances to PRÊT (PREPARED=8). Matches the server
        // `OrderStateMachine::allows` rule and the legacy `kdsBump` step
        // ladder in KitchenDisplaySystemComponent.vue:1716-1728.
        onCtaTap(orderId, queueNo) {
            const order = this.activeOrders.find((o) => o.id === orderId);
            if (!order) {
                return;
            }
            const currentStatus = parseInt(order?.status ?? order?.rawStatus, 10);
            const nextStatus = currentStatus === ORDER_STATUS.ACCEPT
                ? ORDER_STATUS.PREPARING
                : ORDER_STATUS.PREPARED;
            const isFinalStep = nextStatus === ORDER_STATUS.PREPARED;

            // Fire PATCH immediately — no 3s wait, no single-slot serialization.
            this.$emit('change-status', {
                orderId,
                status: nextStatus,
            });

            // a11y: announce the transition for screen readers via the
            // existing sr-only aria-live="polite" region. No visual toast.
            this.liveMessage = isFinalStep
                ? this.$t('label.kds_aria_live_ready', { id: queueNo || orderId })
                : this.$t('label.kds_aria_live_preparing', { id: queueNo || orderId });
        },
        // [Heal-5 / PROPOSAL KDS Archive Undo 2026-05-25 — Path B compensating action]
        // True if the order is in the RAPPELÉ window (passed down from the
        // orchestrator via `recallActiveIds`). KdsOrderCard renders the badge
        // overlay accordingly.
        isRecallActive(order) {
            if (!order || !Array.isArray(this.recallActiveIds)) {
                return false;
            }
            return this.recallActiveIds.includes(order.id);
        },
        // [Wave U 2026-05-21] Compact "il y a Xm" relative label for the
        // recently-served strip. Reads `now` reactively so each pill updates
        // every second alongside the active card timers (no per-pill setInterval).
        servedAgoLabel(o) {
            const stamp = Date.parse(o?.updated_at || '') || 0;
            if (!stamp) {
                return '';
            }
            const diffSec = Math.max(0, Math.floor((this.now - stamp) / 1000));
            if (diffSec < 60) {
                return this.$t('label.kds_served_just_now');
            }
            const mins = Math.floor(diffSec / 60);
            return this.$t('label.kds_served_ago', { mins });
        },
    },
};
</script>

<style scoped>
.kds-v2 {
    flex: 1;
    display: flex;
    flex-direction: column;
    background: #F9FAFB;
    position: relative;
    font-family: 'Inter', system-ui, sans-serif;
    min-height: 0;
}
[dir="rtl"] .kds-v2 {
    font-family: 'Noto Naskh Arabic', 'Inter', system-ui, sans-serif;
}

.kds-v2__grid {
    /* [KDS-6CARDS GOAL-8AXES 2026-08-05] Owner (/goal, révoque KDS-3CARDS c70b1e518) :
       6 commandes par écran + DÉFILEMENT HORIZONTAL vers la droite pour voir la suite
       de la file (mettre toutes les viandes à cuire, savoir ce qui vient après).
       grid-auto-flow:column + grid-auto-columns → toutes les cartes rendues, 6 par
       largeur d'écran, une seule rangée pleine hauteur. La barre de défilement reste
       VISIBLE et manipulable à la souris (secours si l'écran tactile lâche). */
    flex: 1;
    display: grid;
    grid-auto-flow: column;
    /* [KDS-COLONNES 2026-08-07] Largeur CONSTANTE : (largeur utile - gaps) / colonnes.
       Elle ne dépend PLUS du nombre de commandes — c'est ce saut (plein écran à 2
       commandes, puis un sixième à 3) qui rendait les cartes illisibles. */
    grid-auto-columns: calc((100% - 20px - (var(--kds-cols, 4) - 1) * 10px) / var(--kds-cols, 4));
    grid-template-rows: 1fr;
    align-items: stretch;
    gap: 10px;
    padding: 10px;
    min-height: 0;
    overflow-x: auto;
    overflow-y: hidden;
    scroll-behavior: smooth;
    /* Barre TOUJOURS visible (pas d'auto-hide) */
    scrollbar-width: auto;
    scrollbar-color: #F4501E #E5E7EB;
}
.kds-v2__grid::-webkit-scrollbar {
    height: 16px;
}
.kds-v2__grid::-webkit-scrollbar-track {
    background: #E5E7EB;
    border-radius: 8px;
}
.kds-v2__grid::-webkit-scrollbar-thumb {
    background: #F4501E;
    border-radius: 8px;
    border: 3px solid #E5E7EB;
}
/* [KDS-UI-MULTI 2026-08-07 owner] ÉCHELLE TYPOGRAPHIQUE PAR NOMBRE DE COLONNES.
   Mesuré au banc : à 8 colonnes sur 1366 px, une carte fait 152 px de large mais le numéro
   de commande restait à 52 px — il sortait de la carte, le bandeau CUISSON s'empilait un
   caractère par ligne et 1 seule ligne produit sur 6 restait visible. La cause : les tailles
   étaient en `vw`, donc calées sur la largeur d'ÉCRAN et non sur celle de la CARTE.
   Corrigé au bon endroit : la CARTE est devenue un conteneur de requête et dimensionne ses
   textes sur SA propre largeur (`cqw`, cf. KdsOrderCard). Un facteur par nombre de colonnes
   avait été essayé d'abord — insuffisant, il laissait encore le numéro chevaucher le compteur
   d'attente sur une carte de 281 px. */
/* Gouttières réservées aux boutons ◀ ▶ quand la file déborde : sans elles, les boutons se
   posaient PAR-DESSUS le contenu de la première et de la dernière carte. */
.kds-v2__grid.has-overflow {
    padding-left: 76px;
    padding-right: 76px;
}

/* [KDS-COLONNES 2026-08-07 owner] Les cas particuliers « 1 » et « 2 » sont SUPPRIMÉS :
   ils faisaient occuper tout l'écran à deux cartes, puis rétrécir brutalement à la
   troisième. Une carte garde désormais la même largeur, quelle que soit la file. */

/* Sélecteur du nombre de colonnes — flottant, discret, tactile. */
.kds-cols-picker {
    position: absolute;
    top: 8px;
    right: 16px;
    z-index: 95;
    display: flex;
    gap: 4px;
    padding: 4px;
    border-radius: 10px;
    background: rgba(26, 26, 26, 0.72);
}
/* [KDS-BARRE-UNIQUE 2026-08-13] Projeté dans la barre unique du parent, ce sélecteur doit
   redevenir un élément ORDINAIRE. Le `position: absolute` ci-dessus se calcule alors par rapport
   à la fenêtre : mesuré, il se posait PAR-DESSUS la barre d'administration et masquait
   « Tableau de bord » et le nom du chef. Un composant déplacé emporte son positionnement — il
   faut le neutraliser explicitement à l'arrivée.
   La règle vit ICI, dans le style du composant qui la pose, pour que la spécificité soit
   décidée et non laissée au hasard de l'ordre des feuilles. */
.kds-cols-picker--dans-barre {
    position: static;
    top: auto;
    right: auto;
    z-index: auto;
    padding: 2px;
    background: rgba(26, 26, 26, 0.72);
}
/* Dans la barre, ces boutons n'ont plus à porter la hauteur d'une cible flottante isolée : ce
   sont eux qui dictaient la hauteur de toute la bande (54 px pour trois chiffres). 34 px reste
   une cible confortable au doigt sur un écran tactile, et rend 20 px aux commandes. */
.kds-cols-picker--dans-barre .kds-cols-picker__btn {
    min-width: 34px;
    min-height: 34px;
    font-size: 15px;
}
.kds-cols-picker--dans-barre .kds-cols-picker__waiting {
    font-size: 12px;
    padding: 3px 8px;
}
.kds-cols-picker__waiting {
    display: inline-flex;
    align-items: center;
    padding: 0 12px;
    margin-right: 4px;
    border-radius: 8px;
    background: #F4501E;
    color: #FFFFFF;
    font-size: 15px;
    font-weight: 700;
    white-space: nowrap;
}
.kds-cols-picker__btn {
    min-width: 44px;
    min-height: 44px;
    border: none;
    border-radius: 8px;
    background: transparent;
    color: #F9FAFB;
    font-size: 18px;
    font-weight: 700;
    cursor: pointer;
}
.kds-cols-picker__btn.is-active {
    background: #F4501E;
    color: #FFFFFF;
}

/* [KDS-6CARDS] Boutons de défilement ◀ ▶ — cible tactile large (secours souris/tactile). */
.kds-scroll-btn {
    position: absolute;
    top: 50%;
    transform: translateY(-50%);
    z-index: 90;
    width: 64px;
    height: 96px;
    border: none;
    border-radius: 12px;
    background: rgba(26, 26, 26, 0.72);
    color: #fff;
    font-size: 34px;
    cursor: pointer;
}
.kds-scroll-btn:hover { background: #F4501E; }
.kds-scroll-btn--left { left: 8px; }
.kds-scroll-btn--right { right: 8px; }

.kds-v2__placeholder {
    border: 2px dashed #E5E7EB;
    border-radius: 12px;
    min-height: 200px;
}

/* [Wave N M-KDS-6 F1 P0 2026-05-24] Overflow chip — chef visibility safety net.
   Cayenne red (#F4501E) high-contrast pill, absolute top-right of .kds-v2
   (which is position:relative). z-index:100 keeps it above grid cards but the
   parent .kds-v2 stacking context contains the chip below any modal overlay.
   Pulse keyframe pulls peripheral attention; the `prefers-reduced-motion`
   media query disables animation for vestibular-sensitive operators. */
.kds-overflow-chip {
    position: absolute;
    top: 16px;
    right: 16px;
    padding: 8px 16px;
    background: #F4501E;
    color: #1A1A1A;
    border-radius: 8px;
    font-weight: 700;
    font-size: 16px;
    display: flex;
    align-items: center;
    gap: 8px;
    box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
    animation: kds-overflow-pulse 2s ease-in-out infinite;
    z-index: 100;
}
.kds-overflow-chip__icon {
    font-size: 18px;
    line-height: 1;
    font-weight: 900;
}
@keyframes kds-overflow-pulse {
    0%, 100% { transform: scale(1); }
    50% { transform: scale(1.05); }
}
@media (prefers-reduced-motion: reduce) {
    .kds-overflow-chip {
        animation: none;
    }
}

/* [Wave U 2026-05-21] Récemment servies — compact archive strip.
   Lives below the 4x2 active grid. Single row, small footprint
   (~60px total) so it never steals vertical budget from the active
   cards. Pills are read-only (no CTA, no keyboard, no timer-pulse). */
.kds-v2__served {
    flex-shrink: 0;
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 8px 16px 12px;
    border-top: 1px solid #E5E7EB;
    background: #F9FAFB;
}
.kds-v2__served-label {
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: #6B7280;
    flex-shrink: 0;
}
.kds-v2__served-list {
    display: flex;
    flex-wrap: nowrap;
    gap: 8px;
    overflow-x: auto;
    overscroll-behavior: contain;
    min-width: 0;
}
.kds-v2__served-pill {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 6px 12px;
    border-radius: 9999px;
    background: #ECFDF5;
    color: #065F46;
    border: 1px solid #A7F3D0;
    font-family: 'JetBrains Mono', ui-monospace, monospace;
    font-size: 13px;
    font-weight: 700;
    line-height: 1;
    flex-shrink: 0;
}
.kds-v2__served-pill-num {
    font-weight: 800;
    letter-spacing: -0.02em;
}
.kds-v2__served-pill-reopen {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    margin-inline-start: 6px;
    padding: 3px;
    border: none;
    border-radius: 6px;
    background: rgba(17, 17, 17, 0.08);
    color: #111111;
    cursor: pointer;
}
.kds-v2__served-pill-reopen:hover { background: rgba(17, 17, 17, 0.18); }
.kds-v2__served-pill-ago {
    font-family: 'Inter', system-ui, sans-serif;
    font-size: 11px;
    font-weight: 600;
    color: #047857;
    opacity: 0.85;
    letter-spacing: 0.02em;
}

.kds-v2__empty {
    flex: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    color: #9CA3AF;
    padding: 32px;
}
.kds-v2__empty-illustration {
    position: relative;
    width: 200px;
    height: 200px;
    margin-bottom: 32px;
    display: flex;
    align-items: center;
    justify-content: center;
}
.kds-v2__empty-glow {
    position: absolute;
    inset: 0;
    border-radius: 9999px;
    background: radial-gradient(closest-side, #F3F4F6, transparent 70%);
}
.kds-v2__empty-title {
    font-size: 32px;
    font-weight: 700;
    color: #374151;
}
.kds-v2__empty-sub {
    margin-top: 8px;
    font-size: 16px;
    color: #9CA3AF;
}

.sr-only {
    position: absolute;
    width: 1px;
    height: 1px;
    padding: 0;
    margin: -1px;
    overflow: hidden;
    clip: rect(0, 0, 0, 0);
    white-space: nowrap;
    border: 0;
}
</style>
