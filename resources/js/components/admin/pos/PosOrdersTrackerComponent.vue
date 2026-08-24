<template>
    <!--
      [POS-V4-ORDERS-TRACKER 2026-05-02]
      Écran caisse plein écran : kanban des commandes actives (POS + borne + online)
      pour le caissier, avec live update Echo. Aucune logique de pricing — affichage
      des totaux renvoyés par le backend (invariant FoodKing : pricing SSOT).
      L'écran client (OSS) reste séparé : route admin.order-status-screen.
    -->
    <section class="pos-tracker-shell" data-pos-tracker-shell>
        <ConnectionStatusBanner suppress-transient suppress-session-invalid />

        <header class="pos-tracker-bar">
            <div class="pos-tracker-bar-left min-w-0">
                <p class="pos-tracker-eyebrow">Caisse Le Cayenne</p>
                <h1 class="pos-tracker-title">{{ $t('pos.tracker.title') }}</h1>
                <div class="pos-tracker-status-row">
                    <span>
                        <strong>{{ stats.active }}</strong>
                        {{ $t('pos.tracker.active_orders') }}
                    </span>
                    <span class="pos-tracker-status-pill pos-tracker-status-pill--ready" v-if="stats.ready > 0">
                        <i class="fa-solid fa-bell-concierge" aria-hidden="true"></i>
                        {{ stats.ready }} {{ $t('pos.tracker.ready_short') }}
                    </span>
                    <!-- [CAISSE-WEB-INTEL 2026-08-06] Pill « web à traiter » : compte les
                         commandes du site exigeant une action caissier (accepter / encaisser).
                         Cliquable → filtre 🌐. FR direct (ADR-007). -->
                    <button
                        v-if="webActionableCount > 0"
                        type="button"
                        class="pos-tracker-status-pill pos-tracker-status-pill--web"
                        data-testid="tracker-web-pill"
                        title="Afficher les commandes du site web"
                        @click="filters.source = 'online'"
                    >
                        🌐 {{ webActionableCount }} web à traiter
                    </button>
                    <!-- [RED-TEAM 2026-08-19] Libellé HONNÊTE. Depuis la fenêtre « journée de
                         service », ce compteur couvre AUSSI la veille entre minuit et 5 h :
                         annoncer « aujourd'hui » ferait mentir le nombre (140 commandes de la
                         veille + 3 depuis minuit = « 143 aujourd'hui »). Le mot bascule dès que
                         la fenêtre s'étend sur deux jours civils. -->
                    <span>{{ stats.todayCount }} {{ windowSpansTwoDays ? $t('pos.tracker.service_total') : $t('pos.tracker.today_total') }}</span>
                    <!-- [COMMANDES EN SOUFFRANCE 2026-08-19] Le tableau ne montre que la journée
                         de SERVICE : tout ce qui traîne au-delà était devenu invisible (577 non
                         terminées mesurées le 2026-08-19, dont 486 payées, la plus ancienne du
                         2026-05-28). La pilule n'apparaît que s'il y en a, et le nombre est le
                         TOTAL réel, pas la taille de la page. Instantané rafraîchi à l'ouverture
                         du panneau et après chaque action — pas à chaque sondage : ce compteur
                         bouge en heures, il ne mérite pas une requête toutes les 5 secondes. -->
                    <button
                        v-if="staleMeta.count > 0"
                        type="button"
                        class="pos-tracker-status-pill pos-tracker-status-pill--stale"
                        data-testid="tracker-stale-pill"
                        :aria-expanded="staleOpen ? 'true' : 'false'"
                        aria-controls="pos-tracker-stale-panel"
                        title="Commandes non terminées antérieures à la journée de service"
                        @click="toggleStalePanel"
                    >
                        <i class="fa-solid fa-hourglass-half" aria-hidden="true"></i>
                        {{ staleMeta.count }} en souffrance
                    </button>
                    <!-- [CAISSE-HEALTH 2026-07-30] Santé système au cœur de la vue d'ensemble : l'opérateur
                         voit une dégradation temps réel/fiscale AVANT de perdre des commandes en silence. -->
                    <pos-system-health-pill />
                </div>
            </div>
            <div class="pos-tracker-bar-right">
                <div class="pos-tracker-search">
                    <i class="lab lab-search-normal" aria-hidden="true"></i>
                    <input
                        type="search"
                        v-model.trim="filters.query"
                        :placeholder="$t('pos.tracker.search_placeholder')"
                        :aria-label="$t('pos.tracker.search_placeholder')"
                    />
                    <button
                        v-if="filters.query"
                        type="button"
                        class="pos-tracker-search-clear"
                        @click="filters.query = ''"
                        :aria-label="$t('button.clear')"
                    >✕</button>
                </div>
                <div class="pos-tracker-source-tabs" role="tablist" :aria-label="$t('pos.tracker.source_filter')">
                    <button
                        v-for="src in sourceTabs"
                        :key="src.id"
                        type="button"
                        role="tab"
                        :aria-selected="filters.source === src.id"
                        :class="['pos-tracker-source-tab', filters.source === src.id ? 'is-active' : '']"
                        @click="filters.source = src.id"
                    >
                        <span class="pos-tracker-source-tab-icon" aria-hidden="true">{{ src.icon }}</span>
                        <span>{{ src.label }}</span>
                    </button>
                </div>
                <!-- [FLYER PROMO 2026-08-08] Ticket promo depuis la CAISSE : l'exploitant
                     voit arriver une commande plateforme et imprime le ticket sans
                     changer d'écran. Le nom se pré-remplit depuis la commande quand on
                     part de sa carte (bouton 🎟️ sur la carte). -->
                <button
                    v-if="canPrintFlyer"
                    type="button"
                    class="pos-tracker-history-link"
                    @click="openPromoFlyer('')"
                    title="Imprimer un ticket promo nominatif (commande plateforme)"
                    data-testid="pos-tracker-promo-flyer"
                >
                    <span aria-hidden="true">🎟️</span>
                    <span>Ticket promo</span>
                </button>
                <!-- [OWNER REPAS-PERSONNEL/PERTES 2026-07-31] Ouvre la modale de sortie de stock hors-vente. -->
                <button
                    type="button"
                    class="pos-tracker-history-link"
                    @click="stockOutflowOpen = true"
                    title="Enregistrer un repas personnel ou une perte (sortie de stock)"
                    data-testid="pos-tracker-outflow"
                >
                    <i class="fa-solid fa-utensils" aria-hidden="true"></i>
                    <span>Sortie stock</span>
                </button>
                <router-link
                    :to="{ name: 'admin.historique.list' }"
                    class="pos-tracker-history-link"
                    :title="$t('pos.tracker.history_hint')"
                    data-testid="pos-tracker-history"
                >
                    <i class="fa-solid fa-list-ul" aria-hidden="true"></i>
                    <span>{{ $t('pos.tracker.history') }}</span>
                </router-link>
                <router-link
                    :to="{ name: 'admin.order-status-screen' }"
                    target="_blank"
                    rel="noopener"
                    class="pos-tracker-customer-link"
                    :title="$t('pos.tracker.customer_screen_hint')"
                >
                    <i class="fa-solid fa-display" aria-hidden="true"></i>
                    {{ $t('pos.tracker.customer_screen') }}
                </router-link>
                <router-link
                    :to="{ name: 'admin.pos' }"
                    class="pos-tracker-back-link"
                >
                    <i class="fa-solid fa-arrow-left" aria-hidden="true"></i>
                    {{ $t('pos.tracker.back_to_pos') }}
                </router-link>
            </div>
        </header>

        <!--
          [iter15-mega-fix B-003/C-008 2026-05-10] Local realtime banner was
          missed by run-1 fix that gated only the global ConnectionStatusBanner.
          In local/dev (no Pusher/Soketi), `realtimeConnected` is permanently
          false → banner shouts "Connexion temps réel perdue" forever, polluting
          dev/demo screenshots and visual audits. Production keeps the banner
          (genuinely useful when WS is down for staff). Same env gate pattern
          as ConnectionStatusBanner.vue isDevEnv computed.
        -->
        <div v-if="!realtimeConnected && !isDevEnv" class="pos-tracker-rt-warn" role="status">
            {{ $t('pos.tracker.realtime_lost') }}
        </div>

        <!--
          [S2 F1 révisé 2026-07-29] Ce board ne montre que LE JOUR. S'il reste des
          commandes à encaisser plus anciennes (elles vivent dans la file
          all-time /admin/encaissement), on l'annonce ici — sans les rapatrier
          dans les colonnes, ce qui écraserait le signal du jour et volerait
          leurs cartes aux voies « Prêts » / « Livrés ».
        -->
        <router-link
            v-if="olderPendingCount > 0"
            :to="{ name: 'admin.encaissement' }"
            class="pos-tracker-older-pending"
            data-testid="tracker-older-pending"
        >
            💶 {{ $t('pos.tracker.older_pending', { count: olderPendingCount }) }}
        </router-link>

        <div class="pos-tracker-grid" :aria-busy="loading ? 'true' : 'false'">
            <article
                v-for="col in columns"
                :key="col.id"
                :class="['pos-tracker-col', `pos-tracker-col--${col.tone}`, col.highlight && col.orders.length > 0 ? 'is-pulse' : '']"
            >
                <header class="pos-tracker-col-head">
                    <div class="pos-tracker-col-head-titles">
                        <h2>
                            <span class="pos-tracker-col-icon" aria-hidden="true">{{ col.icon }}</span>
                            {{ col.label }}
                        </h2>
                        <!--
                          [Wave S-4 P-OWNER 2026-05-20] Lane subtitle clarifies
                          the renamed "À encaisser" lane semantic for the
                          cashier (kiosk paid-at-counter orders only).
                        -->
                        <p v-if="col.subtitle" class="pos-tracker-col-subtitle">{{ col.subtitle }}</p>
                    </div>
                    <span class="pos-tracker-col-count" :aria-label="`${col.orders.length} ${col.label}`">
                        {{ col.orders.length }}
                    </span>
                </header>

                <div class="pos-tracker-col-body" v-if="col.orders.length > 0">
                    <transition-group name="pos-tracker-card" tag="div" class="pos-tracker-cards">
                        <article
                            v-for="order in col.orders"
                            :key="order.id"
                            :class="['pos-tracker-card', `pos-tracker-card--${col.tone}`, newReadyIds.has(order.id) ? 'is-fresh' : '', trackerAgeClass(order, col.id)]"
                            :data-testid="`tracker-order-${order.id}`"
                        >
                            <header class="pos-tracker-card-head">
                                <span class="pos-tracker-card-num">
                                    {{ order.queue_number ? 'N°' + order.queue_number : ('#' + (order.order_serial_no || order.id)) }}
                                </span>
                                <!--
                                  [Wave S-4 P-OWNER 2026-05-20] Cash-pending
                                  bell badge — visible only on cards in the
                                  À encaisser lane. Same icon as the column
                                  header (🔔) reinforces the cashier signal.
                                -->
                                <span
                                    v-if="isCashPending(order)"
                                    class="pos-tracker-card-cash-badge"
                                    :title="$t('pos.tracker.cash_due_label')"
                                    :data-testid="`tracker-cash-badge-${order.id}`"
                                    aria-label="Commande à encaisser"
                                >
                                    🔔
                                </span>
                                <!-- [CAISSE-WEB-INTEL 2026-08-06] Payée EN LIGNE (CB) : rien à
                                     encaisser — le badge tue le doute (anti double encaissement),
                                     symétrique du 🔔 cash-pending. -->
                                <span
                                    v-if="isPaidOnline(order)"
                                    class="pos-tracker-card-paid-badge"
                                    title="Payée en ligne par carte — ne pas encaisser"
                                    :data-testid="`tracker-paid-online-${order.id}`"
                                    aria-label="Commande déjà payée en ligne"
                                >
                                    ✅ CB
                                </span>
                                <!-- [CAISSE-WEB-INTEL 2026-08-06] Livraison signalée dès l'arrivée
                                     (order_type) — avant, seule la voie 🛵 OUT_FOR_DELIVERY la
                                     révélait, trop tard pour organiser la préparation. -->
                                <span
                                    v-if="isDeliveryOrder(order)"
                                    class="pos-tracker-card-type-badge"
                                    title="Commande en livraison"
                                    :data-testid="`tracker-delivery-${order.id}`"
                                >
                                    🛵
                                </span>
                                <span :class="['pos-tracker-card-source', `pos-tracker-card-source--${sourceOf(order)}`]"
                                      :title="$t('pos.tracker.source_' + sourceOf(order))">
                                    {{ sourceIcon(order) }}
                                </span>
                                <span class="pos-tracker-card-time" :title="formatTime(order.created_at)">
                                    {{ elapsedShort(order.created_at) }}
                                </span>
                            </header>
                            <!--
                              [IMP-AGING 2026-07-22] Age badge — À encaisser lane only.
                              A web/kiosk order waiting for the cashier turns orange
                              after 5 min (tracker-card--aging) and red/pulsing after
                              10 min (tracker-card--urgent). Rendered only when the
                              aging threshold is crossed so quiet cards stay clean.
                              New testid (additive — no existing testid touched).
                            -->
                            <div
                                v-if="trackerAgeClass(order, col.id)"
                                :class="['pos-tracker-card-age', trackerAgeClass(order, col.id) === 'tracker-card--urgent' ? 'pos-tracker-card-age--urgent' : '']"
                                :data-testid="`tracker-age-${order.id}`"
                            >
                                <i class="fa-solid fa-hourglass-half" aria-hidden="true"></i>
                                <span>{{ agingLabel(order) }}</span>
                            </div>
                            <!-- [CAISSE-WEB-INTEL 2026-08-06] Commande PROGRAMMÉE : « 🕐 pour 19:30 ».
                                 Sans ce badge, une commande web pour ce soir ressemblait à un ASAP
                                 (et l'aging la peignait « urgente » à tort — exclu dans trackerAgeClass). -->
                            <div
                                v-if="scheduledLabel(order)"
                                class="pos-tracker-card-scheduled"
                                :data-testid="`tracker-scheduled-${order.id}`"
                            >
                                <span aria-hidden="true">🕐</span>
                                <span>{{ scheduledLabel(order) }}</span>
                            </div>
                            <!-- [CAISSE-WEB-INTEL 2026-08-06] Instruction client (allergie, note) :
                                 visible AVANT l'accept — l'info vivait uniquement dans le détail. -->
                            <div
                                v-if="instructionPreview(order)"
                                class="pos-tracker-card-instruction"
                                :data-testid="`tracker-instruction-${order.id}`"
                                role="note"
                            >
                                <span aria-hidden="true">⚠️</span>
                                <span>{{ instructionPreview(order) }}</span>
                            </div>
                            <!--
                              [OWNER 2026-07-31] Identité client VISIBLE avant l'accept/encaissement :
                              nom + téléphone. Pour une commande WEB (distante) le caissier DOIT pouvoir
                              rappeler le client pour CONFIRMER que c'est une vraie commande d'une vraie
                              personne (anti « commande nulle »). Le téléphone vient de SimpleOrderResource
                              (customer_phone, shippé pour web + delivery). Additif — nom déjà affiché.
                            -->
                            <div class="pos-tracker-card-customer" v-if="customerLabel(order) || customerPhone(order)">
                                <i class="fa-solid fa-user" aria-hidden="true"></i>
                                <span>{{ customerLabel(order) }}</span>
                                <a
                                    v-if="customerPhone(order)"
                                    class="pos-tracker-card-phone"
                                    :href="`tel:${customerPhone(order)}`"
                                    :data-testid="`tracker-customer-phone-${order.id}`"
                                    :title="`Appeler ${customerLabel(order) || 'le client'} pour confirmer la commande`"
                                    @click.stop
                                ><i class="fa-solid fa-phone" aria-hidden="true"></i> {{ customerPhone(order) }}</a>
                            </div>
                            <!--
                              [GOAL-CAISSE-VISION 2026-08-24 · demande propriétaire]
                              « si j'ai un client devant moi, j'ai pas pris son nom, je peux voir
                              ce qu'il a pris et toutes les personnalisations qu'il a fait ».
                              Avant : 3 noms de produits et un total. Un caissier ne pouvait pas
                              distinguer deux sandwichs identiques commandés différemment.
                              Désormais chaque ligne porte sa composition résumée, et « Voir tout »
                              ouvre le contenu COMPLET sans quitter l'écran ni toucher le réseau.
                            -->
                            <ul class="pos-tracker-card-items">
                                <li
                                    v-for="(item, idx) in itemsPreview(order)"
                                    :key="idx"
                                >
                                    <span class="pos-tracker-card-qty">{{ item.quantity || 1 }}×</span>
                                    <span class="pos-tracker-card-name">{{ nomProduit(item) }}</span>
                                    <span
                                        v-if="resumeComposition(item)"
                                        class="pos-tracker-card-compo"
                                        :data-testid="`tracker-compo-${order.id}-${idx}`"
                                        :title="resumeComposition(item)"
                                    >{{ resumeComposition(item) }}</span>
                                </li>
                                <li v-if="extraItemsCount(order) > 0" class="pos-tracker-card-more">
                                    + {{ extraItemsCount(order) }} {{ $t('pos.tracker.more_items') }}
                                </li>
                            </ul>
                            <button
                                v-if="aDuContenuAVoir(order)"
                                type="button"
                                class="pos-tracker-card-voirtout"
                                :data-testid="`tracker-voir-tout-${order.id}`"
                                :aria-label="`Voir tout le contenu de la commande ${order.queue_number || order.order_serial_no || order.id}`"
                                @click.stop="ouvrirContenu(order)"
                            >
                                <i class="fa-solid fa-list-ul" aria-hidden="true"></i>
                                <span>Voir tout</span>
                            </button>
                            <footer class="pos-tracker-card-foot">
                                <!--
                                  [WT-D-R1-F4 2026-05-20] `order.total` is now
                                  shipped raw by `SimpleOrderResource` (Wave T
                                  R1 F4 heal). It's the SSOT we should prefer;
                                  the `total_amount_price` / `order_amount`
                                  fallbacks remain for callers that consume
                                  the resource pre-WT (cache, legacy mirrors)
                                  and to keep the existing failing-safe
                                  pattern. The shared `formatPrice()` (from
                                  `adminPriceMixin`) renders any of them as
                                  the canonical "19,00 €".

                                  Historical context (kept for archeology):
                                  iter15-mega-fix B-001/C-002 2026-05-10
                                  patched the `Number(undefined) || 0` →
                                  `0,00 €` regression that the old projection
                                  caused.
                                -->
                                <span
                                    :class="['pos-tracker-card-total', isCashPending(order) ? 'pos-tracker-card-total--cash' : '']"
                                    :data-testid="`tracker-amount-${order.id}`"
                                >
                                    <!--
                                      [Wave S-4 P-OWNER 2026-05-20] When the
                                      card is cash-pending, the total carries
                                      a "À encaisser" prefix so the cashier
                                      sees the amount due, not just a price.
                                    -->
                                    <!--
                                      [S2 V4 2026-07-29] Le «&nbsp;:» est collé au
                                      libellé (insécable) : en inline-flex il se
                                      détachait sur sa propre ligne, produisant un
                                      «&nbsp;:» orphelin sous un libellé cassé en 3
                                      lignes qui chevauchait le bouton Encaisser.
                                    -->
                                    <span v-if="isCashPending(order)" class="pos-tracker-card-total-prefix">{{ $t('pos.tracker.cash_due_label') }}&nbsp;:</span>
                                    {{ formatPrice(order.cash_pending_amount ?? order.total ?? order.total_amount_price ?? order.order_amount) }}
                                </span>
                                <div class="pos-tracker-card-actions">
                                    <!--
                                      [FLYER PROMO 2026-08-08] Ticket promo, sur les seules
                                      commandes venues d'une PLATEFORME : c'est là que la
                                      commission de 30-35 % s'applique et qu'un retour en
                                      direct rapporte. Le prénom de la commande pré-remplit
                                      la fenêtre — un geste au lieu de trois, en plein service.
                                    -->
                                    <button
                                        v-if="isPlatformOrder(order) && canPrintFlyer"
                                        type="button"
                                        class="pos-tracker-card-btn"
                                        title="Imprimer un ticket promo pour ce client"
                                        :data-testid="`tracker-promo-${order.id}`"
                                        @click.stop="openPromoFlyer(customerLabel(order))"
                                    >
                                        <span aria-hidden="true">🎟️</span>
                                        <span class="hidden xl:inline">Ticket promo</span>
                                    </button>
                                    <!--
                                      [Wave S-4 P-OWNER 2026-05-20] Encaisser
                                      CTA — only on cash-pending cards. Wires
                                      to Wave S-5 encaissement modal via a
                                      window-level CustomEvent so the two
                                      heals can ship independently. The card
                                      stays clickable for details via the eye
                                      link below; the encaisser CTA is the
                                      primary action surface.
                                    -->
                                    <button
                                        v-if="col.id === 'accept' && isCashPending(order)"
                                        type="button"
                                        class="pos-tracker-card-btn pos-tracker-card-btn--cash"
                                        :title="$t('pos.tracker.cash_collect_cta')"
                                        :data-testid="`tracker-encaisser-${order.id}`"
                                        @click="openEncaissement(order)"
                                    >
                                        <i class="fa-solid fa-cash-register" aria-hidden="true"></i>
                                        <span class="hidden xl:inline">{{ $t('pos.tracker.cash_collect_cta') }}</span>
                                    </button>
                                    <!--
                                      [WEB-TRACKER-VISIBILITY 2026-07-20] Commande WEB PENDING
                                      (pas encore acceptée) : CTA « Accepter » inline — même flux
                                      que le panneau web caisse (C1). Après accept → cash-pending
                                      → le CTA Encaisser ci-dessus prend le relais. FR direct.
                                    -->
                                    <template v-else-if="col.id === 'accept' && isWebPending(order) && canProcessWebOrders">
                                        <!-- [CAISSE-WEB-INTEL 2026-08-06] Temps de préparation RÉEL choisi
                                             à l'acceptation (persisté via preparation_time, lu par le suivi
                                             client) — fini le défaut global aveugle de 15 min. -->
                                        <select
                                            class="pos-tracker-prep-select"
                                            :value="webPrepChoice[order.id] ?? 15"
                                            :data-testid="`tracker-prep-${order.id}`"
                                            title="Temps de préparation annoncé au client"
                                            aria-label="Temps de préparation"
                                            @change="webPrepChoice = { ...webPrepChoice, [order.id]: parseInt($event.target.value, 10) }"
                                            @click.stop
                                        >
                                            <option :value="15">15 min</option>
                                            <option :value="25">25 min</option>
                                            <option :value="40">40 min</option>
                                        </select>
                                        <button
                                            type="button"
                                            class="pos-tracker-card-btn pos-tracker-card-btn--cash"
                                            :disabled="!!webAccepting[order.id]"
                                            title="Accepter la commande web — encaissement au comptoir"
                                            :data-testid="`tracker-accept-web-${order.id}`"
                                            @click="acceptWebOrder(order)"
                                        >
                                            <i class="fa-solid fa-globe" aria-hidden="true"></i>
                                            <span class="hidden xl:inline">{{ webAccepting[order.id] ? 'Acceptation…' : 'Accepter' }}</span>
                                        </button>
                                    </template>
                                    <router-link
                                        :to="{ name: 'admin.pos-orders.show', params: { id: order.id } }"
                                        class="pos-tracker-card-btn"
                                        :title="$t('pos.tracker.view_details')"
                                    >
                                        <i class="fa-solid fa-eye" aria-hidden="true"></i>
                                    </router-link>
                                    <!--
                                      [POS-V4-CASHIER-OPS 2026-05-02] One-click reprint.
                                      Loads full order then mounts ReceiptComponent inside this view.
                                    -->
                                    <button
                                        type="button"
                                        class="pos-tracker-card-btn"
                                        :disabled="reprintBusyId === order.id"
                                        :title="$t('pos.reprint_ticket_hint')"
                                        :data-testid="`tracker-reprint-${order.id}`"
                                        @click="requestReprint(order)"
                                    >
                                        <i class="fa-solid fa-print" aria-hidden="true"></i>
                                    </button>
                                    <!--
                                      [POS-V4-CASHIER-OPS 2026-05-02] Cancel with reason.
                                      Visible only for non-final statuses (ACCEPT/PREPARING/PREPARED).
                                      Backend OrderService L1546-1551 already enforces the reason
                                      (required, max 700) — we duplicate the rule client-side
                                      to short-circuit the round-trip + give immediate UX feedback.
                                    -->
                                    <!--
                                      [BOUTON SCELLÉ 2026-08-19] Une commande enfermée dans un Z CLOS
                                      ne PEUT PLUS être annulée — le refus vient de NF525 et il est
                                      juste. Mais le bouton « Annuler » restait AFFICHÉ : le caissier
                                      cliquait, recevait une erreur, et restait sans issue. 68 commandes
                                      scellées mesurées sur 400 lignes réelles.
                                      La sortie légitime existe déjà : la contrepartie comptable
                                      (« Rembourser » → refund-with-counter-entry, la commande d'origine
                                      reste immuable). On la propose À LA PLACE.
                                      Sans le droit `pos-refund`, on n'affiche PAS un second bouton mort :
                                      un état inerte DIT pourquoi et qui peut le faire.
                                    -->
                                    <button
                                        v-if="col.id !== 'delivered' && !cancelBlockedReason(order)"
                                        type="button"
                                        class="pos-tracker-card-btn pos-tracker-card-btn--danger"
                                        :title="$t('pos.cancel_order_hint')"
                                        :data-testid="`tracker-cancel-${order.id}`"
                                        @click="openCancelDialog(order)"
                                    >
                                        <i class="fa-solid fa-ban" aria-hidden="true"></i>
                                    </button>
                                    <button
                                        v-else-if="col.id !== 'delivered' && cancelBlockedReason(order) === 'sealed' && canRefundSealed"
                                        type="button"
                                        class="pos-tracker-card-btn pos-tracker-card-btn--danger"
                                        title="Ticket clôturé dans un Z : annulation impossible (NF525). Émettre un remboursement en contrepartie."
                                        :data-testid="`tracker-refund-${order.id}`"
                                        @click="openRefundDialog(order)"
                                    >
                                        <i class="fa-solid fa-rotate-left" aria-hidden="true"></i>
                                        <span class="hidden xl:inline">Rembourser</span>
                                    </button>
                                    <span
                                        v-else-if="col.id !== 'delivered'"
                                        class="pos-tracker-card-btn pos-tracker-card-btn--sealed"
                                        role="note"
                                        :title="cancelBlockedReason(order) === 'sealed'
                                            ? 'Ticket clôturé dans un Z : ni annulation ni remboursement depuis ce compte. Un responsable doit émettre la contrepartie.'
                                            : 'Commande PAYÉE : l’annuler rend l’argent, ce compte n’a pas le droit de remboursement. Un responsable doit la traiter.'"
                                        :data-testid="`tracker-blocked-${order.id}`"
                                    >
                                        <i class="fa-solid fa-lock" aria-hidden="true"></i>
                                        <span class="hidden xl:inline">{{ cancelBlockedReason(order) === 'sealed' ? 'Clôturé' : 'Responsable' }}</span>
                                    </span>
                                    <button
                                        v-if="col.id === 'prepared'"
                                        type="button"
                                        class="pos-tracker-card-btn pos-tracker-card-btn--primary"
                                        :disabled="!!order._delivering"
                                        :title="$t('pos.tracker.mark_delivered')"
                                        @click="markDelivered(order)"
                                    >
                                        <i class="fa-solid fa-check" aria-hidden="true"></i>
                                        <span class="hidden xl:inline">{{ $t('pos.tracker.delivered_short') }}</span>
                                    </button>
                                </div>
                            </footer>
                        </article>
                    </transition-group>
                </div>
                <div v-else class="pos-tracker-col-empty">
                    <span class="pos-tracker-col-empty-icon" aria-hidden="true">{{ col.emptyIcon }}</span>
                    <p>{{ col.emptyLabel || $t('pos.tracker.empty_column') }}</p>
                </div>
            </article>
        </div>

        <!--
          [COMMANDES EN SOUFFRANCE 2026-08-19] Panneau dépliable, SÉPARÉ des voies.
          Fondre 577 vieilles commandes dans les colonnes noierait les 2 du service en cours —
          c'est l'inverse du service rendu. Ici on les VOIT, on les rouvre, on les clôt.
        -->
        <section
            v-if="staleOpen"
            id="pos-tracker-stale-panel"
            class="pos-tracker-stale"
            data-testid="tracker-stale-panel"
        >
            <header class="pos-tracker-stale-head">
                <h2>
                    <i class="fa-solid fa-hourglass-half" aria-hidden="true"></i>
                    En souffrance — non terminées avant la journée de service
                </h2>
                <div class="pos-tracker-stale-head-right">
                    <span v-if="staleMeta.truncated" class="pos-tracker-stale-truncated">
                        {{ staleMeta.shown }} affichées sur {{ staleMeta.count }}
                    </span>
                    <button type="button" class="pos-tracker-card-btn" @click="fetchStaleOrders" :disabled="staleLoading">
                        <i class="fa-solid fa-arrows-rotate" aria-hidden="true"></i>
                    </button>
                    <button type="button" class="pos-tracker-card-btn" @click="staleOpen = false" aria-label="Fermer">✕</button>
                </div>
            </header>

            <p v-if="staleLoading" class="pos-tracker-stale-msg">Lecture…</p>
            <!-- Un panneau vide après une erreur se lirait « il n'y en a pas » : on le DIT. -->
            <p v-else-if="staleError" class="pos-tracker-stale-msg pos-tracker-stale-msg--error">
                Lecture impossible : {{ staleError }}
            </p>
            <p v-else-if="staleOrders.length === 0" class="pos-tracker-stale-msg">Aucune commande en souffrance.</p>

            <ul v-else class="pos-tracker-stale-list">
                <li v-for="o in staleOrders" :key="o.id" class="pos-tracker-stale-row" :data-testid="`tracker-stale-row-${o.id}`">
                    <span class="pos-tracker-stale-serial keep-latin">{{ o.queue_number ? 'N°' + o.queue_number : '#' + (o.order_serial_no || o.id) }}</span>
                    <span class="pos-tracker-stale-date">{{ o.order_datetime }}</span>
                    <span class="pos-tracker-stale-status">{{ staleStatusLabel(o.status) }}</span>
                    <span class="pos-tracker-stale-total">{{ formatPrice(o.total) }}</span>
                    <span v-if="o.is_sealed" class="pos-tracker-stale-sealed" title="Clôturée dans un Z : annulation impossible (NF525)">
                        <i class="fa-solid fa-lock" aria-hidden="true"></i> Clôturé
                    </span>
                    <span class="pos-tracker-stale-actions">
                        <router-link
                            :to="{ name: 'admin.pos-orders.show', params: { id: o.id } }"
                            class="pos-tracker-card-btn"
                            :title="$t('pos.tracker.view_details')"
                        ><i class="fa-solid fa-eye" aria-hidden="true"></i></router-link>
                        <button
                            v-if="!cancelBlockedReason(o)"
                            type="button"
                            class="pos-tracker-card-btn pos-tracker-card-btn--danger"
                            :data-testid="`tracker-stale-cancel-${o.id}`"
                            :title="$t('pos.cancel_order_hint')"
                            @click="openCancelDialog(o)"
                        ><i class="fa-solid fa-ban" aria-hidden="true"></i></button>
                        <button
                            v-else-if="cancelBlockedReason(o) === 'sealed' && canRefundSealed"
                            type="button"
                            class="pos-tracker-card-btn pos-tracker-card-btn--danger"
                            :data-testid="`tracker-stale-refund-${o.id}`"
                            title="Clôturée dans un Z : émettre la contrepartie comptable"
                            @click="openRefundDialog(o)"
                        ><i class="fa-solid fa-rotate-left" aria-hidden="true"></i></button>
                    </span>
                </li>
            </ul>
        </section>

        <div v-if="loading && columns.every(c => c.orders.length === 0)" class="pos-tracker-loading">
            <div class="pos-tracker-spinner" aria-hidden="true"></div>
            <p>{{ $t('pos.tracker.loading') }}</p>
        </div>

        <!--
          [GOAL-CAISSE-VISION 2026-08-24] Panneau « Voir tout » — le contenu COMPLET
          d'une commande, produits ET personnalisations, en français lisible.

          POURQUOI UN PANNEAU ET PAS UNE NAVIGATION : la fiche détail existe
          (`/admin/pos-orders/show/:id`) mais depuis `/admin/pos-v4` elle coûte un
          RECHARGEMENT COMPLET de page (`pos-app.js:118-140` la déclare en
          `window.location.assign`). En plein service, avec un client au comptoir,
          c'est le geste qu'on ne fait pas. Ce panneau lit les données DÉJÀ en
          mémoire : ZÉRO appel réseau, ouverture instantanée, Échap pour fermer.
        -->
        <div
            v-if="contenuDialog.open"
            class="pos-tracker-contenu-overlay"
            role="dialog"
            aria-modal="true"
            aria-labelledby="pos-tracker-contenu-title"
            data-testid="tracker-contenu-overlay"
            @click.self="fermerContenu"
        >
            <div class="pos-tracker-contenu-card">
                <header class="pos-tracker-contenu-head">
                    <h3 id="pos-tracker-contenu-title">
                        <span class="pos-tracker-contenu-num">{{ numeroCommande(commandeAffichee) }}</span>
                        <span v-if="customerLabel(commandeAffichee)" class="pos-tracker-contenu-client">
                            — {{ customerLabel(commandeAffichee) }}
                        </span>
                    </h3>
                    <button
                        type="button"
                        ref="contenuCloseBtn"
                        class="pos-tracker-contenu-close"
                        :aria-label="$t('button.close')"
                        data-testid="tracker-contenu-close"
                        @click="fermerContenu"
                    >
                        <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                    </button>
                </header>

                <!-- Repères d'identification : canal, heure, montant. Le caissier
                     reconnaît d'abord la commande, puis en lit le contenu. -->
                <p class="pos-tracker-contenu-meta" v-if="commandeAffichee">
                    <span class="pos-tracker-contenu-canal">
                        {{ sourceIcon(commandeAffichee) }} {{ sourceLabel(commandeAffichee) }}
                    </span>
                    <span>{{ formatTime(commandeAffichee.created_at) }}</span>
                    <!-- Un « voir tout » doit dire de combien de « tout » il s'agit :
                         sans ce compte, rien n'indique qu'il faut faire défiler. -->
                    <span data-testid="tracker-contenu-compte">{{ compteArticles(commandeAffichee) }}</span>
                    <span class="pos-tracker-contenu-total">
                        {{ formatPrice(commandeAffichee.total ?? commandeAffichee.total_amount_price ?? commandeAffichee.order_amount) }}
                    </span>
                </p>

                <div class="pos-tracker-contenu-body">
                    <ol class="pos-tracker-contenu-lignes" data-testid="tracker-contenu-lignes">
                        <li
                            v-for="(item, idx) in lignesCompletes(commandeAffichee)"
                            :key="idx"
                            class="pos-tracker-contenu-ligne"
                            :data-testid="`tracker-contenu-ligne-${idx}`"
                        >
                            <p class="pos-tracker-contenu-produit">
                                <span class="pos-tracker-contenu-qty">{{ item.quantity || 1 }}×</span>
                                <span class="pos-tracker-contenu-nom">{{ nomProduit(item) }}</span>
                            </p>

                            <!-- Les choix du client (pain, viande, sauce, cuisson…). -->
                            <ul v-if="(item.options || []).length" class="pos-tracker-contenu-detail">
                                <li v-for="(o, i) in item.options" :key="'o' + i" data-testid="tracker-contenu-option">
                                    <span class="pos-tracker-contenu-label" v-if="o.label">{{ o.label }} :</span>
                                    <span>{{ o.value }}</span>
                                    <span v-if="o.quantity > 1" class="pos-tracker-contenu-mult">×{{ o.quantity }}</span>
                                </li>
                            </ul>

                            <p v-if="(item.extras || []).length" class="pos-tracker-contenu-ligne-extras" data-testid="tracker-contenu-extras">
                                <span class="pos-tracker-contenu-label">{{ $t('label.extras') }} :</span>
                                <span>{{ listeNommee(item.extras) }}</span>
                            </p>

                            <p v-if="(item.addons || []).length" class="pos-tracker-contenu-ligne-addons" data-testid="tracker-contenu-addons">
                                <span class="pos-tracker-contenu-label">{{ $t('label.addons') }} :</span>
                                <span>{{ listeNommee(item.addons) }}</span>
                            </p>

                            <!-- L'instruction libre porte les allergies : jamais tronquée ici. -->
                            <p v-if="item.instruction" class="pos-tracker-contenu-instruction" data-testid="tracker-contenu-instruction">
                                <span aria-hidden="true">⚠️</span> {{ item.instruction }}
                            </p>
                        </li>
                    </ol>

                    <p v-if="!lignesCompletes(commandeAffichee).length" class="pos-tracker-contenu-vide" data-testid="tracker-contenu-vide">
                        Le détail de cette commande n'a pas encore été chargé.
                    </p>
                </div>

                <footer class="pos-tracker-contenu-foot">
                    <router-link
                        v-if="commandeAffichee"
                        :to="{ name: 'admin.pos-orders.show', params: { id: commandeAffichee.id } }"
                        class="pos-tracker-contenu-fiche"
                        data-testid="tracker-contenu-fiche"
                    >
                        {{ $t('pos.tracker.view_details') }}
                    </router-link>
                    <button type="button" class="pos-tracker-contenu-ok" @click="fermerContenu">
                        {{ $t('button.close') }}
                    </button>
                </footer>
            </div>
        </div>

        <!--
          [POS-V4-CASHIER-OPS 2026-05-02] Cancel-order dialog.
          Custom inline dialog (not using bootstrap modal) so we keep full
          control of the textarea focus/validation lifecycle. Click on
          backdrop dismisses; Esc dismisses (handled at element level).
        -->
        <div
            v-if="cancelDialog.open"
            class="pos-tracker-cancel-overlay"
            role="dialog"
            aria-modal="true"
            :aria-labelledby="'pos-tracker-cancel-title'"
            @click.self="closeCancelDialog"
            @keydown.esc="closeCancelDialog"
        >
            <div class="pos-tracker-cancel-card">
                <header class="pos-tracker-cancel-head">
                    <h3 id="pos-tracker-cancel-title">{{ $t('pos.cancel_order_title') }}</h3>
                    <button
                        type="button"
                        class="pos-tracker-cancel-close"
                        :aria-label="$t('button.close')"
                        @click="closeCancelDialog"
                    >
                        <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                    </button>
                </header>
                <div class="pos-tracker-cancel-body">
                    <p class="pos-tracker-cancel-target" v-if="cancelDialog.order">
                        <strong>{{ cancelDialog.order.queue_number ? 'N°' + cancelDialog.order.queue_number : '#' + (cancelDialog.order.order_serial_no || cancelDialog.order.id) }}</strong>
                        <span v-if="customerLabel(cancelDialog.order)"> — {{ customerLabel(cancelDialog.order) }}</span>
                        <!-- [iter15-mega-fix B-001/C-002 2026-05-10] Same field-projection mismatch as the card total. -->
                        <span> — {{ formatPrice(cancelDialog.order.total ?? cancelDialog.order.total_amount_price ?? cancelDialog.order.order_amount) }}</span>
                    </p>
                    <label for="pos-tracker-cancel-reason" class="pos-tracker-cancel-label">
                        {{ $t('pos.cancel_order_reason_label') }}
                    </label>
                    <!-- [CAISSE-WEB-INTEL 2026-08-06] Raisons 1-geste : pré-remplissent le
                         textarea (modifiable) — le refus d'une commande web ne doit pas
                         coûter une saisie clavier en plein rush. Backend inchangé. -->
                    <div class="pos-tracker-cancel-chips" data-testid="tracker-cancel-chips">
                        <button
                            v-for="preset in cancelReasonPresets"
                            :key="preset"
                            type="button"
                            class="pos-tracker-cancel-chip"
                            :class="{ 'is-active': cancelDialog.reason === preset }"
                            @click="cancelDialog.reason = preset"
                        >
                            {{ preset }}
                        </button>
                    </div>
                    <textarea
                        id="pos-tracker-cancel-reason"
                        ref="cancelReasonInput"
                        v-model="cancelDialog.reason"
                        rows="3"
                        maxlength="700"
                        :placeholder="$t('pos.cancel_order_reason_placeholder')"
                        class="pos-tracker-cancel-textarea"
                        data-testid="tracker-cancel-reason"
                    ></textarea>
                    <!--
                      [test-e2e/pos-kds-sync round-4 E-001 P0 2026-05-10]
                      Persistent error banner inside the cancel dialog.
                      Mirrors KDS C-001 pattern: toast fires for screen-reader
                      attention, but the banner is the DURABLE visual evidence
                      that survives adversarial capture timing (no fade).
                      role="alert" + aria-live="assertive" forces SR announce;
                      the banner stays visible until user dismisses or closes
                      the dialog (closeCancelDialog clears `error`).
                    -->
                    <div
                        v-if="cancelDialog.error"
                        class="pos-tracker-cancel-error-banner"
                        role="alert"
                        aria-live="assertive"
                        data-testid="tracker-cancel-error-banner"
                    >
                        <i class="fa-solid fa-circle-exclamation pos-tracker-cancel-error-icon" aria-hidden="true"></i>
                        <span class="pos-tracker-cancel-error-msg">{{ cancelDialog.error }}</span>
                    </div>
                </div>
                <footer class="pos-tracker-cancel-foot">
                    <button
                        type="button"
                        class="pos-tracker-cancel-btn pos-tracker-cancel-btn--ghost"
                        @click="closeCancelDialog"
                        :disabled="cancelDialog.busy"
                    >
                        {{ $t('pos.cancel_order_cancel') }}
                    </button>
                    <button
                        type="button"
                        class="pos-tracker-cancel-btn pos-tracker-cancel-btn--danger"
                        :disabled="cancelDialog.busy"
                        :aria-busy="cancelDialog.busy"
                        data-testid="tracker-cancel-confirm"
                        @click="confirmCancelOrder"
                    >
                        <i v-if="cancelDialog.busy" class="fa-solid fa-spinner fa-spin" aria-hidden="true"></i>
                        {{ $t('pos.cancel_order_confirm') }}
                    </button>
                </footer>
            </div>
        </div>

        <!--
          [POS-V4-CASHIER-OPS 2026-05-02] Hidden ReceiptComponent for one-click
          reprints from the tracker. Uses the same existing #receiptModal so
          the existing print buttons (kitchen + client) remain authoritative
          for the actual paper output.
        -->
        <ReceiptComponent v-if="reprintOrder && reprintOrder.id" :order="reprintOrder" />

        <!-- [GOAL-2026-05-29 DEAD-BUTTON-FIX] Shared counter-collect modal so the
             tracker's "Encaisser" CTA actually opens the encashment flow
             (previously a dead button — un-listened CustomEvent only). -->
        <PosCounterCollectModal
            :order="encaisseOrder"
            @confirmed="onEncaisseConfirmed"
            @cancel="encaisseOrder = null"
        />

        <!-- [BOUTON SCELLÉ 2026-08-19] Contrepartie comptable d'une commande scellée.
             Composant EXISTANT réutilisé tel quel (PosRefundModal) : il porte déjà la clé
             d'idempotence, la validation du motif et l'appel à refund-with-counter-entry. -->
        <PosRefundModal
            :order="refundOrder"
            @close="refundOrder = null"
            @refunded="onRefunded"
        />

        <!-- [OWNER REPAS-PERSONNEL/PERTES 2026-07-31] Sortie de stock hors-vente. -->
        <PosStockOutflowModal :open="stockOutflowOpen" @close="stockOutflowOpen = false" />
        <PromoFlyerQuickModal
            :open="promoFlyerOpen"
            :prefill-name="promoFlyerPrefill"
            @close="promoFlyerOpen = false"
        />
    </section>
</template>

<script>
import axios from 'axios';
import orderStatusEnum from '../../../enums/modules/orderStatusEnum';
// [CAISSE-WEB-INTEL 2026-08-06] Enums canoniques pour les badges paiement/type
// (payé en ligne CB vs à encaisser ; livraison vs à emporter).
import paymentStatusEnum from '../../../enums/modules/paymentStatusEnum';
import orderTypeEnum from '../../../enums/modules/orderTypeEnum';
import { onEvents } from '../../../services/eventContract';
import alertService from '../../../services/alertService';
// [OWNER 2026-08-19] Rythme de la sonnerie d'arrivée — partagé avec la caisse, l'écran
// cuisine et l'écran de statut. Trois copies du rythme finiraient par diverger.
import { creerSequenceurDeSonnerie } from '../../../helpers/orderArrivalChime';
// [T-SUIVI-MINUIT 2026-08-19 · GOAL owner] Journée de SERVICE plutôt que jour
// calendaire : une commande de 23 h 50 ne doit pas disparaître à minuit.
import { serviceDayRange } from '../../../helpers/posServiceDay';
import appService from '../../../services/appService';
import ConnectionStatusBanner from '../../common/ConnectionStatusBanner.vue';
import ReceiptComponent from './ReceiptComponent.vue';
// [BOUTON SCELLÉ 2026-08-19] Contrepartie NF525 d'une commande enfermée dans un Z clos.
import PosRefundModal from './PosRefundModal.vue';
// [GOAL-2026-05-29 DEAD-BUTTON-FIX] Shared counter-collect modal — the tracker
// must be self-sufficient for encashment (its Encaisser CTA was previously a
// dead button: it only dispatched an un-listened CustomEvent).
import PosCounterCollectModal from './PosCounterCollectModal.vue';
import PosSystemHealthPill from './PosSystemHealthPill.vue';
import PosStockOutflowModal from './PosStockOutflowModal.vue';
import PromoFlyerQuickModal from '../promo/PromoFlyerQuickModal.vue';
// [WT-D-R1-F4 2026-05-20] Shared admin FR EUR price formatter — canonical
// "19,00 €" rendering shared with PosOrderList / PosOrderShow.
import { adminPriceMixin } from '../../../helpers/formatPrice';

const POLL_WS_MS = 60000;
// [MULTI-DEVICE 2026-08-07] 8 s → 5 s sur demande du propriétaire, qui accepte
// désormais des commandes depuis plusieurs terminaux : quand le temps réel est
// indisponible (worker de file arrêté, socket coupée), deux caisses doivent se
// voir l'une l'autre en 5 s maximum. Ce chemin n'est PAS le cas nominal — avec
// le temps réel opérationnel, un événement arrive en moins d'une seconde et la
// cadence lente ci-dessus ne sert que de filet.
const POLL_NO_WS_MS = 5000;
// [S2 F1 révisé 2026-07-29] Le compteur d'anciennes commandes à encaisser tape un
// endpoint lourd (OrderDetailsResource, ~1,3 s) : 5 min de TTL, jamais la cadence
// du poll dégradé.
const OLDER_PENDING_TTL_MS = 300000;
const FRESH_HIGHLIGHT_MS = 6000;
// [UX-TRACKER-02/POSPERF-09 2026-07-22] Event-staleness escape hatch.
// `realtimeConnected` only proves the SOCKET is up (soketi alive) — NOT that
// broadcasts are delivered (queue worker can be dead while the socket stays
// "connected"). If no realtime order event has landed for EVENT_STALE_MS, or
// the board is empty, we fall back to the fast poll cadence — freshness
// parity with PosComponent which already has this escape hatch.
const EVENT_STALE_MS = 35000;
// [IMP-AGING 2026-07-22] Cash-pending card aging — the "À encaisser" lane is
// the cashier's action queue; a card waiting >5 min turns orange, >10 min
// red + pulse. Ages are recomputed on a light 30s ticker (single interval,
// cleaned up in beforeUnmount) which also doubles as the poll-cadence
// watchdog (the setInterval cadence is otherwise only re-read on ws
// connect/disconnect — without the watchdog, staleness detected mid-flight
// would never shorten an already-running 60s timer).
const AGE_TICK_MS = 30000;
const AGE_AGING_MIN = 5;
const AGE_URGENT_MIN = 10;
// [CAISSE-WEB-INTEL 2026-08-06] Commande PROGRAMMÉE (scheduled_at futur) :
// tant que now < scheduled_at − lead, elle n'est PAS en retard — l'aging
// 5/10 min ne s'applique pas. Miroir du lead KDS (config kds.scheduled_
// lead_minutes, défaut 20) — constante côté front, le backend reste SSOT
// pour la libération cuisine.
const SCHEDULED_LEAD_MIN = 20;

/**
 * [POS-V4-ORDERS-TRACKER 2026-05-02]
 * Écran caisse plein écran. Kanban 4 colonnes : ACCEPT, PREPARING, PREPARED, DELIVERED.
 * Données : `admin/pos-order` (store posOrder/lists). Live: Echo `branch.{branchId}` —
 * mêmes events que PosComponent (OrderCreated, OrderStatusChanged, OrderPaidAtCounter)
 * pour cohérence cross-surface. Stock/availability sont gérés ailleurs (PosComponent).
 */
export default {
    name: 'PosOrdersTrackerComponent',
    components: { ConnectionStatusBanner, ReceiptComponent, PosCounterCollectModal, PosSystemHealthPill, PosStockOutflowModal, PromoFlyerQuickModal, PosRefundModal },
    mixins: [adminPriceMixin],
    data() {
        return {
            loading: false,
            // [OWNER REPAS-PERSONNEL/PERTES 2026-07-31] Modale sortie de stock hors-vente.
            stockOutflowOpen: false,
            promoFlyerOpen: false,
            // [BOUTON SCELLÉ 2026-08-19] Commande dont on émet la contrepartie (null = fermé).
            refundOrder: null,
            // [COMMANDES EN SOUFFRANCE 2026-08-19] Non terminées ANTÉRIEURES à la journée de
            // service : invisibles depuis la fenêtre glissante du tableau. 577 en base au
            // 2026-08-19, dont 486 payées. Chargées À LA DEMANDE — les fondre dans les voies
            // noierait les commandes du service en cours.
            staleOpen: false,
            staleLoading: false,
            staleOrders: [],
            staleMeta: { count: 0, shown: 0, truncated: false },
            staleError: '',
            promoFlyerPrefill: '',
            orders: [],
            filters: {
                query: '',
                source: 'all',
            },
            enums: { orderStatusEnum },
            newReadyIds: new Set(),
            _eventSub: null,
            _pollTimer: null,
            // [PERF SYNC 2026-07-31] Debounce trailing + garde in-flight. Les events WS
            // (OrderCreated/OrderStatusChanged/OrderPaidAtCounter) arrivent en rafale — un seul
            // encaissement emet StatusChanged ET PaidAtCounter → sans coalescing, 2 fetchOrders
            // complets (per_page 100) par geste, ~5-6 par cycle de vie. Mirroir du _debouncedRefresh KDS.
            _refreshTimeout: null,
            _fetchInFlight: false,
            _refetchQueued: false,
            _onWsConnected: null,
            _onWsDisconnected: null,
            realtimeConnected: !!(window._wsService?.isConnected()),
            _freshTimers: Object.create(null),
            // [T-B ALERTE-WEB 2026-08-16 · GOAL owner] 1 seul bip de 0,4s passait
            // inaperçu ("je ne détecte même pas une commande site web"). Séquence de
            // 3 bips espacés 10s pour les commandes web, façon Uber Eats.
            _webOrderAlertTimers: [],
            // [UX-TRACKER-02/POSPERF-09 2026-07-22] Timestamp of the last
            // realtime order event actually DELIVERED (bumped by every Echo
            // handler + on ws (re)connect as a grace period). Init = boot time
            // so a fresh mount is not instantly declared stale.
            lastEventAt: Date.now(),
            // [UX-TRACKER-02/POSPERF-09 2026-07-22] Cadence (ms) the running
            // poll timer was armed with — lets the 30s watchdog detect that
            // `_pollInterval()` now disagrees with the live timer.
            _pollTimerMs: 0,
            // [UX-TRACKER-02b 2026-07-22] Poll-diff freshness: ids already
            // seen on the board (seeded silently on the first successful
            // fetch so the initial board load doesn't flash every card).
            _seenOrderIds: new Set(),
            _seenSeeded: false,
            // [IMP-AGING 2026-07-22] Reactive "now" bumped every 30s so the
            // aging classes/labels re-render without per-card timers.
            ageTick: Date.now(),
            _ageTimer: null,
            // [POS-V4-CASHIER-OPS 2026-05-02] One-click reprint state. Holds
            // the full hydrated order to feed ReceiptComponent. We keep a
            // single instance — only the most recent reprint is rendered.
            reprintOrder: {},
            reprintBusyId: null,
            // [POS-V4-CASHIER-OPS 2026-05-02] Cancel-with-reason dialog state.
            cancelDialog: {
                open: false,
                order: null,
                reason: '',
                error: '',
                busy: false,
            },
            // [GOAL-CAISSE-VISION 2026-08-24] Panneau « Voir tout » : le contenu
            // complet d'une commande. Ne porte QUE la commande déjà en mémoire —
            // aucun chargement, donc aucun état `busy`/`error` à gérer.
            contenuDialog: {
                open: false,
                order: null,
            },
            // [GOAL-2026-05-29 DEAD-BUTTON-FIX] Order currently being encashed
            // via the shared PosCounterCollectModal (null = modal closed).
            encaisseOrder: null,
            // [WEB-TRACKER-VISIBILITY 2026-07-20] Anti double-clic par commande
            // pour le CTA « Accepter » des commandes web PENDING.
            webAccepting: {},
            // [CAISSE-WEB-INTEL 2026-08-06] Temps de préparation choisi par
            // commande web avant l'accept (défaut 15 min = défaut settings).
            webPrepChoice: {},
            // [S2 F1 révisé 2026-07-29] Nombre de commandes à encaisser antérieures
            // au jour affiché (bandeau → /admin/encaissement). Cf. _refreshOlderPendingCount.
            olderPendingCount: 0,
            _olderPendingFetchedAt: 0,
            // [CAISSE-WEB-INTEL 2026-08-06] Alerte sonore nouvelle commande
            // web/borne : ids déjà signalés (exactement-une-fois entre les
            // chemins Echo→fetch et poll→fetch — le hook vit dans le poll-diff
            // de fetchOrders, unique point de vérité des « nouveaux » ids).
            _notifiedOrderIds: new Set(),
            _audioCtx: null,
            // Titre d'onglet original — restauré au démontage.
            _baseDocTitle: '',
        };
    },
    computed: {
        /**
         * [RED-TEAM 2026-08-19] La fenêtre de chargement couvre-t-elle DEUX jours civils ?
         * Vrai uniquement entre minuit et l'heure de bascule du service — c'est là que le
         * mot « aujourd'hui » deviendrait faux. Recalculé à chaque rendu, sans état stocké,
         * donc jamais périmé au passage de 5 h.
         */
        windowSpansTwoDays() {
            const bornes = serviceDayRange();

            return bornes.from !== bornes.to;
        },
        /**
         * [BOUTON MENTEUR 2026-08-19] Pourquoi cette commande ne peut-elle PAS être annulée
         * depuis CE compte ? Rend une clé, ou null si l'annulation est réellement possible.
         *
         * Deux refus existent côté serveur, et les DEUX laissaient un bouton affiché :
         *  · `sealed` — la commande est enfermée dans un Z clos : NF525 interdit de la muter,
         *    la sortie légitime est la contrepartie comptable ;
         *  · `refund_right` — la commande est PAYÉE : l'annuler REND l'argent
         *    (PosOrderController::changeStatus étend la garde `pos-refund` à CANCELED/REJECTED
         *    dès que payment_status = PAYÉ). Le rôle Caissier ne porte pas ce droit — refus
         *    DÉLIBÉRÉ et documenté (vecteur de remboursement de masse). Le bouton, lui, ne le
         *    disait pas : clic → 403 sans explication.
         *
         * On ne CONTOURNE aucune garde ici : on cesse de promettre ce que le serveur refusera.
         * Rendu comme une fonction (et non un computed par ligne) : le tableau affiche jusqu'à
         * 100 cartes et se rafraîchit toutes les 5 s.
         */
        cancelBlockedReason() {
            const peutRembourser = this.canRefundSealed;
            return (order) => {
                if (!order) return null;
                if (order.is_sealed) return 'sealed';
                if (Number(order.payment_status) === paymentStatusEnum.PAID && !peutRembourser) {
                    return 'refund_right';
                }
                return null;
            };
        },
        /**
         * [BOUTON SCELLÉ 2026-08-19] Ce compte peut-il émettre une contrepartie ?
         * Même vérificateur que PosOrderShowComponent (`permissionChecker('pos-refund')`) :
         * l'endpoint est gardé par cette permission côté serveur, la refléter ici évite un
         * SECOND bouton mort là où on vient justement d'en supprimer un.
         */
        canRefundSealed() {
            try {
                return !!appService.permissionChecker('pos-refund');
            } catch (e) {
                return false;
            }
        },
        // [WEB-ORDER-ACCEPT 2026-07-30 · décision owner + parité PosComponent.canProcessWebOrders]
        // Le CTA « Accepter » d'une commande web POST vers online-order/change-status (gardé
        // `permission:online-orders`). On garde le bouton sur CETTE permission : sinon un rôle
        // portant `pos` mais pas `online-orders` voyait un bouton MORT (403 au clic — défaut audit
        // « gestion » 2026-07-30). Le rôle Caissier (POS Operator) reçoit désormais cette permission
        // (seeder + migration) → le bouton s'affiche ET fonctionne pour lui ; la garde protège tout
        // futur rôle pos-only contre la réapparition du bouton mort.
        canProcessWebOrders() {
            const raw = this.$store.getters.authPermission;
            const perms = Array.isArray(raw) ? raw : (raw && Array.isArray(raw.data) ? raw.data : []);
            const entry = perms.find((p) => p && p.url === 'online-orders');
            return !!(entry && entry.access === true);
        },
        // [OWNER 2026-08-13 « ameliore l'acces du caissier »] Même défaut que
        // `canProcessWebOrders` ci-dessus : le bouton « Ticket promo » s'affichait pour tout
        // le monde alors que l'endpoint (`store`/`reprint`) était gardé `coupons_create|settings`
        // (Admin uniquement) — clic → 403 silencieux. La permission `pos-flyer-print` est
        // désormais accordée au rôle Caissier (POS Operator) et Branch Manager (seeder +
        // migration) ; ce garde protège tout futur rôle qui ne l'aurait pas.
        canPrintFlyer() {
            const raw = this.$store.getters.authPermission;
            const perms = Array.isArray(raw) ? raw : (raw && Array.isArray(raw.data) ? raw.data : []);
            const entry = perms.find((p) => p && p.url === 'pos-flyer-print');
            return !!(entry && entry.access === true);
        },
        // [iter15-mega-fix B-003/C-008 2026-05-10] Hide the local
        // `pos-tracker-rt-warn` realtime banner in dev/local where Pusher/Soketi
        // is not running. Mirrors ConnectionStatusBanner.vue isDevEnv gate.
        isDevEnv() {
            try {
                const env = (typeof window !== 'undefined' && window.foodkingConfig?.appEnv) || '';
                return env === 'local' || env === 'testing';
            } catch (_e) {
                return false;
            }
        },
        /**
         * [GOAL-CAISSE-VISION 2026-08-24] La commande montrée par le panneau
         * « Voir tout », TOUJOURS dans sa version la plus fraîche.
         *
         * Le panneau reste ouvert pendant que le suivi se rafraîchit (5 s). S'il
         * gardait la référence capturée à l'ouverture, un caissier lisant le
         * contenu pendant qu'une ligne est modifiée verrait un état PÉRIMÉ — et
         * rien à l'écran ne le lui dirait. On re-résout donc par id à chaque
         * rendu, et on ne retombe sur l'instantané d'ouverture QUE si la commande
         * a quitté le tableau (encaissée, livrée) : mieux vaut un contenu figé et
         * lisible qu'un panneau qui se vide sous les yeux.
         */
        commandeAffichee() {
            const capturee = this.contenuDialog.order;
            if (!capturee) return null;
            const vivante = this.orders.find((o) => String(o.id) === String(capturee.id));
            return vivante || capturee;
        },
        sourceTabs() {
            const base = [
                { id: 'all', icon: '🧾', label: this.$t('pos.tracker.source_all') },
                { id: 'pos', icon: '🛒', label: this.$t('pos.tracker.source_pos') },
                { id: 'kiosk', icon: '🖥️', label: this.$t('pos.tracker.source_kiosk') },
                { id: 'online', icon: '🌐', label: this.$t('pos.tracker.source_online') },
            ];

            // [GOAL-CAISSE-VISION 2026-08-24] Trois canaux réels n'avaient pas d'onglet :
            // téléphone, plateforme, livraison — ils étaient TOUS filtrés sous « Caisse ».
            // On ne les ajoute QUE s'ils sont réellement présents sur le tableau : en
            // service normal la barre reste courte, et le jour où une commande téléphone
            // arrive, son onglet apparaît de lui-même. Un onglet qui ne filtre rien est
            // un onglet qui encombre.
            const presents = new Set(this.orders.map((o) => this.sourceOf(o)));
            [
                { id: 'phone', icon: '📞', label: this.$t('pos.tracker.source_phone') },
                { id: 'platform', icon: '🛵', label: this.$t('pos.tracker.source_platform') },
                { id: 'delivery', icon: '🚗', label: this.$t('pos.tracker.source_delivery') },
            ].forEach((tab) => {
                if (presents.has(tab.id)) base.push(tab);
            });

            return base;
        },
        filteredOrders() {
            const q = this.filters.query.toLowerCase();
            const src = this.filters.source;
            return this.orders.filter((o) => {
                if (src !== 'all' && this.sourceOf(o) !== src) return false;
                if (q) {
                    const hay = String(o.queue_number || '') + ' ' + String(o.order_serial_no || '') + ' ' + String(o.user?.name || '') + ' ' + String(o.user?.first_name || '');
                    if (!hay.toLowerCase().includes(q)) return false;
                }
                return true;
            });
        },
        ordersByStatus() {
            const buckets = { accept: [], preparing: [], prepared: [], onTheWay: [], delivered: [] };
            for (const o of this.filteredOrders) {
                const s = parseInt(o.order_status ?? o.status ?? 0, 10);
                // [SYNC gateway-refund coherence 2026-08-05] Une commande REMBOURSÉE par la banque
                // (gateway Mollie/Stripe) garde souvent status=PREPARING mais payment_status=REFUNDED :
                // le KDS/OSS la retirent (KitchenReleaseRule::applyBoardReleaseFilter exclut REFUNDED)
                // mais ce tracker, bucketé par STATUT, la montrait « en préparation » un-bumpable =
                // carte ORPHELINE sur la caisse. On l'exclut des voies ACTIVES (miroir exact du
                // board-release) — elle reste en base/historique. isTerminalStatus() ne l'attrape pas
                // (le STATUT n'est pas terminal ; c'est le PAIEMENT qui l'est).
                if (this.isRefunded(o)) continue;
                // [Wave S-4 P-OWNER 2026-05-20] The ACCEPT lane is now reserved
                // for cash-pending kiosk orders ONLY. With Wave S-1 auto-PREPA
                // active, every paid order skips ACCEPT entirely and lands in
                // PREPARING — so the only orders that legitimately remain at
                // ACCEPT are kiosk paid-at-counter orders waiting for cashier
                // collection. Backend exposes `is_cash_pending` via
                // SimpleOrderResource (PENDING_COUNTER + COUNTER_DEFERRED).
                // Anything else lingering at ACCEPT is dropped from the view
                // to avoid muddying the cashier's "À encaisser" signal — those
                // orders still appear in the EN PRÉPARATION column once
                // S-1 auto-promotes them (which happens at payment time).
                // [S2 F1 2026-07-29] Une commande cash-pending appartient à la voie
                // « À encaisser » QUEL QUE SOIT son statut cuisine. Repro : 5 commandes
                // PREPARED + PENDING_COUNTER visibles dans /admin/encaissement mais
                // « À ENCAISSER = 0 » ici, car le prédicat cash-pending n'était évalué
                // que sous status===ACCEPT. Le signal argent prime sur le statut cuisine
                // (l'avancement cuisine reste visible sur le KDS).
                //
                // [S2 auto-RED 2026-07-29] MAIS jamais un statut TERMINAL : une commande
                // annulée/rejetée/remboursée garde `payment_status=PENDING_COUNTER` en base
                // (30 lignes constatées) alors que `confirmCounterPayment` la REFUSE — sans
                // ce garde on recréait la « carte fantôme incaissable » déjà fermée côté
                // backend (routes/api.php, `whereNotIn('status', [CANCELED,REJECTED,RETURNED])`
                // de counter-collect/pending). On miroite exactement ce set terminal.
                if (this.isCashPending(o) && !this.isTerminalStatus(s)) {
                    buckets.accept.push(o);
                    continue;
                }
                if (s === orderStatusEnum.ACCEPT) {
                    {
                        // [GOAL-2026-05-29 TRACKER-CONTINUITY-FIX] A paid order
                        // still at ACCEPT is the CASH counter-collect case: the
                        // S-5 carve-out (AutoPrepareOnPaidPolicy::shouldPromote
                        // === false for isCounterCollect+CASH) intentionally does
                        // NOT auto-promote it to PREPARING — it waits for the chef
                        // to bump it on the KDS, which DOES show it (KitchenRelease
                        // Rule: PAID → released, "Prêt" action). The old code
                        // assumed every paid order auto-promotes and silently
                        // DROPPED this one → the paid order VANISHED from the
                        // tracker board while the kitchen was still cooking it.
                        // Surface it in the kitchen-active lane so the tracker
                        // stays consistent with the KDS.
                        buckets.preparing.push(o);
                    }
                }
                // [WEB-TRACKER-VISIBILITY 2026-07-20] Une commande WEB arrive PENDING (UNPAID,
                // source_surface='web') et n'était bucketée NULLE PART → invisible dans
                // « commandes en cours » (plainte owner : commande web passée, introuvable).
                // Elle rejoint la voie « À encaisser » avec un CTA « Accepter » qui réutilise
                // le chemin EXISTANT OnlineOrderController::changeStatus (=ACCEPT → bascule
                // PENDING_COUNTER+COUNTER_DEFERRED, même flux que le panneau web de la caisse
                // C1 2026-07-18). Après acceptation elle reste dans la même voie en cash-pending
                // (Encaisser) — continuité visuelle totale du cycle web.
                else if (s === orderStatusEnum.PENDING && this.isWebPending(o)) buckets.accept.push(o);
                else if (s === orderStatusEnum.PREPARING) buckets.preparing.push(o);
                else if (s === orderStatusEnum.PREPARED) buckets.prepared.push(o);
                // [Wave T R1 F1 P0 2026-05-20] EN LIVRAISON lane: any order at
                // OUT_FOR_DELIVERY (status=10) — driver has picked it up and is
                // in transit. Previously vanished from tracker for the 30+min
                // delivery window. Lane is delivery-specific by domain (only
                // DELIVERY orders transition through OUT_FOR_DELIVERY) but we
                // filter on status alone — same approach as the other lanes —
                // so any order arriving at this status surfaces here.
                else if (s === orderStatusEnum.OUT_FOR_DELIVERY) buckets.onTheWay.push(o);
                else if (s === orderStatusEnum.DELIVERED) buckets.delivered.push(o);
            }
            // Sort each bucket: oldest first for active queues, newest first for delivered.
            // [CAISSE-WEB-INTEL 2026-08-06] Voie « À encaisser » : tri composite —
            // les web PENDING (seules cartes dont l'INACTION bloque le client
            // distant : ni cuisine ni suivi tant que pas acceptées) remontent
            // devant les cash-pending, puis plus ancien d'abord dans chaque
            // groupe. Les autres voies restent purement chronologiques.
            buckets.accept.sort((a, b) => {
                const aw = this.isWebPending(a) ? 0 : 1;
                const bw = this.isWebPending(b) ? 0 : 1;
                if (aw !== bw) return aw - bw;
                return this._tsOf(a) - this._tsOf(b);
            });
            buckets.preparing.sort((a, b) => this._tsOf(a) - this._tsOf(b));
            buckets.prepared.sort((a, b) => this._tsOf(a) - this._tsOf(b));
            buckets.onTheWay.sort((a, b) => this._tsOf(a) - this._tsOf(b));
            buckets.delivered.sort((a, b) => this._tsOf(b) - this._tsOf(a));
            return buckets;
        },
        columns() {
            const b = this.ordersByStatus;
            // [iter15-mega-fix C-024 run-3 2026-05-10] The 'accept' lane maps
            // to OrderStatus::ACCEPT (4) — exactly what the KDS surface labels
            // "Confirmées" via `label.confirmed`. Previously the POS tracker
            // labeled the same lane "À envoyer" so the same order looked like
            // it lived in two different columns across surfaces. The column
            // semantic is unchanged (still ACCEPT=4); only the display label
            // is harmonised in `pos.tracker.col_accept` (fr.json + en.json).
            return [
                {
                    // [Wave S-4 P-OWNER 2026-05-20] Renamed lane "Confirmées" →
                    // "À encaisser". Wave S-1 auto-promotes all paid orders
                    // ACCEPT → PREPARING, so this lane is now exclusively the
                    // cashier's encaissement queue (kiosk paid-at-counter).
                    // The accordion now carries a subtitle clarifying the
                    // semantic, the count badge is the "fresh" pulsating tone
                    // when ≥1 order awaits cash collection, and each card
                    // shows the amount due + an "Encaisser" CTA. The 4-column
                    // layout is preserved per owner directive — empty state
                    // stays visible to signal "all clear".
                    id: 'accept',
                    label: this.$t('pos.tracker.col_accept'),
                    subtitle: this.$t('pos.tracker.col_accept_subtitle'),
                    icon: '🔔',
                    tone: 'amber',
                    highlight: true,
                    orders: b.accept,
                    emptyIcon: '✓',
                    emptyLabel: this.$t('pos.tracker.empty_accept'),
                },
                {
                    id: 'preparing',
                    label: this.$t('pos.tracker.col_preparing'),
                    icon: '🍳',
                    tone: 'primary',
                    orders: b.preparing,
                    emptyIcon: '⏳',
                    emptyLabel: this.$t('pos.tracker.empty_preparing'),
                },
                {
                    id: 'prepared',
                    label: this.$t('pos.tracker.col_prepared'),
                    icon: '🛎️',
                    tone: 'green',
                    highlight: true,
                    orders: b.prepared,
                    emptyIcon: '—',
                    emptyLabel: this.$t('pos.tracker.empty_prepared'),
                },
                // [Wave T R1 F1 P0 2026-05-20] EN LIVRAISON lane inserted before
                // LIVRÉS so the cashier keeps visibility on in-flight delivery
                // orders during the 30+min driver window. Tone 'blue' separates
                // it visually from PRÊTS (green) and LIVRÉS (muted). Backend
                // status code is OrderStatus::OUT_FOR_DELIVERY (10) — set when
                // the driver picks up via DeliveryBoyController, cleared when
                // they tap "Livré" which flips to status=13.
                {
                    id: 'onTheWay',
                    label: this.$t('pos.tracker.col_on_the_way'),
                    icon: '🛵',
                    tone: 'blue',
                    orders: b.onTheWay,
                    emptyIcon: '—',
                    emptyLabel: this.$t('pos.tracker.empty_on_the_way'),
                },
                {
                    id: 'delivered',
                    label: this.$t('pos.tracker.col_delivered'),
                    icon: '✅',
                    tone: 'muted',
                    orders: b.delivered,
                    emptyIcon: '—',
                    emptyLabel: this.$t('pos.tracker.empty_delivered'),
                },
            ];
        },
        stats() {
            const b = this.ordersByStatus;
            return {
                active: b.accept.length + b.preparing.length + b.prepared.length,
                ready: b.prepared.length,
                // [D-2 HEAL 2026-07-24 · reports/audit-sync-gestion-2026-07-23 §D-2]
                // Compteur honnête. `fetchOrders` tire le jour SANS filtre de statut →
                // un PENDING NON-web (panier borne abandonné, commande téléphone/pos
                // naissante, source NULL) entre dans `this.orders` mais n'est bucketé
                // dans AUCUNE voie (seul le web PENDING a la voie « À encaisser »). Il
                // gonflait donc « X aujourd'hui » avec des cartes invisibles (162 en
                // base). On l'exclut : le compteur ne compte plus que ce qui est
                // représentable sur le board. Le web PENDING (actionnable, CTA
                // Accepter) reste compté ; le bucketing est INCHANGÉ (le non-web
                // PENDING reste hors board). Même calcul de statut que ordersByStatus.
                todayCount: this.orders.reduce((n, o) => {
                    const s = parseInt(o.order_status ?? o.status ?? 0, 10);
                    const isPhantomPending = s === orderStatusEnum.PENDING && !this.isWebPending(o);
                    return isPhantomPending ? n : n + 1;
                }, 0),
            };
        },
        // [CAISSE-WEB-INTEL 2026-08-06] Nombre de commandes WEB exigeant une
        // action caissier MAINTENANT : web PENDING (à accepter) + web déjà
        // acceptée en attente d'encaissement. Alimente la pill header 🌐 et le
        // compteur du titre d'onglet — le caissier voit « 3 web à traiter »
        // d'un coup d'œil sans ouvrir le filtre.
        // [CAISSE-WEB-INTEL 2026-08-06] Raisons d'annulation 1-geste (chips du
        // dialog). FR direct (ADR-007) ; le texte reste éditable au clavier.
        cancelReasonPresets() {
            return [
                'Rupture produit',
                'Fermeture imminente',
                'Client injoignable',
                'Erreur de commande',
            ];
        },
        webActionableCount() {
            return this.orders.reduce((n, o) => {
                const s = parseInt(o.order_status ?? o.status ?? 0, 10);
                if (this.isRefunded(o) || this.isTerminalStatus(s)) return n;
                if (this.isWebPending(o)) return n + 1;
                if (this.isCashPending(o) && this.sourceOf(o) === 'online') return n + 1;
                return n;
            }, 0);
        },
    },
    watch: {
        // [CAISSE-WEB-INTEL 2026-08-06] Compteur dans le titre d'onglet : si le
        // tracker est en arrière-plan, « (2) Suivi commandes » reste visible
        // dans la barre d'onglets — complément visuel de l'alerte sonore.
        webActionableCount(n) {
            this._updateDocTitle(n);
        },
    },
    mounted() {
        try { this._baseDocTitle = document.title || ''; } catch (_e) { /* SSR/test */ }
        this._collapseSidebar();
        this.fetchOrders();
        // [COMMANDES EN SOUFFRANCE 2026-08-19] Instantané du compteur au chargement. Ce nombre
        // évolue en heures : le sonder à chaque poll 5 s coûterait 720 requêtes/heure pour une
        // valeur qui ne bouge pas. Il est rafraîchi à l'ouverture du panneau et après action.
        this.fetchStaleOrders();
        this._subscribeEcho();
        this._bindWsService();
        this._startPolling();
        this._startAgeTicker();
        // [GOAL-CAISSE-VISION 2026-08-24] Échap ferme le panneau « Voir tout » où que
        // soit le focus — au comptoir on ferme d'un geste, sans viser une croix.
        try { document.addEventListener('keydown', this._contenuOnKeydown); } catch (_e) { /* SSR/test */ }
    },
    beforeUnmount() {
        try { document.removeEventListener('keydown', this._contenuOnKeydown); } catch (_e) { /* SSR/test */ }
        this._unsubscribeEcho();
        this._unbindWsService();
        this._stopPolling();
        this._stopAgeTicker();
        Object.values(this._freshTimers).forEach((t) => clearTimeout(t));
        // [CAISSE-WEB-INTEL 2026-08-06] Restaure le titre d'onglet original.
        try { if (this._baseDocTitle) document.title = this._baseDocTitle; } catch (_e) { /* defensive */ }
        // [T-B ALERTE-WEB 2026-08-16] Annule les bips programmés en attente — sinon un bip
        // peut sonner après le démontage de l'écran.
        // [OWNER 2026-08-19] Le séquenceur porte désormais ces minuteries ; l'ancien tableau
        // reste vidé pour les contextes fabriqués par les bancs d'essai, qui le renseignent.
        try { this._sequenceurSonnerie?.annuler?.(); } catch (_e) { /* defensive */ }
        this._sequenceurSonnerie = null;
        this._webOrderAlertTimers.forEach((t) => clearTimeout(t));
        this._webOrderAlertTimers = [];
        // [RED heal P3 2026-08-06] Libère l'AudioContext (les navigateurs en
        // plafonnent ~6 par page) + vide la dédup sonore.
        try { this._audioCtx?.close?.(); } catch (_e) { /* defensive */ }
        this._audioCtx = null;
        this._notifiedOrderIds.clear();
    },
    methods: {
        /**
         * [T-SUIVI-LAYOUT 2026-08-19 · GOAL owner] Replie la barre latérale admin,
         * exactement comme le fait déjà la caisse plein écran
         * (`PosComponent.closeSidebar`, PosComponent.vue:4920).
         *
         * Ce tableau est un écran d'exploitation affiché en continu au comptoir :
         * la navigation admin n'y sert à rien et lui volait 260 px de largeur, soit
         * une voie entière sur cinq. C'était le premier facteur du « je dois
         * scroller à gauche et à droite » — mesuré en direct : 1728 px de fenêtre
         * mais seulement 1388 px pour la grille.
         *
         * Non restauré au démontage : c'est le comportement déjà en place côté
         * caisse, et l'utilisateur peut rouvrir la barre d'un clic sur le menu.
         */
        _collapseSidebar() {
            try {
                this.$store.dispatch('globalState/set', { topSidebar: false });
                document?.querySelector('.db-sidebar')?.classList?.add('active');
                document?.querySelector('.db-main')?.classList?.add('expand');
            } catch (_e) { /* défensif : jamais bloquer le chargement du tableau */ }
        },
        authBranchId() {
            const candidates = [
                this.$store.getters['auth/authBranchId'],
                this.$store.getters.authBranchId,
                this.$store.state?.auth?.authBranchId,
            ];
            for (const c of candidates) {
                if (c === '' || c == null) continue;
                const v = parseInt(c, 10);
                if (Number.isFinite(v)) return v;
            }
            return 0;
        },
        _bindWsService() {
            const ws = window._wsService;
            if (!ws) return;
            // [UX-TRACKER-02 2026-07-22] `_noteRealtimeEvent()` on (re)connect =
            // grace period: give the fresh socket EVENT_STALE_MS to prove event
            // delivery before the staleness escape hatch kicks in.
            this._onWsConnected = () => { this.realtimeConnected = true; this._noteRealtimeEvent(); this._restartPolling(); this.fetchOrders(); };
            this._onWsDisconnected = () => { this.realtimeConnected = false; this._restartPolling(); };
            ws.on('connected', this._onWsConnected);
            ws.on('disconnected', this._onWsDisconnected);
        },
        _unbindWsService() {
            const ws = window._wsService;
            if (!ws) return;
            if (this._onWsConnected) ws.off('connected', this._onWsConnected);
            if (this._onWsDisconnected) ws.off('disconnected', this._onWsDisconnected);
        },
        _subscribeEcho() {
            if (!window.Echo) return;
            const branchId = this.authBranchId();
            if (branchId <= 0) return;
            try {
                // [UX-TRACKER-02 2026-07-22] Every delivered order event bumps
                // `lastEventAt` — the ONLY proof that the realtime pipe (queue
                // worker → soketi → Echo) is actually alive end-to-end.
                this._eventSub = onEvents(branchId, [
                    { broadcastAs: 'OrderCreated', handler: () => { this._noteRealtimeEvent(); this._debouncedFetch(); } },
                    {
                        broadcastAs: 'OrderStatusChanged',
                        handler: (event) => {
                            this._noteRealtimeEvent();
                            const data = event?.payload || {};
                            const newStatus = parseInt(data.new_status, 10);
                            const oid = parseInt(data.order_id, 10);
                            if (newStatus === orderStatusEnum.PREPARED && oid) {
                                this._markFresh(oid);
                            }
                            this._debouncedFetch();
                        },
                    },
                    { broadcastAs: 'OrderPaidAtCounter', handler: () => { this._noteRealtimeEvent(); this._debouncedFetch(); } },
                    // [SYNC 2026-08-05] Refund GATEWAY (Mollie/Stripe) → payment_status=REFUNDED SANS
                    // changement de statut → aucun OrderStatusChanged n'était émis, donc le tracker ne
                    // se rafraîchissait qu'au poll (≤60s) et gardait la carte remboursée « en préparation ».
                    // On re-fetch sur le flip paiement → la carte sort des voies actives (isRefunded) en temps-réel.
                    { broadcastAs: 'OrderPaymentStatusChanged', handler: () => { this._noteRealtimeEvent(); this._debouncedFetch(); } },
                ]);
            } catch (e) {
                /* echo auth failed — polling fallback */
            }
        },
        _unsubscribeEcho() {
            try { this._eventSub?.unsubscribe(); } catch (e) { /* defensive */ }
            this._eventSub = null;
        },
        // [UX-TRACKER-02 2026-07-22] Clock seam — every freshness/aging
        // computation goes through _now() so tests can stub time
        // deterministically (no raw Date.now() in tested logic).
        _now() {
            return Date.now();
        },
        _noteRealtimeEvent() {
            this.lastEventAt = this._now();
        },
        _pollInterval() {
            if (!this.realtimeConnected) return POLL_NO_WS_MS;
            // [UX-TRACKER-02/POSPERF-09 2026-07-22] Socket "connected" is NOT
            // proof of event delivery (dead queue worker ⇒ soketi up, 0 events,
            // banner never shows). Fall back to the fast cadence as soon as no
            // event has landed for EVENT_STALE_MS — or the board is empty —
            // freshness parity with PosComponent's existing escape hatch.
            const eventsStale = (this._now() - this.lastEventAt) > EVENT_STALE_MS;
            const boardEmpty = this.orders.length === 0;
            return (eventsStale || boardEmpty) ? POLL_NO_WS_MS : POLL_WS_MS;
        },
        _startPolling() {
            this._stopPolling();
            // Remember the cadence the timer is armed with: the 30s age ticker
            // watchdog compares it against _pollInterval() and re-arms when the
            // staleness state changed mid-flight (setInterval never re-reads it).
            this._pollTimerMs = this._pollInterval();
            this._pollTimer = setInterval(() => this.fetchOrders(), this._pollTimerMs);
        },
        _stopPolling() {
            if (this._pollTimer) { clearInterval(this._pollTimer); this._pollTimer = null; }
        },
        _restartPolling() {
            this._startPolling();
        },
        _markFresh(orderId) {
            const id = parseInt(orderId, 10);
            if (!id) return;
            this.newReadyIds = new Set([...this.newReadyIds, id]);
            if (this._freshTimers[id]) clearTimeout(this._freshTimers[id]);
            this._freshTimers[id] = setTimeout(() => {
                const next = new Set(this.newReadyIds);
                next.delete(id);
                this.newReadyIds = next;
                delete this._freshTimers[id];
            }, FRESH_HIGHLIGHT_MS);
        },
        _tsOf(o) {
            if (!o) return 0;
            const t = o.created_at || o.updated_at;
            if (!t) return 0;
            const v = new Date(t).getTime();
            return Number.isFinite(v) ? v : 0;
        },
        // [IMP-AGING + UX-TRACKER-02 2026-07-22] Single light 30s ticker:
        // 1) bumps the reactive `ageTick` so aging classes/labels re-render;
        // 2) poll-cadence watchdog — if the staleness state changed while a
        //    60s setInterval is mid-flight, re-arm it (and fetch immediately
        //    when downgrading to the fast cadence, to close the gap NOW).
        _startAgeTicker() {
            this._stopAgeTicker();
            this._ageTimer = setInterval(() => this._onAgeTick(), AGE_TICK_MS);
        },
        _stopAgeTicker() {
            if (this._ageTimer) { clearInterval(this._ageTimer); this._ageTimer = null; }
        },
        _onAgeTick() {
            this.ageTick = this._now();
            const want = this._pollInterval();
            if (this._pollTimer && this._pollTimerMs !== want) {
                this._restartPolling();
                if (want === POLL_NO_WS_MS) this.fetchOrders();
            }
        },
        _ageMinutes(o) {
            const ts = this._tsOf(o);
            if (!ts) return 0;
            return Math.floor(Math.max(0, this.ageTick - ts) / 60000);
        },
        // [IMP-AGING 2026-07-22] Age class for the À encaisser lane ONLY (the
        // cashier's action queue): '' | 'tracker-card--aging' (≥5 min, orange)
        // | 'tracker-card--urgent' (≥10 min, red + pulse).
        trackerAgeClass(o, laneId) {
            if (laneId !== 'accept') return '';
            // [CAISSE-WEB-INTEL 2026-08-06] Une commande PROGRAMMÉE pour plus
            // tard n'est pas « en retard » : pas d'orange/rouge tant que
            // now < scheduled_at − lead. Le badge 🕐 porte l'information.
            if (this._scheduledNotYetDue(o)) return '';
            const mins = this._ageMinutes(o);
            if (mins >= AGE_URGENT_MIN) return 'tracker-card--urgent';
            if (mins >= AGE_AGING_MIN) return 'tracker-card--aging';
            return '';
        },
        // i18n-with-fallback: vue-i18n returns the KEY for missing entries, so
        // a plain `|| 'fr'` never triggers — treat "value === key" as missing
        // to avoid a raw-label window until the lang files gain the key.
        _tOr(key, fallback) {
            let v = '';
            try { v = this.$t(key); } catch (_e) { v = ''; }
            return (v && v !== key) ? v : fallback;
        },
        agingLabel(o) {
            const mins = this._ageMinutes(o);
            if (mins < AGE_AGING_MIN) return '';
            return `${this._tOr('pos.tracker.age_ago', 'il y a')} ${mins} min`;
        },
        _debouncedFetch() {
            // [PERF SYNC 2026-07-31] Collapse une rafale d'events WS en un seul fetch (trailing 250ms).
            if (this._refreshTimeout) clearTimeout(this._refreshTimeout);
            this._refreshTimeout = setTimeout(() => { this._refreshTimeout = null; this.fetchOrders(); }, 250);
        },
        async fetchOrders() {
            // [PERF SYNC 2026-07-31] Garde in-flight : si un fetch tourne deja, memoriser la demande
            // et rejouer un seul fetch au retour (etat le plus recent) au lieu d'un doublon concurrent.
            if (this._fetchInFlight) { this._refetchQueued = true; return; }
            this._fetchInFlight = true;
            this.loading = this.orders.length === 0;
            try {
                const today = this._todayRange();
                const res = await this.$store.dispatch('posOrder/lists', {
                    // [POSPERF-07 2026-07-22] `paginate: 1` makes OrderService::list
                    // HONOUR per_page — without it the backend runs ->get('*') and
                    // returns EVERY order of the day (unbounded) with 8 eager
                    // relations each. `lean: 1` swaps the heavy OrderResource
                    // eager-load set (media/category/roles/branch/transaction.order),
                    // which SimpleOrderResource never reads, for the tracker's real
                    // needs (transaction/user/orderItems.orderItem) → lighter payload.
                    // 100 most-recent (id desc) covers every active lane; only stale
                    // DELIVERED rows beyond 100 fall off (they live in the muted lane).
                    paginate: 1,
                    per_page: 100,
                    lean: 1,
                    from_date: today.from,
                    to_date: today.to,
                    vuex: false,
                });
                const data = res?.data?.data || [];
                this.orders = Array.isArray(data) ? data : [];
                // [S2 F1 2026-07-29, révisé par auto-RED cycle 1] Ce fetch ne couvre
                // que le JOUR COURANT alors que la file d'encaissement est all-time :
                // une commande PENDING_COUNTER de la veille reste encaissable dans
                // /admin/encaissement sans apparaître ici.
                //
                // La première version FUSIONNAIT la file counter-collect dans
                // `this.orders`. C'était FAUX : l'endpoint renvoie 191 lignes tous
                // statuts confondus (132 ACCEPT, 24 PREPARED, 32 DELIVERED…), donc
                // (a) la voie « À encaisser » passait de 12 à 191 cartes, (b) les
                // commandes PRÊTES/LIVRÉES quittaient leur colonne — le signal
                // « plat prêt à remettre » disparaissait de la caisse, (c) le
                // compteur « X aujourd'hui » annonçait 218 pour 39, (d) chaque fetch
                // coûtait 1557 requêtes SQL / 1,3 s (OrderDetailsResource), toutes
                // les 8 s en mode dégradé.
                //
                // Le tableau reste donc un board DU JOUR (une seule vérité par écran,
                // DISCIPLINE §9) : on ne rapatrie qu'un COMPTEUR d'anciennes commandes
                // à encaisser, affiché en bandeau qui renvoie vers /admin/encaissement,
                // rafraîchi au plus toutes les 5 min (jamais au rythme du poll).
                this._refreshOlderPendingCount();
                // [UX-TRACKER-02b 2026-07-22] Poll-diff freshness: previously
                // the "fresh" highlight was ONLY driven by Echo events — dead
                // when the queue worker is down. Diff the fetched ids against
                // what the board has already seen and flash every genuinely
                // NEW order. The very first successful fetch seeds silently
                // (no highlight storm on page load).
                const ids = this.orders
                    .map((o) => parseInt(o?.id, 10))
                    .filter((id) => Number.isFinite(id) && id > 0);
                if (!this._seenSeeded) {
                    this._seenSeeded = true;
                    this._seenOrderIds = new Set(ids);
                } else {
                    for (const id of ids) {
                        if (!this._seenOrderIds.has(id)) {
                            this._seenOrderIds.add(id);
                            this._markFresh(id);
                            // [CAISSE-WEB-INTEL 2026-08-06] Alerte sonore+toast
                            // pour une nouvelle commande DISTANTE (web/borne) —
                            // le beep de PosComponent ne vit pas sur cette page.
                            // Branché ici (poll-diff) : fiable même queue-worker
                            // down, et exactement-une-fois (chemins Echo et poll
                            // convergent tous deux vers fetchOrders).
                            const o = this.orders.find((x) => parseInt(x?.id, 10) === id);
                            this._maybeNotifyIncomingOrder(o);
                        }
                    }
                }
            } catch (e) {
                /* surface error sparingly to avoid alert fatigue */
            } finally {
                this.loading = false;
                this._fetchInFlight = false;
                if (this._refetchQueued) { this._refetchQueued = false; this._debouncedFetch(); }
            }
        },
        /**
         * [T-SUIVI-MINUIT 2026-08-19 · GOAL owner] Bornes de la JOURNÉE DE SERVICE.
         *
         * Ce tableau ne chargeait que le jour calendaire : Le Cayenne servant tard,
         * une commande prise à 23 h 50 DISPARAISSAIT à 00 h 00 alors que la cuisine
         * était encore dessus — plus moyen de la suivre, de la marquer livrée ni de
         * l'annuler. C'est l'une des deux causes du « je n'arrive pas à annuler les
         * commandes passées il y a quelques heures » (l'autre, la machine à états,
         * est traitée sous LOCK-OSM-CANCEL-AFTER-READY).
         *
         * Avant 5 h du matin, la veille reste donc affichée avec le jour courant.
         * Passé cette heure, comportement strictement identique à avant : un seul
         * jour. La décision « board du jour » documentée plus bas pour des raisons
         * de charge est préservée — elle n'est élargie que sur la poignée d'heures
         * où elle coupait le service en deux.
         *
         * Logique et cas limites (mois, année, heure réglable) :
         * resources/js/helpers/posServiceDay.js + tests/js/posServiceDay.spec.js.
         */
        _todayRange() {
            return serviceDayRange();
        },
        /**
         * [COMMANDES EN SOUFFRANCE 2026-08-19] Libellé de statut du panneau.
         *
         * Réutilise EXACTEMENT les libellés des voies affichées juste au-dessus : le caissier
         * lit « En préparation » dans la colonne et « En préparation » dans le panneau, pas deux
         * mots pour un même état. `all.order.status.X` n'existe QUE côté PHP — la première
         * écriture de ce panneau affichait donc la clé brute « all.order.status.8 » à l'écran,
         * défaut vu en ouvrant la page, pas en relisant le code.
         *
         * PENDING n'a pas de voie (le tableau ne l'affiche jamais) : libellé propre en dur,
         * cohérent avec la V1 FR-locked (ADR-007).
         */
        staleStatusLabel(status) {
            const s = Number(status);
            if (s === orderStatusEnum.PENDING) return 'En attente';
            if (s === orderStatusEnum.ACCEPT) return this.$t('pos.tracker.col_accept');
            if (s === orderStatusEnum.PREPARING) return this.$t('pos.tracker.col_preparing');
            if (s === orderStatusEnum.PREPARED) return this.$t('pos.tracker.col_prepared');
            if (s === orderStatusEnum.OUT_FOR_DELIVERY) return this.$t('pos.tracker.col_on_the_way');
            if (s === orderStatusEnum.DELIVERED) return this.$t('pos.tracker.col_delivered');
            return '';
        },
        // [BOUTON SCELLÉ 2026-08-19] Ouvre la contrepartie comptable sur une commande scellée.
        openRefundDialog(order) {
            if (!order || !order.id) return;
            this.refundOrder = order;
        },
        async onRefunded() {
            this.refundOrder = null;
            await this.fetchOrders();
            if (this.staleOpen) await this.fetchStaleOrders();
        },
        /**
         * [COMMANDES EN SOUFFRANCE 2026-08-19] Charge les non terminées antérieures à la
         * journée de service. Lecture seule ; les actions passent par les routes existantes.
         */
        async fetchStaleOrders() {
            if (this.staleLoading) return;
            this.staleLoading = true;
            this.staleError = '';
            try {
                const res = await axios.get('admin/pos-order/stale', { params: { per_page: 50 } });
                const data = res?.data?.data;
                this.staleOrders = Array.isArray(data) ? data : [];
                const meta = res?.data?.meta;
                this.staleMeta = meta && typeof meta === 'object'
                    ? { count: Number(meta.count) || 0, shown: Number(meta.shown) || 0, truncated: !!meta.truncated }
                    : { count: 0, shown: 0, truncated: false };
            } catch (e) {
                // On DIT que la lecture a échoué : un panneau vide se lirait « il n'y en a pas ».
                this.staleOrders = [];
                this.staleMeta = { count: 0, shown: 0, truncated: false };
                this.staleError = e?.response?.data?.message || 'Lecture impossible';
            } finally {
                this.staleLoading = false;
            }
        },
        async toggleStalePanel() {
            this.staleOpen = !this.staleOpen;
            if (this.staleOpen && this.staleOrders.length === 0 && !this.staleError) {
                await this.fetchStaleOrders();
            }
        },
        async markDelivered(order) {
            if (!order || order._delivering) return;
            order._delivering = true;
            try {
                // [POS-V4-CASHIER-OPS 2026-05-02 FIX] Backend OrderStatusRequest
                // validates `status`, not `order_status` — previous cycle shipped
                // the wrong field name which silently no-op'd validation. Aligned
                // with PosOrderShowComponent canonical usage.
                await this.$store.dispatch('posOrder/changeStatus', {
                    id: order.id,
                    status: orderStatusEnum.DELIVERED,
                });
                await this.fetchOrders();
            } catch (e) {
                const msg = e?.response?.data?.message || this.$t('message.something_wrong');
                alertService.error(msg);
                order._delivering = false;
            }
        },
        // [POS-V4-CASHIER-OPS 2026-05-02] One-click reprint.
        // Fetches the full order (we only hold the lightweight list payload)
        // and opens the existing ReceiptComponent modal, which carries its own
        // print buttons (kitchen + client) — fiscal compteur is updated by the
        // existing `pos.print` endpoint, NOT here. We're a pure UI shortcut.
        async requestReprint(order) {
            if (!order || !order.id) return;
            if (this.reprintBusyId === order.id) return;
            this.reprintBusyId = order.id;
            try {
                const res = await this.$store.dispatch('posOrder/show', order.id);
                const fullOrder = res?.data?.data;
                if (!fullOrder || !fullOrder.id) {
                    throw new Error('empty');
                }
                this.reprintOrder = fullOrder;
                this.$nextTick(() => {
                    appService.modalShow('#receiptModal');
                });
            } catch (e) {
                alertService.error(this.$t('pos.reprint_error'));
            } finally {
                this.reprintBusyId = null;
            }
        },
        // [POS-V4-CASHIER-OPS 2026-05-02] Cancel-with-reason flow.
        openCancelDialog(order) {
            this.cancelDialog = {
                open: true,
                order,
                reason: '',
                error: '',
                busy: false,
            };
            this.$nextTick(() => {
                try { this.$refs.cancelReasonInput?.focus(); } catch (e) { /* defensive */ }
            });
        },
        closeCancelDialog() {
            if (this.cancelDialog.busy) return;
            this.cancelDialog = {
                open: false,
                order: null,
                reason: '',
                error: '',
                busy: false,
            };
        },
        async confirmCancelOrder() {
            const dlg = this.cancelDialog;
            if (!dlg.open || !dlg.order || dlg.busy) return;
            const reason = String(dlg.reason || '').trim();
            if (reason.length < 3) {
                this.cancelDialog.error = this.$t('pos.cancel_order_reason_required');
                return;
            }
            this.cancelDialog.busy = true;
            this.cancelDialog.error = '';
            try {
                // Backend OrderService::changeStatus accepts `reason` for the
                // CANCELED transition (validates required|max:700, persists on
                // order, dispatches OrderStatusChanged with reason). We rely on
                // that single endpoint — no schema change here.
                await this.$store.dispatch('posOrder/changeStatus', {
                    id: dlg.order.id,
                    status: orderStatusEnum.CANCELED,
                    reason,
                });
                this.cancelDialog.busy = false;
                this.closeCancelDialog();
                alertService.success(this.$t('pos.cancel_order_done'));
                await this.fetchOrders();
                // [COMMANDES EN SOUFFRANCE 2026-08-19] Une annulation peut venir de CE panneau :
                // sans ce rafraîchissement, la ligne resterait affichée et le compteur mentirait.
                await this.fetchStaleOrders();
            } catch (e) {
                // [test-e2e/pos-kds-sync round-3 E-001 P0] silent-error visibility:
                // backend 422/4xx on /pos-order/change-status was previously written
                // only into cancelDialog.error inside the modal. Audit Wave E state 14
                // showed zero [role=alert] / .toast in DOM → operator had no signal
                // when the cancel was rejected (e.g. status already advanced past
                // CANCELED-eligible). Now we ALSO fire alertService.error (vue-toastification
                // toast with role="alert") so the rejection is visible at the page level,
                // and we use a dedicated friendly message for HTTP 422 (rule violation).
                this.cancelDialog.busy = false;
                const status = Number(e?.response?.status) || 0;
                const backendMsg = e?.response?.data?.message
                    || e?.response?.data?.errors?.reason?.[0];
                let msg;
                if (status === 422) {
                    msg = backendMsg || this.$t('error.order_cancel_rejected');
                } else if (status === 401 || status === 403) {
                    msg = backendMsg || this.$t('error.order_cancel_unauthorized');
                } else if (status >= 400 && status < 500) {
                    msg = backendMsg || this.$t('pos.cancel_order_error');
                } else {
                    msg = backendMsg || this.$t('pos.cancel_order_error');
                }
                this.cancelDialog.error = msg;
                try { alertService.error(msg); } catch (_) { /* defensive — never block dialog */ }
            }
        },
        // [Wave S-4 P-OWNER 2026-05-20] Cash-pending detection. The backend
        // `SimpleOrderResource` exposes `is_cash_pending` (PENDING_COUNTER +
        // COUNTER_DEFERRED). We keep a defensive fallback on the canonical
        // numeric enum constants in case an older projection ships through
        // (e.g. cached Vuex payload pre-deploy). PaymentStatus::PENDING_COUNTER
        // = 15, PosPaymentMethod::COUNTER_DEFERRED = 6 — see app/Enums/.
        /**
         * [S2 F1 révisé 2026-07-29] Compteur des commandes à encaisser ANTÉRIEURES
         * au jour affiché. Volontairement hors du board (voir fetchOrders) : on
         * ne rapatrie qu'un nombre, jamais les lignes. Throttlé à 5 min car
         * l'endpoint renvoie une resource lourde (~1,3 s) — il ne doit JAMAIS
         * suivre la cadence du poll dégradé (8 s).
         */
        async _refreshOlderPendingCount() {
            const now = Date.now();
            if (this._olderPendingFetchedAt && (now - this._olderPendingFetchedAt) < OLDER_PENDING_TTL_MS) {
                return;
            }
            this._olderPendingFetchedAt = now;
            try {
                const res = await axios.get('admin/pos/counter-collect/pending');
                const rows = Array.isArray(res?.data?.data) ? res.data.data : [];
                const onBoard = new Set(
                    this.orders.map((o) => parseInt(o?.id, 10)).filter(Number.isFinite)
                );
                this.olderPendingCount = rows.filter((r) => {
                    const id = parseInt(r?.id, 10);
                    return Number.isFinite(id) && !onBoard.has(id);
                }).length;
            } catch (_) {
                // File indisponible → on garde la dernière valeur connue (pas de faux
                // zéro). [S2 auto-RED cycle 2] Back-off : on ne remet PAS le TTL à 0,
                // sinon un échec persistant (403/429/réseau) relancerait cet endpoint
                // lourd à chaque poll, soit toutes les 8 s en mode dégradé. Retente
                // dans 30 s.
                this._olderPendingFetchedAt = now - OLDER_PENDING_TTL_MS + 30000;
            }
        },
        /**
         * [S2 auto-RED 2026-07-29] Set terminal du sceau d'encaissement — miroir
         * strict de la garde backend de `counter-collect/pending`. Une commande
         * dans un de ces statuts n'est plus encaissable, quel que soit son
         * `payment_status` résiduel.
         */
        isTerminalStatus(status) {
            const s = parseInt(status, 10);
            return s === orderStatusEnum.CANCELED
                || s === orderStatusEnum.REJECTED
                || s === orderStatusEnum.RETURNED;
        },
        isCashPending(o) {
            if (!o) return false;
            if (o.is_cash_pending === true || o.is_cash_pending === 1) return true;
            const ps = parseInt(o.payment_status, 10);
            const ppm = parseInt(o.pos_payment_method, 10);
            return ps === 15 && ppm === 6;
        },
        // [SYNC gateway-refund coherence 2026-08-05] Remboursée par la banque = PaymentStatus::REFUNDED (20).
        // Un refund gateway (Mollie/Stripe) laisse souvent status=PREPARING mais payment_status=REFUNDED.
        isRefunded(o) {
            return o ? parseInt(o.payment_status, 10) === 20 : false;
        },
        // [WEB-TRACKER-VISIBILITY 2026-07-20] Commande WEB fraîchement arrivée (PENDING, pas
        // encore acceptée). Distincte de isCashPending (qui = déjà acceptée, PENDING_COUNTER).
        isWebPending(o) {
            if (!o) return false;
            // [AUDIT-B D3 2026-08-06 · P1] web ≡ delivery (FrontendOrder::creating force
            // 'delivery' sur une commande LIVRAISON site) : sans elle, la voie « Accepter »
            // du tracker ignorait la livraison PENDING → personne ne pouvait l'accepter.
            const surface = String(o.source_surface || '').toLowerCase();
            return (surface === 'web' || surface === 'delivery') && parseInt(o.status, 10) === orderStatusEnum.PENDING;
        },
        // [WEB-TRACKER-VISIBILITY 2026-07-20] Accepter une commande web SANS quitter le tracker —
        // miroir exact de PosComponent.acceptWebOrder (C1 2026-07-18) : même endpoint
        // online-order/change-status (ACCEPT), même clé d'idempotence minute-bucket. Le backend
        // bascule le takeaway COD web en PENDING_COUNTER+COUNTER_DEFERRED → au refresh la carte
        // reste dans la voie « À encaisser », désormais avec le CTA Encaisser (cash-pending).
        async acceptWebOrder(order) {
            if (!order || !order.id || this.webAccepting[order.id]) return;
            this.webAccepting = { ...this.webAccepting, [order.id]: true };
            try {
                const minuteBucket = Math.floor(Date.now() / 60000);
                // [CAISSE-WEB-INTEL 2026-08-06 · RED heal P2] Temps de préparation choisi
                // (15/25/40) envoyé avec l'accept — TOUJOURS envoyé, y compris le défaut
                // affiché 15 : sans ça, le select montrait « 15 min » mais le backend
                // gardait le défaut settings (réglable ≠ 15) → mensonge UI. Ce que le
                // caissier VOIT est ce qui est ENVOYÉ.
                const prep = parseInt(this.webPrepChoice[order.id] ?? 15, 10);
                await axios.post(
                    `admin/online-order/change-status/${order.id}`,
                    {
                        status: orderStatusEnum.ACCEPT,
                        ...(Number.isFinite(prep) && prep > 0 ? { preparation_time: prep } : {}),
                    },
                    { headers: { 'X-Idempotency-Key': `web-accept-${order.id}-${minuteBucket}` } }
                );
                const num = order.queue_number || order.order_serial_no || order.id;
                // FR direct (idiome du panneau web caisse, locale FR ADR-007) — pas de raw-label i18n.
                try { alertService.success(`Commande web N°${num} acceptée — encaissement au comptoir`); } catch (_) { /* best-effort */ }
                await this.fetchOrders();
            } catch (err) {
                const msg = err?.response?.data?.message || 'Erreur lors de l\'acceptation de la commande web';
                try { alertService.error(msg); } catch (_) { /* defensive */ }
            } finally {
                this.webAccepting = { ...this.webAccepting, [order.id]: false };
            }
        },
        // [Wave S-4 P-OWNER 2026-05-20] Encaissement CTA — Wave S-5 owns the
        // actual modal. We surface a window-level event so the parent shell
        // (PosShell / global listener) can intercept, hydrate the order, and
        // open the encaissement dialog. No direct coupling here — emitting a
        // CustomEvent keeps the tracker decoupled while Wave S-5 lands in
        // parallel. Fallback: deep-link to the POS payment screen.
        openEncaissement(order) {
            if (!order || !order.id) return;
            // [GOAL-2026-05-29 DEAD-BUTTON-FIX] Previously this ONLY dispatched a
            // `foodking:pos:open-encaissement` CustomEvent expecting a global
            // listener (PosShell/PosComponent) — but nothing in the app ever
            // listened for it, and on the standalone /admin/pos-orders-tracker
            // page PosComponent is not mounted, so the Encaisser CTA was a DEAD
            // BUTTON. We now open the shared PosCounterCollectModal locally; it
            // POSTs admin/pos/counter-collect/{id}/confirm itself, and on
            // @confirmed we refresh the board. The modal reads `order.total`, so
            // we map the cash-pending amount onto it.
            const amount = order.cash_pending_amount ?? order.total_amount_price ?? order.total ?? order.order_amount ?? 0;
            this.encaisseOrder = { ...order, total: amount };
            // Keep the decoupled CustomEvent (harmless) for any future global host.
            try {
                window.dispatchEvent(new CustomEvent('foodking:pos:open-encaissement', {
                    detail: { orderId: order.id, amount },
                }));
            } catch (_e) { /* defensive — environment without CustomEvent */ }
        },
        // [GOAL-2026-05-29 DEAD-BUTTON-FIX] PosCounterCollectModal already POSTed
        // the counter-collect; clear it + refresh so the now-paid order leaves
        // the "À encaisser" lane (the OrderPaidAtCounter broadcast also triggers
        // fetchOrders, but we refresh immediately for local responsiveness).
        onEncaisseConfirmed() {
            this.encaisseOrder = null;
            // [S2 F1 révisé 2026-07-29] Un encaissement change la file d'attente :
            // on invalide le TTL du compteur d'anciennes commandes pour que le
            // bandeau ne reste pas jusqu'à 5 min sur une valeur périmée.
            this._olderPendingFetchedAt = 0;
            this.fetchOrders();
        },
        sourceOf(o) {
            const surface = String(o.source_surface || o._origin || '').toLowerCase();
            if (surface === 'kiosk') return 'kiosk';
            if (surface === 'pos') return 'pos';
            if (surface === 'online') return 'online';
            // [WEB-TRACKER-VISIBILITY 2026-07-20] source_surface='web' (site client) = onglet 🌐.
            // Avant : non reconnu → retombait sur l'heuristique order_type → classé 'pos' à tort.
            if (surface === 'web') return 'online';
            // [GOAL-CAISSE-VISION 2026-08-24] Trois canaux réels tombaient tous dans « Caisse ».
            //
            // TÉLÉPHONE (`source_surface='phone'`, créé par `OrderService.php:1273`) : le client
            // n'est PAS là. Il faut pouvoir l'appeler, et il viendra payer au comptoir. Le
            // confondre avec une vente au comptoir — client présent, déjà payé — c'est confondre
            // deux situations opposées. Le mode existe depuis le 2026-07-07
            // (`tests/Feature/Pos/PhoneOrderDeferredTest.php`) ; le suivi ne l'avait jamais su.
            //
            // PLATEFORME (Uber/Deliveroo) : commission de 30-35 %, ticket promo dédié — la carte
            // proposait déjà le bouton (`isPlatformOrder`) tout en affichant « Caisse ».
            //
            // LIVRAISON : la commande part, elle ne sera pas retirée au comptoir.
            if (surface === 'phone') return 'phone';
            if (surface === 'uber_eats' || surface === 'uber' || surface === 'ubereats'
                || surface === 'deliveroo' || surface === 'just_eat' || surface === 'justeat'
                || surface === 'platform') return 'platform';
            if (surface === 'delivery') return 'delivery';
            const ot = parseInt(o.order_type, 10);
            // Heuristics fallback when source_surface is missing
            if (Number.isFinite(ot)) {
                if (ot === 17 || ot === 18) return 'kiosk';
                if (ot === 15 || ot === 20) return 'pos';
            }
            return 'pos';
        },

        /**
         * [GOAL-CAISSE-VISION 2026-08-24] Libellé FR du canal, pour le panneau
         * « Voir tout » et les infobulles. Passe par les clés i18n existantes
         * (`pos.tracker.source_*`) — jamais de clé brute à l'écran.
         */
        sourceLabel(o) {
            return this.$t('pos.tracker.source_' + this.sourceOf(o));
        },
        sourceIcon(o) {
            const s = this.sourceOf(o);
            if (s === 'kiosk') return '🖥️';
            if (s === 'online') return '🌐';
            // [GOAL-CAISSE-VISION 2026-08-24] Un pictogramme par canal réel. Le 📞
            // dit au caissier l'essentiel en un coup d'œil : ce client n'est pas là.
            if (s === 'phone') return '📞';
            if (s === 'platform') return '🛵';
            if (s === 'delivery') return '🚗';
            return '🛒';
        },
        customerLabel(o) {
            const u = o.user || {};
            const n = u.name || [u.first_name, u.last_name].filter(Boolean).join(' ');
            return n || o.customer_name || '';
        },

        // [FLYER PROMO 2026-08-08] Ouvre la fenêtre « ticket promo » depuis la
        // caisse. Le nom est pré-rempli quand on part d'une carte de commande :
        // l'exploitant lit le prénom sur la commande plateforme et n'a plus
        // qu'à valider — un geste au lieu de trois.
        openPromoFlyer(prefill) {
            this.promoFlyerPrefill = String(prefill || '').trim();
            this.promoFlyerOpen = true;
        },

        // Le ticket vise les clients venus d'une PLATEFORME : c'est là que la
        // commission de 30-35 % s'applique et qu'un retour en direct rapporte.
        // Sur une commande déjà passée en direct, le bouton n'aurait aucun sens
        // et encombrerait la carte.
        isPlatformOrder(o) {
            const s = String(o.source_surface || o._origin || '').toLowerCase();
            return s.includes('uber') || s.includes('deliveroo') || s.includes('just') || s.includes('platform');
        },
        // [OWNER 2026-07-31] Téléphone du client pour la carte de suivi. SimpleOrderResource
        // ship `customer_phone` pour les commandes WEB (client distant → le caissier appelle
        // pour confirmer) et DELIVERY (livreur). null pour borne/walk-in (client présent).
        customerPhone(o) {
            return o.customer_phone || (o.user && o.user.phone) || '';
        },
        itemsPreview(o) {
            const items = Array.isArray(o.order_items) ? o.order_items : [];
            return items.slice(0, 3);
        },
        extraItemsCount(o) {
            const items = Array.isArray(o.order_items) ? o.order_items : [];
            return Math.max(0, items.length - 3);
        },

        // ─────────────────────────────────────────────────────────────────────
        // [GOAL-CAISSE-VISION 2026-08-24] Voir CE QUE LE CLIENT A PRIS
        //
        // Demande propriétaire : « si j'ai un client devant moi, j'ai pas pris son
        // nom, je peux voir ce qu'il a pris et toutes les personnalisations qu'il a
        // fait ». Le serveur expédie désormais la composition en forme compacte
        // (`SimpleOrderResource` → `App\Support\Order\CompositionCompactor`), déjà
        // réconciliée entre l'instantané NF525 et l'ancienne forme. Ici on ne fait
        // que RENDRE : aucune re-dérivation, aucun appel réseau.
        // ─────────────────────────────────────────────────────────────────────

        /**
         * Nom du produit, avec repli. `item_name` est null quand l'article a été
         * retiré du catalogue depuis la vente : sans repli la carte affichait une
         * ligne muette — une quantité, un vide, et un caissier incapable de dire ce
         * que le client tient dans la main.
         */
        nomProduit(item) {
            if (!item) return this.$t('label.deleted_item');
            const nom = String(item.item_name || item.name || '').trim();
            return nom || this.$t('label.deleted_item');
        },

        /**
         * Résumé d'UNE ligne, pour la carte : « Sauce algérienne · Salade · +2 Cheddar ».
         * Volontairement court — la carte doit rester lisible d'un coup d'œil ;
         * le détail intégral vit dans le panneau « Voir tout ».
         */
        resumeComposition(item) {
            if (!item) return '';
            const morceaux = [];

            (item.options || []).forEach((o) => {
                const valeur = String(o?.value || '').trim();
                if (!valeur) return;
                morceaux.push(o.quantity > 1 ? `${valeur} ×${o.quantity}` : valeur);
            });
            (item.extras || []).forEach((e) => {
                const nom = String(e?.name || '').trim();
                if (!nom) return;
                morceaux.push(e.quantity > 1 ? `+${e.quantity} ${nom}` : `+${nom}`);
            });
            (item.addons || []).forEach((a) => {
                const nom = String(a?.name || '').trim();
                if (!nom) return;
                morceaux.push(a.quantity > 1 ? `+${a.quantity} ${nom}` : `+${nom}`);
            });

            return morceaux.join(' · ');
        },

        /** Toutes les lignes de la commande, telles qu'expédiées par le serveur. */
        lignesCompletes(o) {
            return o && Array.isArray(o.order_items) ? o.order_items : [];
        },

        /**
         * « Voir tout » n'apparaît que s'il y a vraiment quelque chose de plus à
         * voir : plus de 3 lignes, une personnalisation, ou une instruction. Un
         * bouton qui n'ajoute rien est un bouton qui ment.
         */
        aDuContenuAVoir(o) {
            const lignes = this.lignesCompletes(o);
            if (lignes.length > 3) return true;
            return lignes.some((l) => (
                (l.options || []).length > 0
                || (l.extras || []).length > 0
                || (l.addons || []).length > 0
                || (typeof l.instruction === 'string' && l.instruction.trim() !== '')
            ));
        },

        /** « + 2 Cheddar, Salade » — liste nommée avec quantités implicites. */
        listeNommee(liste) {
            return (Array.isArray(liste) ? liste : [])
                .map((e) => {
                    const nom = String(e?.name || '').trim();
                    if (!nom) return '';
                    return e.quantity > 1 ? `${e.quantity}× ${nom}` : nom;
                })
                .filter(Boolean)
                .join(', ');
        },

        numeroCommande(o) {
            if (!o) return '';
            return o.queue_number ? `N°${o.queue_number}` : `#${o.order_serial_no || o.id}`;
        },

        /**
         * « 4 articles · 7 au total » — le nombre de LIGNES et la quantité cumulée.
         * Les deux comptent : 4 lignes disent combien de blocs lire, 7 articles
         * disent combien de choses partiront dans le sac.
         */
        compteArticles(o) {
            const lignes = this.lignesCompletes(o);
            if (!lignes.length) return '';
            const pieces = lignes.reduce((n, l) => n + (parseInt(l.quantity, 10) || 1), 0);
            const motLignes = lignes.length > 1 ? 'articles' : 'article';
            return pieces > lignes.length
                ? `${lignes.length} ${motLignes} · ${pieces} au total`
                : `${lignes.length} ${motLignes}`;
        },

        ouvrirContenu(o) {
            this.contenuDialog = { open: true, order: o };
            // Le focus part sur la fermeture : au clavier comme au tactile, Échap et
            // Entrée referment sans piéger le caissier dans le panneau.
            this.$nextTick(() => {
                try { this.$refs.contenuCloseBtn?.focus(); } catch (e) { /* jsdom */ }
            });
        },

        fermerContenu() {
            this.contenuDialog = { open: false, order: null };
        },

        /** Échap ferme le panneau, où que soit le focus. */
        _contenuOnKeydown(ev) {
            if (ev && ev.key === 'Escape' && this.contenuDialog.open) {
                this.fermerContenu();
            }
        },
        // [WT-D-R1-F4 2026-05-20] `formatPrice()` is now provided by the
        // shared `adminPriceMixin` (helpers/formatPrice.js) so every admin
        // surface — tracker, orders list, detail page — renders the exact
        // same "19,00 €" string for the same numeric input. Behaviour is
        // byte-identical to the previous inline implementation (Intl
        // fr-FR EUR with NBSP separator + fallback).
        formatTime(iso) {
            if (!iso) return '';
            try {
                return new Date(iso).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' });
            } catch (e) { return ''; }
        },
        elapsedShort(iso) {
            if (!iso) return '';
            const t = new Date(iso).getTime();
            if (!Number.isFinite(t)) return '';
            const diff = Math.max(0, Date.now() - t);
            const mins = Math.floor(diff / 60000);
            if (mins < 1) return this.$t('pos.tracker.now');
            if (mins < 60) return mins + ' min';
            const h = Math.floor(mins / 60);
            const m = mins % 60;
            return h + 'h' + (m < 10 ? '0' + m : m);
        },
        // ── [CAISSE-WEB-INTEL 2026-08-06] Intelligence commandes web ─────────
        // Payée EN LIGNE (CB Mollie) : rien à encaisser — badge ✅ pour tuer le
        // doute (et le double encaissement). Complément exact du 🔔 cash-pending.
        isPaidOnline(o) {
            if (!o) return false;
            // [RED heal P2 2026-08-06] Exiger le moyen de paiement CARTE (PaymentGateway::CARD=4) :
            // une web COD encaissée en ESPÈCES passe PENDING_COUNTER→PAID et portait le badge
            // « CB » à tort — information de moyen de paiement FAUSSE (litige/contrôle tiroir).
            return parseInt(o.payment_status, 10) === paymentStatusEnum.PAID
                && parseInt(o.payment_method, 10) === 4
                && this.sourceOf(o) === 'online'
                && !this.isCashPending(o);
        },
        isDeliveryOrder(o) {
            return o ? parseInt(o.order_type, 10) === orderTypeEnum.DELIVERY : false;
        },
        _scheduledTs(o) {
            if (!o || !o.scheduled_at) return 0;
            const t = new Date(o.scheduled_at).getTime();
            return Number.isFinite(t) ? t : 0;
        },
        // Programmée ET pas encore due (échéance − lead dans le futur).
        _scheduledNotYetDue(o) {
            const ts = this._scheduledTs(o);
            if (!ts) return false;
            return this.ageTick < (ts - SCHEDULED_LEAD_MIN * 60000);
        },
        // « pour 19:30 » — badge 🕐 des commandes programmées (aujourd'hui) ou
        // « pour le 12/08 19:30 » si une avance multi-jours arrive un jour.
        scheduledLabel(o) {
            const ts = this._scheduledTs(o);
            if (!ts) return '';
            const d = new Date(ts);
            const hm = o.scheduled_hm || d.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' });
            const today = new Date();
            const sameDay = d.getFullYear() === today.getFullYear()
                && d.getMonth() === today.getMonth()
                && d.getDate() === today.getDate();
            return sameDay ? `pour ${hm}` : `pour le ${d.toLocaleDateString('fr-FR', { day: '2-digit', month: '2-digit' })} ${hm}`;
        },
        // Première instruction client de la commande (allergie, note…) — le
        // flag has_instruction vient du resource ; le texte vit par ligne.
        instructionPreview(o) {
            if (!o || !o.has_instruction) return '';
            const items = Array.isArray(o.order_items) ? o.order_items : [];
            const withNote = items.find((it) => it && typeof it.instruction === 'string' && it.instruction.trim() !== '');
            return withNote ? withNote.instruction.trim() : '';
        },
        // Titre d'onglet « (N) … » quand des commandes web attendent une action.
        _updateDocTitle(n) {
            try {
                if (!this._baseDocTitle) this._baseDocTitle = document.title || '';
                document.title = n > 0 ? `(${n}) ${this._baseDocTitle}` : this._baseDocTitle;
            } catch (_e) { /* environnement sans document */ }
        },
        // Réglage son : même clé que PosComponent (pos_new_order_sound_enabled,
        // défaut ON) — un seul interrupteur pour toutes les surfaces caisse.
        _newOrderSoundEnabled() {
            try {
                const s = this.$store.getters['frontendSetting/lists'] || {};
                const flag = s.pos_new_order_sound_enabled;
                if (flag === undefined || flag === null) return true;
                return String(flag) === '1' || flag === true;
            } catch (_e) {
                return true;
            }
        },
        // Beep + toast pour une nouvelle commande DISTANTE (web/borne). Les
        // commandes créées à la caisse même ne sonnent pas (le caissier les a
        // tapées). Dédup par id — jamais deux signaux pour la même commande.
        _maybeNotifyIncomingOrder(o) {
            if (!o) return;
            const src = this.sourceOf(o);
            if (src !== 'online' && src !== 'kiosk') return;
            const idStr = String(o.id);
            if (this._notifiedOrderIds.has(idStr)) return;
            this._notifiedOrderIds.add(idStr);
            try {
                const num = o.queue_number || o.order_serial_no || o.id;
                const label = src === 'online'
                    ? `Nouvelle commande web N°${num}`
                    : `Nouvelle commande borne N°${num}`;
                alertService.info(label);
            } catch (_e) { /* defensive */ }
            if (!this._newOrderSoundEnabled()) return;
            // [T-B ALERTE-WEB 2026-08-16 · GOAL owner] « la caisse n'arrête pas de sonner
            // pendant 30s » (en réalité : 1 seul bip de 0,4 s, noyé dans le bruit ambiant,
            // raté) → 3 bips espacés de 10 s, façon Uber Eats.
            //
            // [OWNER 2026-08-19] ÉLARGI À TOUS LES CANAUX D'ARRIVÉE. Le 16/08, seul le WEB
            // avait été demandé et la BORNE gardait son bip unique — décision explicitement
            // bornée à l'époque. Le propriétaire a tranché depuis : « 3 sonneries espacées,
            // puis stop » pour toute commande qui arrive. Une commande borne se rate aussi
            // bien qu'une commande web, et rien ne justifiait deux régimes d'alerte sur le
            // même comptoir. Les commandes SAISIES au comptoir ne notifient toujours pas :
            // le caissier vient de les taper lui-même (garde `src` au-dessus).
            this._sonnerieArrivee();
        },
        /**
         * [OWNER 2026-08-19] Sonnerie d'arrivée : 3 fois, espacées, puis stop. Le rythme vit
         * dans `helpers/orderArrivalChime.js`, partagé avec les trois autres surfaces ; ici on
         * ne fournit que la façon d'émettre UN son.
         *
         * Le séquenceur est créé à la demande (et non dans `data`) pour que les bancs d'essai
         * puissent appeler cette méthode sur un contexte fabriqué à la main, comme ils le font
         * déjà. Une nouvelle arrivée REMPLACE la séquence en attente : l'ancienne version
         * empilait ses minuteries sans borne, et cinq commandes en une minute donnaient quinze
         * bips entrelacés — un bruit continu qu'on finit par ignorer.
         */
        _sonnerieArrivee() {
            if (!this._sequenceurSonnerie) {
                this._sequenceurSonnerie = creerSequenceurDeSonnerie();
            }
            this._sequenceurSonnerie.declencher(() => this._playNewOrderBeep());
        },
        // [CAISSE-WEB-INTEL 2026-08-06] Miroir exact du beep PosComponent
        // (POS-9.1.11 / H.3.4) — Web Audio, aucun asset, resume() anti-autoplay.
        _playNewOrderBeep() {
            const Ctx = window.AudioContext || window.webkitAudioContext;
            if (!Ctx) return;
            if (!this._audioCtx) {
                try { this._audioCtx = new Ctx(); } catch (_e) { return; }
            }
            const ctx = this._audioCtx;
            const emit = () => {
                try {
                    const osc = ctx.createOscillator();
                    const gain = ctx.createGain();
                    osc.type = 'sine';
                    osc.frequency.value = 880;
                    gain.gain.setValueAtTime(0.0001, ctx.currentTime);
                    gain.gain.exponentialRampToValueAtTime(0.25, ctx.currentTime + 0.02);
                    gain.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + 0.35);
                    osc.connect(gain).connect(ctx.destination);
                    osc.start();
                    osc.stop(ctx.currentTime + 0.4);
                } catch (_e) { /* autoplay bloqué */ }
            };
            try {
                if (ctx.state === 'suspended' && typeof ctx.resume === 'function') {
                    const p = ctx.resume();
                    if (p && typeof p.then === 'function') {
                        p.then(emit).catch(() => { /* verrouillé */ });
                        return;
                    }
                }
                emit();
            } catch (_e) { /* defensive */ }
        },
    },
};
</script>

<style scoped>
/* =============================================================================
   PosOrdersTrackerComponent — POS V5 Design Convergence (refonte 2026-05-02)
   -----------------------------------------------------------------------------
   Mission : CV1-POS-DESIGN-CONVERGENCE-001
   Doc plan : §3.6
   - Approche chirurgicale : on remappe les tokens scoped --pos-tracker-*
     aux tokens V5 globaux. Tous les styles existants (excellents) prennent
     automatiquement la palette warm V5 sans toucher la structure DOM.
   - Bordure left colorée par status (Q4 plan : signal scan visuel rapide)
   ============================================================================= */
.pos-tracker-shell {
    /* Remap scoped tokens → V5 globals */
    --pos-tracker-bg: var(--pos-v5-bg-app);
    --pos-tracker-card-bg: var(--pos-v5-bg-panel);
    --pos-tracker-border: var(--pos-v5-border);
    --pos-tracker-text: var(--pos-v5-ink);
    --pos-tracker-muted: var(--pos-v5-ink-soft);
    --pos-tracker-primary: var(--pos-v5-brand-red);
    --pos-tracker-primary-soft: var(--pos-v5-brand-red-soft);
    --pos-tracker-amber: var(--pos-v5-warning);
    --pos-tracker-amber-soft: var(--pos-v5-warning-soft);
    --pos-tracker-green: var(--pos-v5-success);
    --pos-tracker-green-soft: var(--pos-v5-success-soft);
    --pos-tracker-muted-soft: var(--pos-v5-bg-subtle);
    /* [Wave T R1 F1 P0 2026-05-20] Blue tone for EN LIVRAISON lane.
       Hardcoded blue (not V5 token) — V5 palette has no info/blue role.
       Hue chosen high-contrast on white (≥4.5:1) and distinct from
       primary-red / amber / green to keep cashier scan unambiguous. */
    --pos-tracker-blue: #1d4ed8;
    --pos-tracker-blue-soft: #dbeafe;

    min-height: 100dvh;
    background: var(--pos-tracker-bg);
    padding: var(--pos-v5-space-4) var(--pos-v5-space-5) var(--pos-v5-space-6);
    color: var(--pos-tracker-text);
    font-family: var(--pos-v5-font-sans);
}

.pos-tracker-bar {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-end;
    justify-content: space-between;
    gap: var(--pos-v5-space-4) var(--pos-v5-space-6);
    padding: var(--pos-v5-space-3) var(--pos-v5-space-5);
    border-radius: var(--pos-v5-radius-lg);
    background: var(--pos-tracker-card-bg);
    border: 1px solid var(--pos-tracker-border);
    border-left: 4px solid var(--pos-v5-brand-red);
    box-shadow: var(--pos-v5-shadow-md);
    margin-bottom: var(--pos-v5-space-4);
}

.pos-tracker-eyebrow {
    font-size: 11px;
    font-weight: 600;
    letter-spacing: 0.08em;
    color: var(--pos-tracker-muted);
    text-transform: uppercase;
    margin: 0 0 4px;
}

.pos-tracker-title {
    font-size: 22px;
    font-weight: 700;
    color: var(--pos-tracker-text);
    margin: 0 0 6px;
    line-height: 1.1;
}

.pos-tracker-status-row {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 8px 16px;
    font-size: 13px;
    color: var(--pos-tracker-muted);
}

.pos-tracker-status-row strong {
    color: var(--pos-tracker-text);
    font-weight: 700;
}

.pos-tracker-status-pill {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 10px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 600;
    background: var(--pos-tracker-green-soft);
    color: #166534;
    border: 1px solid rgba(26, 183, 89, 0.25);
}

.pos-tracker-status-pill--ready {
    animation: pos-tracker-soft-pulse 2.4s ease-in-out infinite;
}

/* [CAISSE-WEB-INTEL 2026-08-06] Pill « web à traiter » — bouton (filtre 🌐).
   Ton cyan distinct du vert « prêts » et de l'ambre encaissement. */
.pos-tracker-status-pill--web {
    background: #ECFEFF;
    color: #155e75;
    border: 1px solid rgba(8, 145, 178, 0.35);
    cursor: pointer;
    font: inherit;
    font-size: 12px;
    font-weight: 700;
    animation: pos-tracker-soft-pulse 2.4s ease-in-out infinite;
}
.pos-tracker-status-pill--web:hover {
    background: #CFFAFE;
}

.pos-tracker-bar-right {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}

.pos-tracker-search {
    position: relative;
    display: flex;
    align-items: center;
    background: #F7F7FC;
    border: 1px solid var(--pos-tracker-border);
    border-radius: 10px;
    padding: 0 10px;
    min-width: 200px;
}

.pos-tracker-search i {
    color: var(--pos-tracker-muted);
    font-size: 14px;
}

.pos-tracker-search input {
    flex: 1;
    border: 0;
    background: transparent;
    padding: 8px 6px;
    font-size: 13px;
    color: var(--pos-tracker-text);
    outline: none;
    min-width: 0;
}

.pos-tracker-search input::placeholder {
    color: var(--pos-tracker-muted);
}

.pos-tracker-search-clear {
    background: transparent;
    border: 0;
    color: var(--pos-tracker-muted);
    cursor: pointer;
    font-size: 14px;
    padding: 0 4px;
}

.pos-tracker-source-tabs {
    display: inline-flex;
    background: #F7F7FC;
    border: 1px solid var(--pos-tracker-border);
    border-radius: 10px;
    padding: 3px;
    gap: 2px;
}

.pos-tracker-source-tab {
    background: transparent;
    border: 0;
    padding: 6px 10px;
    border-radius: 7px;
    font-size: 12px;
    font-weight: 600;
    color: var(--pos-tracker-muted);
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    transition: background 0.15s ease, color 0.15s ease;
}

.pos-tracker-source-tab:hover {
    background: rgba(176, 0, 77, 0.06);
    color: var(--pos-tracker-text);
}

.pos-tracker-source-tab.is-active {
    background: var(--pos-tracker-primary);
    color: #fff;
}

.pos-tracker-source-tab-icon {
    font-size: 14px;
}

.pos-tracker-history-link,
.pos-tracker-customer-link,
.pos-tracker-back-link {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 14px;
    border-radius: 10px;
    font-size: 13px;
    font-weight: 600;
    border: 1px solid var(--pos-tracker-border);
    background: #fff;
    color: var(--pos-tracker-text);
    text-decoration: none;
    transition: background 0.15s ease, border-color 0.15s ease;
}

.pos-tracker-history-link:hover,
.pos-tracker-customer-link:hover,
.pos-tracker-back-link:hover {
    background: var(--pos-tracker-primary-soft);
    border-color: var(--pos-tracker-primary);
    color: var(--pos-tracker-primary);
}

.pos-tracker-rt-warn {
    margin-bottom: 12px;
    padding: 8px 14px;
    border-radius: 10px;
    background: #FEF3C7;
    color: #92400E;
    font-size: 13px;
    font-weight: 600;
    border: 1px solid rgba(217, 119, 6, 0.2);
}

.pos-tracker-grid {
    display: grid;
    /* [Wave T R1 F1 P0 2026-05-20] 5-column layout: À ENCAISSER / EN
       PRÉPARATION / PRÊTS / EN LIVRAISON / LIVRÉS. Wave S-4 left the
       grid at 4 cols; with the new on-the-way lane the cashier keeps
       full caisse-to-delivered visibility on a single screen. Wide
       breakpoints unchanged for laptops & vertical caisse displays. */
    /* [T-SUIVI-LAYOUT 2026-08-19 · GOAL owner] `auto-fit` mesure la place RÉELLE
       du conteneur, là où les media queries mesuraient le VIEWPORT — écart de
       340 px sur cette route (barre latérale 260 px + marges), donc à 1481 px la
       règle réclamait 5 voies dans 1141 px, soit 217 px chacune, illisibles ; et à
       1366 px elle repassait à 3 voies, renvoyant « EN LIVRAISON » et « LIVRÉS »
       sur une 2e rangée hors écran — exactement la plainte terrain. Les trois
       media queries deviennent inutiles : `minmax` fait le travail à toute taille. */
    grid-template-columns: repeat(auto-fit, minmax(min(240px, 100%), 1fr));
    gap: 12px;
    align-items: start;
}

.pos-tracker-col {
    background: var(--pos-tracker-card-bg);
    border: 1px solid var(--pos-tracker-border);
    border-radius: 14px;
    overflow: hidden;
    display: flex;
    flex-direction: column;
    min-height: 280px;
    max-height: calc(100dvh - 160px);
}

.pos-tracker-col-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 12px 14px;
    border-bottom: 1px solid var(--pos-tracker-border);
    flex-shrink: 0;
}

.pos-tracker-col-head h2 {
    font-size: 14px;
    font-weight: 700;
    color: var(--pos-tracker-text);
    margin: 0;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    text-transform: uppercase;
    letter-spacing: 0.04em;
}

.pos-tracker-col-icon { font-size: 18px; }

/* [Wave S-4 P-OWNER 2026-05-20] Lane subtitle (À encaisser semantic). */
.pos-tracker-col-head-titles {
    display: flex;
    flex-direction: column;
    gap: 2px;
    min-width: 0;
}
.pos-tracker-col-subtitle {
    margin: 0;
    font-size: 11px;
    font-weight: 600;
    color: var(--pos-tracker-muted);
    text-transform: none;
    letter-spacing: 0;
    line-height: 1.2;
}

/* [Wave S-4 P-OWNER 2026-05-20] Pulsing amber tint on the À encaisser
 * column when ≥1 cash-pending order is present — matches the existing
 * green pulse on PRÊTS À SERVIR for cross-lane visual consistency. */
.pos-tracker-col--amber {
    border-color: rgba(245, 158, 11, 0.4);
    box-shadow: 0 0 0 1px rgba(245, 158, 11, 0.10) inset;
}
.pos-tracker-col--amber.is-pulse {
    animation: pos-tracker-col-amber-glow 2.6s ease-in-out infinite;
}
@keyframes pos-tracker-col-amber-glow {
    0%, 100% { box-shadow: 0 0 0 1px rgba(245, 158, 11, 0.12) inset; }
    50%      { box-shadow: 0 0 0 2px rgba(245, 158, 11, 0.32) inset, 0 0 18px rgba(245, 158, 11, 0.18); }
}

.pos-tracker-col-count {
    background: var(--pos-tracker-muted-soft);
    color: var(--pos-tracker-text);
    border-radius: 999px;
    padding: 2px 10px;
    font-size: 12px;
    font-weight: 700;
    min-width: 26px;
    text-align: center;
}

.pos-tracker-col--amber .pos-tracker-col-count { background: var(--pos-tracker-amber-soft); color: var(--pos-tracker-amber); }
.pos-tracker-col--primary .pos-tracker-col-count { background: var(--pos-tracker-primary-soft); color: var(--pos-tracker-primary); }
.pos-tracker-col--green .pos-tracker-col-count { background: var(--pos-tracker-green-soft); color: #166534; }
.pos-tracker-col--blue .pos-tracker-col-count { background: var(--pos-tracker-blue-soft); color: var(--pos-tracker-blue); }
.pos-tracker-col--muted .pos-tracker-col-count { background: var(--pos-tracker-muted-soft); color: var(--pos-tracker-muted); }

.pos-tracker-col--green {
    border-color: rgba(26, 183, 89, 0.4);
    box-shadow: 0 0 0 1px rgba(26, 183, 89, 0.12) inset;
}

.pos-tracker-col--green.is-pulse {
    animation: pos-tracker-col-glow 2.6s ease-in-out infinite;
}

@keyframes pos-tracker-col-glow {
    0%, 100% { box-shadow: 0 0 0 1px rgba(26, 183, 89, 0.12) inset; }
    50%      { box-shadow: 0 0 0 2px rgba(26, 183, 89, 0.32) inset, 0 0 18px rgba(26, 183, 89, 0.18); }
}

.pos-tracker-col-body {
    padding: 10px;
    overflow-y: auto;
    /* [T-SUIVI-LAYOUT 2026-08-19] `overflow-x` DOIT être déclaré. La spec CSS
       Overflow 3 impose qu'un axe laissé à `visible` face à un axe non-`visible`
       soit recalculé en `auto` : sans cette ligne, cette voie était elle-même un
       défileur horizontal — la vraie origine du « scroller à gauche et à droite »
       (le `overflow:hidden` de `.pos-tracker-col` est une couche AU-DESSUS et ne
       protégeait pas d'ici). Le contenu s'adapte maintenant en largeur. */
    overflow-x: hidden;
    overscroll-behavior: contain;
}

.pos-tracker-cards {
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.pos-tracker-col-empty {
    flex: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 28px 16px;
    color: var(--pos-tracker-muted);
    font-size: 13px;
    text-align: center;
    gap: 8px;
}

.pos-tracker-col-empty-icon {
    font-size: 28px;
    opacity: 0.55;
}

.pos-tracker-card {
    border: 1px solid var(--pos-tracker-border);
    border-radius: 12px;
    background: #fff;
    padding: 10px 12px;
    display: flex;
    flex-direction: column;
    gap: 8px;
    transition: box-shadow 0.2s ease, transform 0.2s ease, border-color 0.2s ease;
}

.pos-tracker-card:hover {
    box-shadow: 0 6px 16px rgba(31, 31, 57, 0.08);
    transform: translateY(-1px);
}

/* Q4 plan : bordure left 4px par status — scan visuel rapide pour caissier */
.pos-tracker-card--amber { border-left: 4px solid var(--pos-tracker-amber); }
.pos-tracker-card--primary { border-left: 4px solid var(--pos-tracker-primary); }
.pos-tracker-card--green { border-left: 4px solid var(--pos-tracker-green); }
.pos-tracker-card--blue { border-left: 4px solid var(--pos-tracker-blue); }
.pos-tracker-card--muted { border-left: 4px solid var(--pos-v5-border-strong); opacity: 0.85; }

.pos-tracker-card.is-fresh { animation: pos-tracker-card-pop 1.2s ease-out 1; border-color: var(--pos-tracker-green); }

@keyframes pos-tracker-card-pop {
    0%   { transform: scale(0.96); box-shadow: 0 0 0 0 rgba(26, 183, 89, 0.45); }
    40%  { transform: scale(1.02); box-shadow: 0 0 0 8px rgba(26, 183, 89, 0.18); }
    100% { transform: scale(1);    box-shadow: 0 0 0 0 rgba(26, 183, 89, 0.0); }
}

/* [IMP-AGING 2026-07-22] À encaisser cards escalate visually with wait time:
 * ≥5 min = orange (aging), ≥10 min = red + gentle pulse (urgent). The two
 * classes are chained after .pos-tracker-card so their border-color beats the
 * per-tone left-border shorthand (0-2-0 > 0-1-0 specificity). */
.pos-tracker-card.tracker-card--aging {
    border-color: var(--pos-tracker-amber);
    background: #fffbeb;
}
.pos-tracker-card.tracker-card--aging .pos-tracker-card-time {
    color: #b45309;
    font-weight: 700;
}
.pos-tracker-card.tracker-card--urgent {
    border-color: #dc2626;
    background: #fef2f2;
    animation: pos-tracker-card-urgent-pulse 1.6s ease-in-out infinite;
}
.pos-tracker-card.tracker-card--urgent .pos-tracker-card-time {
    color: #b91c1c;
    font-weight: 700;
}
@keyframes pos-tracker-card-urgent-pulse {
    0%, 100% { box-shadow: 0 0 0 0 rgba(220, 38, 38, 0); }
    50%      { box-shadow: 0 0 0 4px rgba(220, 38, 38, 0.18); }
}
.pos-tracker-card-age {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    align-self: flex-start;
    padding: 2px 8px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 700;
    background: var(--pos-tracker-amber-soft);
    color: #b45309;
    border: 1px solid rgba(245, 158, 11, 0.35);
}
.pos-tracker-card-age--urgent {
    background: #fee2e2;
    color: #b91c1c;
    border-color: rgba(220, 38, 38, 0.35);
}

.pos-tracker-card-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
}

.pos-tracker-card-num {
    font-size: 17px;
    font-weight: 800;
    color: var(--pos-tracker-text);
    letter-spacing: -0.01em;
}

.pos-tracker-card-source {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 26px;
    height: 26px;
    border-radius: 8px;
    background: var(--pos-tracker-muted-soft);
    font-size: 14px;
}

.pos-tracker-card-source--kiosk { background: #EEF2FF; }
/* [T-B ALERTE-WEB 2026-08-16 · GOAL owner] Bleu pâle → rouge, même teinte que
   .pos-shortcuts__panel--web (PosComponent.vue) pour une identité visuelle
   cohérente "commande web" sur tout l'écran caisse. */
.pos-tracker-card-source--online { background: #FDECEA; color: #d32f2f; }

/* [Wave S-4 P-OWNER 2026-05-20] Cash-pending bell badge — strong amber,
 * gentle pulse to keep cashier attention without being aggressive. */
.pos-tracker-card-cash-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 26px;
    height: 26px;
    border-radius: 8px;
    background: var(--pos-tracker-amber-soft);
    color: var(--pos-tracker-amber);
    font-size: 14px;
    border: 1px solid rgba(245, 158, 11, 0.35);
    animation: pos-tracker-cash-bell-pulse 2.2s ease-in-out infinite;
}
@keyframes pos-tracker-cash-bell-pulse {
    0%, 100% { transform: scale(1); }
    50%      { transform: scale(1.08); }
}
@media (prefers-reduced-motion: reduce) {
    .pos-tracker-card-cash-badge { animation: none; }
}

/* [Wave S-4 P-OWNER 2026-05-20] Cash-pending amount emphasis. */
/* [S2 V4 2026-07-29] Empilé (colonne) : en ligne, le libellé « À ENCAISSER : »
   se cassait en 3 lignes dans la largeur restante à côté du bouton Encaisser et
   chevauchait ce dernier. Le montant garde toute sa lisibilité. */
.pos-tracker-card-total--cash {
    color: var(--pos-tracker-amber);
    font-weight: 800;
    display: inline-flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 2px;
    min-width: 0;
}
.pos-tracker-card-total-prefix {
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: var(--pos-tracker-muted);
    white-space: nowrap;
}

/* [CAISSE-WEB-INTEL 2026-08-06] Badge « ✅ CB » payé en ligne — vert succès,
   même gabarit que le 🔔 cash-pending pour la symétrie visuelle. */
.pos-tracker-card-paid-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    height: 26px;
    padding: 0 8px;
    border-radius: 8px;
    background: var(--pos-tracker-green-soft);
    color: #166534;
    font-size: 11px;
    font-weight: 800;
    border: 1px solid rgba(26, 183, 89, 0.35);
    white-space: nowrap;
}

/* [CAISSE-WEB-INTEL 2026-08-06] Badge type livraison 🛵 sur la carte. */
.pos-tracker-card-type-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 26px;
    height: 26px;
    border-radius: 8px;
    background: var(--pos-tracker-blue-soft);
    font-size: 14px;
}

/* [CAISSE-WEB-INTEL 2026-08-06] Badge commande programmée « 🕐 pour 19:30 ». */
.pos-tracker-card-scheduled {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    align-self: flex-start;
    padding: 2px 8px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 700;
    background: var(--pos-tracker-blue-soft);
    color: var(--pos-tracker-blue);
    border: 1px solid rgba(29, 78, 216, 0.30);
}

/* [CAISSE-WEB-INTEL 2026-08-06] Bandeau instruction client (allergie / note). */
.pos-tracker-card-instruction {
    display: flex;
    align-items: flex-start;
    gap: 6px;
    padding: 5px 8px;
    border-radius: 8px;
    background: #FEF3C7;
    color: #92400E;
    border: 1px solid rgba(217, 119, 6, 0.30);
    font-size: 12px;
    font-weight: 600;
    line-height: 1.35;
    word-break: break-word;
}

.pos-tracker-card-time {
    margin-left: auto;
    font-size: 12px;
    font-weight: 600;
    color: var(--pos-tracker-muted);
}

.pos-tracker-card-customer {
    font-size: 12px;
    color: var(--pos-tracker-muted);
    display: flex;
    align-items: center;
    gap: 6px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.pos-tracker-card-customer span {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

/* [OWNER 2026-07-31] Téléphone client = action visible (rappel de confirmation
   commande web). Accent brand pour ressortir, tappable (tel:) sur tablette caisse. */
.pos-tracker-card-phone {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    flex: none;
    font-weight: 700;
    color: var(--pos-brand, #F4501E);
    text-decoration: none;
    white-space: nowrap;
}
.pos-tracker-card-phone:hover {
    text-decoration: underline;
}

.pos-tracker-card-items {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: 3px;
}

.pos-tracker-card-items li {
    display: flex;
    gap: 6px;
    font-size: 12px;
    color: var(--pos-tracker-text);
    line-height: 1.35;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.pos-tracker-card-qty {
    font-weight: 700;
    color: var(--pos-tracker-primary);
    flex-shrink: 0;
    min-width: 22px;
}

.pos-tracker-card-name {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.pos-tracker-card-more {
    color: var(--pos-tracker-muted);
    font-style: italic;
    font-size: 11px;
}

/* ─────────────────────────────────────────────────────────────────────────
   [GOAL-CAISSE-VISION 2026-08-24] La composition sous chaque produit.

   La ligne produit passe en `flex-wrap: wrap` pour que la composition prenne
   sa PROPRE ligne (`flex: 1 0 100%`) au lieu d'être écrasée à côté du nom.
   Le nom conserve son ellipse : `min-width: 0` + `flex: 0 1 auto` empêchent
   qu'un nom long pousse la mise en page — c'est la condition pour que le
   passage en `wrap` ne change RIEN aux cartes sans personnalisation.
   ───────────────────────────────────────────────────────────────────────── */
.pos-tracker-card-items li {
    flex-wrap: wrap;
}

.pos-tracker-card-name {
    min-width: 0;
    flex: 0 1 auto;
}

.pos-tracker-card-compo {
    flex: 1 0 100%;
    padding-left: 22px;          /* aligné sous le nom, après la quantité */
    font-size: 11px;
    line-height: 1.3;
    color: var(--pos-tracker-muted);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

/* Le bouton « Voir tout » — discret tant qu'on ne le cherche pas, mais assez
   large pour être touché du pouce en plein service (cible ≥ 32 px). */
.pos-tracker-card-voirtout {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    align-self: flex-start;
    margin-top: 2px;
    min-height: 32px;
    padding: 4px 10px;
    border: 1px solid var(--pos-tracker-border);
    border-radius: 8px;
    background: transparent;
    color: var(--pos-tracker-text);
    font-size: 11px;
    font-weight: 700;
    cursor: pointer;
}
.pos-tracker-card-voirtout:hover,
.pos-tracker-card-voirtout:focus-visible {
    background: var(--pos-tracker-blue-soft);
    color: var(--pos-tracker-blue);
    border-color: rgba(29, 78, 216, 0.35);
}

/* ── Panneau « Voir tout » ─────────────────────────────────────────────── */
.pos-tracker-contenu-overlay {
    position: fixed;
    inset: 0;
    z-index: 1080;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 16px;
    background: rgba(15, 23, 42, 0.55);
}

.pos-tracker-contenu-card {
    width: min(560px, 100%);
    max-height: min(80vh, 720px);
    display: flex;
    flex-direction: column;
    background: var(--pos-v5-bg-panel, #fff);
    color: var(--pos-tracker-text);
    border-radius: 14px;
    box-shadow: 0 24px 60px rgba(15, 23, 42, 0.35);
    overflow: hidden;
}

.pos-tracker-contenu-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 14px 16px;
    border-bottom: 1px solid var(--pos-tracker-border);
}
.pos-tracker-contenu-head h3 {
    margin: 0;
    font-size: 16px;
    font-weight: 800;
}
.pos-tracker-contenu-num { font-variant-numeric: tabular-nums; }
/* Le compilateur Vue supprime le nœud d'espace entre deux `<span>` séparés par
   un retour à la ligne : sans cette marge le titre se lit « #GCV24-COMPO— Admin ». */
.pos-tracker-contenu-client { font-weight: 600; margin-left: 6px; }

.pos-tracker-contenu-close {
    flex: 0 0 auto;
    width: 36px;
    height: 36px;
    border: 1px solid var(--pos-tracker-border);
    border-radius: 8px;
    background: transparent;
    color: inherit;
    cursor: pointer;
}

.pos-tracker-contenu-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    margin: 0;
    padding: 8px 16px;
    font-size: 12px;
    color: var(--pos-tracker-muted);
    border-bottom: 1px solid var(--pos-tracker-border);
}
.pos-tracker-contenu-total {
    margin-left: auto;
    font-weight: 800;
    color: var(--pos-tracker-text);
}

.pos-tracker-contenu-body {
    flex: 1 1 auto;
    overflow-y: auto;
    padding: 12px 16px;
}

.pos-tracker-contenu-lignes {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.pos-tracker-contenu-ligne + .pos-tracker-contenu-ligne {
    border-top: 1px dashed var(--pos-tracker-border);
    padding-top: 12px;
}

.pos-tracker-contenu-produit {
    display: flex;
    gap: 8px;
    margin: 0 0 4px;
    font-size: 14px;
    font-weight: 800;
}
.pos-tracker-contenu-qty { font-variant-numeric: tabular-nums; }

.pos-tracker-contenu-detail {
    list-style: none;
    margin: 0 0 4px;
    padding-left: 26px;
    display: flex;
    flex-direction: column;
    gap: 2px;
    font-size: 13px;
}
.pos-tracker-contenu-ligne-extras,
.pos-tracker-contenu-ligne-addons {
    margin: 0 0 4px;
    padding-left: 26px;
    font-size: 13px;
}
.pos-tracker-contenu-label {
    font-weight: 700;
    color: var(--pos-tracker-muted);
    margin-right: 4px;
}
.pos-tracker-contenu-mult { margin-left: 4px; font-weight: 700; }

/* L'instruction porte les allergies : elle doit sauter aux yeux, et n'est
   jamais tronquée dans ce panneau. */
.pos-tracker-contenu-instruction {
    margin: 4px 0 0;
    padding: 6px 8px 6px 26px;
    font-size: 13px;
    font-weight: 700;
    color: #92400e;
    background: rgba(245, 158, 11, 0.12);
    border-radius: 8px;
}

.pos-tracker-contenu-vide {
    margin: 0;
    padding: 16px 0;
    text-align: center;
    color: var(--pos-tracker-muted);
    font-size: 13px;
}

.pos-tracker-contenu-foot {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 10px;
    padding: 12px 16px;
    border-top: 1px solid var(--pos-tracker-border);
}
.pos-tracker-contenu-fiche {
    font-size: 13px;
    font-weight: 700;
    color: var(--pos-tracker-blue);
    text-decoration: underline;
}
.pos-tracker-contenu-ok {
    min-height: 40px;
    padding: 0 18px;
    border: 0;
    border-radius: 10px;
    background: var(--pos-tracker-blue, #1d4ed8);
    color: #fff;
    font-weight: 800;
    cursor: pointer;
}

/* [S2 F1 révisé 2026-07-29] Bandeau « anciennes commandes à encaisser ». */
.pos-tracker-older-pending {
    display: block;
    margin: 0 0 10px;
    padding: 8px 12px;
    border-radius: 8px;
    background: #fff7ed;
    border: 1px solid #fed7aa;
    color: #9a3412;
    font-size: 13px;
    font-weight: 600;
}
.pos-tracker-older-pending:hover { background: #ffedd5; }

/* [S2 V4 2026-07-29] `flex-wrap` + `gap` : sur une carte « à encaisser », le
   bloc montant porte le libellé « À ENCAISSER : » et les actions comptent 4
   boutons — en une seule rangée non-wrappable, le libellé était rogné puis
   recouvert par le bouton Encaisser. Il descend maintenant sur sa propre
   rangée quand la place manque. */
.pos-tracker-card-foot {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 6px;
    padding-top: 6px;
    border-top: 1px dashed var(--pos-tracker-border);
}

.pos-tracker-card-total {
    font-size: 14px;
    font-weight: 700;
    color: var(--pos-tracker-text);
}

/* [T-SUIVI-LAYOUT 2026-08-19 · GOAL owner] « je dois scroller à gauche et à
   droite pour voir une commande ».
   CAUSE MESURÉE en direct (viewport 1728 px, barre latérale dépliée à 260 px,
   5 voies de 266 px) : cette rangée de boutons faisait 218 px pour 215 px
   disponibles — et `nowrap` + `min-width:auto` par défaut sur un item flex la
   rendaient INCOMPRESSIBLE. Sur une carte de commande web (sélecteur de temps de
   préparation ~90 px + « Ticket promo » ~105 px + « Accepter » ~105 px + 3 icônes)
   le débordement dépasse 150 px. Elle passe désormais à la ligne. */
.pos-tracker-card-actions {
    display: flex;
    flex-wrap: wrap;
    justify-content: flex-end;
    min-width: 0;
    gap: 6px;
}

.pos-tracker-card-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 5px 10px;
    border-radius: 8px;
    border: 1px solid var(--pos-tracker-border);
    background: #fff;
    color: var(--pos-tracker-text);
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    text-decoration: none;
    transition: background 0.15s ease, border-color 0.15s ease, color 0.15s ease;
}

.pos-tracker-card-btn:hover {
    background: var(--pos-tracker-primary-soft);
    border-color: var(--pos-tracker-primary);
    color: var(--pos-tracker-primary);
}

.pos-tracker-card-btn--primary {
    background: var(--pos-tracker-green);
    border-color: var(--pos-tracker-green);
    color: #fff;
}

.pos-tracker-card-btn--primary:hover {
    background: #15a151;
    border-color: #15a151;
    color: #fff;
}

.pos-tracker-card-btn--primary:disabled {
    opacity: 0.6;
    cursor: not-allowed;
}

/* [Wave S-4 P-OWNER 2026-05-20] Encaisser CTA — amber primary action.
 * Visually loud enough that the cashier can't miss it but stays within
 * the existing V5 design token palette (warning tone, not error). */
.pos-tracker-card-btn--cash {
    background: var(--pos-tracker-amber);
    border-color: var(--pos-tracker-amber);
    color: #fff;
}
.pos-tracker-card-btn--cash:hover {
    background: #d97706;
    border-color: #d97706;
    color: #fff;
}
.pos-tracker-card-btn--cash:focus-visible {
    outline: 2px solid #fbbf24;
    outline-offset: 2px;
}

/* [POS-V4-CASHIER-OPS 2026-05-02] danger variant for cancel-order */
.pos-tracker-card-btn--danger {
    border-color: rgba(239, 68, 68, 0.4);
    color: #b91c1c;
    background: #fff;
}
.pos-tracker-card-btn--danger:hover {
    background: #fee2e2;
    border-color: #ef4444;
    color: #991b1b;
}
.pos-tracker-card-btn--danger:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

/* [POS-V4-CASHIER-OPS 2026-05-02] cancel-with-reason inline dialog */
.pos-tracker-cancel-overlay {
    position: fixed;
    inset: 0;
    z-index: 2400;
    background: rgba(15, 23, 42, 0.45);
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 24px;
}
.pos-tracker-cancel-card {
    width: min(480px, 100%);
    background: #fff;
    border-radius: 16px;
    box-shadow: 0 24px 48px rgba(15, 23, 42, 0.24);
    display: flex;
    flex-direction: column;
    overflow: hidden;
}
.pos-tracker-cancel-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 16px 20px;
    border-bottom: 1px solid var(--pos-tracker-border);
}
.pos-tracker-cancel-head h3 {
    margin: 0;
    font-size: 16px;
    font-weight: 700;
    color: var(--pos-tracker-text);
}
.pos-tracker-cancel-close {
    width: 32px;
    height: 32px;
    border-radius: 999px;
    border: 1px solid var(--pos-tracker-border);
    background: #fff;
    color: var(--pos-tracker-muted);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: background 0.15s ease, color 0.15s ease;
}
.pos-tracker-cancel-close:hover {
    background: #fee2e2;
    color: #b91c1c;
}
.pos-tracker-cancel-body {
    padding: 16px 20px;
    display: flex;
    flex-direction: column;
    gap: 10px;
}
.pos-tracker-cancel-target {
    margin: 0 0 4px;
    font-size: 13px;
    color: var(--pos-tracker-muted);
}
.pos-tracker-cancel-target strong {
    color: var(--pos-tracker-text);
    font-weight: 700;
}
.pos-tracker-cancel-label {
    font-size: 13px;
    font-weight: 600;
    color: var(--pos-tracker-text);
}
/* [CAISSE-WEB-INTEL 2026-08-06] Select temps de préparation (accept web). */
.pos-tracker-prep-select {
    height: 30px;
    padding: 0 6px;
    border-radius: 8px;
    border: 1px solid var(--pos-tracker-border);
    background: #fff;
    color: var(--pos-tracker-text);
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
}
.pos-tracker-prep-select:focus-visible {
    outline: 2px solid var(--pos-tracker-amber);
    outline-offset: 1px;
}

/* [CAISSE-WEB-INTEL 2026-08-06] Chips raisons d'annulation 1-geste. */
.pos-tracker-cancel-chips {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
}
.pos-tracker-cancel-chip {
    padding: 6px 12px;
    border-radius: 999px;
    border: 1px solid var(--pos-tracker-border);
    background: #fff;
    color: var(--pos-tracker-text);
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    transition: background 0.15s ease, border-color 0.15s ease, color 0.15s ease;
}
.pos-tracker-cancel-chip:hover {
    background: #fee2e2;
    border-color: #ef4444;
    color: #991b1b;
}
.pos-tracker-cancel-chip.is-active {
    background: #ef4444;
    border-color: #ef4444;
    color: #fff;
}

.pos-tracker-cancel-textarea {
    width: 100%;
    min-height: 84px;
    padding: 10px 12px;
    border: 1px solid var(--pos-tracker-border);
    border-radius: 10px;
    font-size: 14px;
    color: var(--pos-tracker-text);
    background: #f9fafb;
    resize: vertical;
    transition: border-color 0.15s ease, background 0.15s ease;
}
.pos-tracker-cancel-textarea:focus {
    outline: none;
    border-color: #ef4444;
    background: #fff;
}
.pos-tracker-cancel-error {
    margin: 0;
    padding: 8px 10px;
    background: #fee2e2;
    border: 1px solid #fecaca;
    border-radius: 8px;
    color: #991b1b;
    font-size: 12px;
    font-weight: 600;
}
/*
 * [test-e2e/pos-kds-sync round-4 E-001 P0 2026-05-10]
 * Persistent error banner — visually distinct from a transient toast.
 * Stays inside the cancel dialog until the user dismisses or closes.
 * Solid red left-border + icon + bold copy = unmistakable failure signal.
 */
.pos-tracker-cancel-error-banner {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    margin: 12px 0 0 0;
    padding: 12px 14px;
    background: #fef2f2;
    border: 1px solid #fecaca;
    border-left: 4px solid #dc2626;
    border-radius: 8px;
    color: #991b1b;
    font-size: 13px;
    font-weight: 600;
    line-height: 1.45;
}
.pos-tracker-cancel-error-icon {
    flex-shrink: 0;
    color: #dc2626;
    font-size: 16px;
    line-height: 1.45;
}
.pos-tracker-cancel-error-msg {
    flex: 1;
    word-break: break-word;
}
.pos-tracker-cancel-foot {
    display: flex;
    justify-content: flex-end;
    gap: 8px;
    padding: 12px 20px;
    border-top: 1px solid var(--pos-tracker-border);
    background: #f9fafb;
}
.pos-tracker-cancel-btn {
    height: 38px;
    padding: 0 18px;
    border-radius: 10px;
    border: 1px solid var(--pos-tracker-border);
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    transition: background 0.15s ease, color 0.15s ease, border-color 0.15s ease;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}
.pos-tracker-cancel-btn--ghost {
    background: #fff;
    color: var(--pos-tracker-text);
}
.pos-tracker-cancel-btn--ghost:hover {
    background: var(--pos-tracker-muted-soft);
}
.pos-tracker-cancel-btn--ghost:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}
.pos-tracker-cancel-btn--danger {
    background: #ef4444;
    border-color: #ef4444;
    color: #fff;
}
.pos-tracker-cancel-btn--danger:hover {
    background: #dc2626;
    border-color: #dc2626;
}
.pos-tracker-cancel-btn--danger:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.pos-tracker-loading {
    margin-top: 24px;
    text-align: center;
    color: var(--pos-tracker-muted);
}

.pos-tracker-spinner {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    border: 3px solid var(--pos-tracker-border);
    border-top-color: var(--pos-tracker-primary);
    margin: 0 auto 10px;
    animation: pos-tracker-spin 0.9s linear infinite;
}

@keyframes pos-tracker-spin {
    to { transform: rotate(360deg); }
}

@keyframes pos-tracker-soft-pulse {
    0%, 100% { box-shadow: 0 0 0 0 rgba(26, 183, 89, 0); }
    50%      { box-shadow: 0 0 0 6px rgba(26, 183, 89, 0.18); }
}

/* Transition group animations for cards moving between columns */
.pos-tracker-card-enter-active,
.pos-tracker-card-leave-active {
    transition: opacity 0.25s ease, transform 0.25s ease;
}

.pos-tracker-card-enter-from { opacity: 0; transform: translateY(-6px); }
.pos-tracker-card-leave-to { opacity: 0; transform: translateY(6px); }

@media (prefers-reduced-motion: reduce) {
    .pos-tracker-status-pill--web,
    .pos-tracker-status-pill--ready,
    .pos-tracker-col--green.is-pulse,
    .pos-tracker-card.tracker-card--urgent,
    .pos-tracker-card.is-fresh { animation: none; }
}
/* ─────────────────────────────────────────────────────────────────────────────
   [COMMANDES EN SOUFFRANCE + BOUTON SCELLÉ 2026-08-19]
   ───────────────────────────────────────────────────────────────────────────── */

.pos-tracker-status-pill--stale {
    background: #FEF3C7;
    color: #92400E;
    border: 1px solid #FCD34D;
    cursor: pointer;
}
.pos-tracker-status-pill--stale:hover { background: #FDE68A; }

/* Marqueur inerte : la commande est clôturée dans un Z et ce compte ne peut pas
   émettre la contrepartie. Ce n'est PAS un bouton — pas de curseur main, pas de
   clic : un bouton mort est exactement ce qu'on vient de supprimer. */
.pos-tracker-card-btn--sealed {
    background: #F3F4F6;
    color: #6B7280;
    border: 1px dashed #D1D5DB;
    cursor: default;
}

.pos-tracker-stale {
    margin: 12px 0 0;
    padding: 12px 16px;
    background: #FFFBEB;
    border: 1px solid #FCD34D;
    border-radius: 10px;
    /* Déclaré EXPLICITEMENT : non déclaré, `overflow-x` est recalculé en `auto` face à un
       `overflow-y` non-`visible` (CSS Overflow 3). C'est précisément ce qui a produit le
       scroll horizontal de cet écran le 2026-08-19 — on ne le refait pas. */
    overflow-x: hidden;
}
.pos-tracker-stale-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 8px;
    margin-bottom: 8px;
}
.pos-tracker-stale-head h2 {
    margin: 0;
    font-size: 14px;
    font-weight: 800;
    color: #92400E;
}
.pos-tracker-stale-head-right { display: flex; align-items: center; gap: 6px; }
.pos-tracker-stale-truncated { font-size: 12px; font-weight: 700; color: #92400E; }
.pos-tracker-stale-msg { margin: 6px 0; font-size: 13px; color: #78350F; }
.pos-tracker-stale-msg--error { color: #B91C1C; font-weight: 700; }

.pos-tracker-stale-list {
    list-style: none;
    margin: 0;
    padding: 0;
    /* Le panneau ne doit pas pousser les voies hors de l'écran : il défile chez lui. */
    max-height: 340px;
    overflow-y: auto;
    overflow-x: hidden;
}
.pos-tracker-stale-row {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 6px 12px;
    padding: 6px 4px;
    border-bottom: 1px solid #FDE68A;
    font-size: 13px;
}
.pos-tracker-stale-row:last-child { border-bottom: 0; }
.pos-tracker-stale-serial {
    font-family: 'JetBrains Mono', ui-monospace, monospace;
    font-weight: 800;
    min-width: 92px;
}
.pos-tracker-stale-date { color: #78350F; min-width: 128px; }
.pos-tracker-stale-status { font-weight: 700; }
.pos-tracker-stale-total { font-weight: 800; margin-left: auto; }
.pos-tracker-stale-sealed { font-size: 12px; font-weight: 700; color: #6B7280; }
.pos-tracker-stale-actions { display: flex; align-items: center; gap: 4px; }
</style>