<template>
    <!--
        [Wave X — X4 2026-05-21] Admin unified cash & transactions overview.
        Owner mandate (verbatim translated):
          « Toutes les commandes encaissées (POS direct, borne cash-collected,
            livreur) au MÊME ENDROIT en base. Admin part + caisse voient tout.
            Pour chaque transaction : source (borne/caisse/livreur) + mode
            paiement + total. Totaux par source + grand total. Permet de
            détecter écarts (cash manquant). »

        Source: GET /api/admin/cash-overview (CashOverviewController).
        Branch isolation: backend-driven (admin sees all; staff sees own).
        Sibling: /admin/cash-sessions-report (Wave O O4) stays as detail view.
    -->
    <div class="col-12">
        <div class="db-card db-tab-div active">
            <div class="db-card-header border-none">
                <h3 class="db-card-title">{{ $t('menu.cash_overview') }}</h3>
            </div>

            <div class="db-card-body">
                <!-- Filters -->
                <form
                    class="p-4 sm:p-5 mb-3 flex flex-wrap items-end gap-3"
                    data-testid="cash-overview-filters"
                    @submit.prevent="applyFilters"
                >
                    <div>
                        <label for="cashOverviewFrom" class="db-field-title after:hidden">{{ $t('label.from_date') }}</label>
                        <input id="cashOverviewFrom" v-model="filters.from" type="date" class="db-field-control" />
                    </div>
                    <div>
                        <label for="cashOverviewTo" class="db-field-title after:hidden">{{ $t('label.to_date') }}</label>
                        <input id="cashOverviewTo" v-model="filters.to" type="date" class="db-field-control" />
                    </div>
                    <div>
                        <label for="cashOverviewSource" class="db-field-title after:hidden">{{ $t('label.source') }}</label>
                        <select id="cashOverviewSource" v-model="filters.source" class="db-field-control">
                            <option value="">{{ $t('label.all_sources') }}</option>
                            <option value="caisse">{{ $t('label.source_caisse') }}</option>
                            <option value="borne">{{ $t('label.source_borne') }}</option>
                            <option value="livreur">{{ $t('label.source_livreur') }}</option>
                        </select>
                    </div>
                    <div>
                        <label for="cashOverviewMode" class="db-field-title after:hidden">{{ $t('label.payment_method') }}</label>
                        <!--
                            [Wave Z Q7 2026-05-21] Owner-mandated removal of the
                            `Autre` option — it was a silent no-op (backend's
                            'other' bucket has no LIKE patterns; cf. controller
                            line 486). `modeLabel('other')` is kept intact so
                            rows whose `mode_bucket='other'` (rare fallback)
                            still render gracefully in the table.
                        -->
                        <select id="cashOverviewMode" v-model="filters.mode" class="db-field-control">
                            <option value="">{{ $t('label.all_methods') }}</option>
                            <option value="cash">{{ $t('label.mode_cash') }}</option>
                            <option value="card">{{ $t('label.mode_card') }}</option>
                            <option value="mobile">{{ $t('label.mode_mobile') }}</option>
                            <option value="ticket">{{ $t('label.mode_ticket') }}</option>
                        </select>
                    </div>
                    <button
                        type="submit"
                        class="db-btn py-2 text-white bg-primary"
                        data-testid="cash-overview-search"
                    >
                        <i class="lab lab-search-line lab-font-size-16"></i>
                        <span>{{ $t('button.search') }}</span>
                    </button>
                    <button
                        type="button"
                        class="db-btn py-2 text-white bg-gray-600"
                        data-testid="cash-overview-clear"
                        @click="clearFilters"
                    >
                        <i class="lab lab-cross-line-2 lab-font-size-22"></i>
                        <span>{{ $t('button.clear') }}</span>
                    </button>
                </form>

                <!-- Summary cards -->
                <section
                    v-if="!loading && summary"
                    class="px-4 sm:px-5 mb-4 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3"
                    data-testid="cash-overview-summary"
                >
                    <div class="border rounded-lg p-3 bg-white">
                        <div class="text-xs uppercase text-gray-500">{{ $t('label.grand_total') }}</div>
                        <div class="text-2xl font-bold text-primary mt-1">{{ formatMoney(summary.total) }}</div>
                        <div class="text-xs text-gray-500 mt-1">
                            {{ summary.count }} {{ $t('label.transactions_short') }}
                        </div>
                    </div>
                    <div
                        v-for="src in displayedSources"
                        :key="`src-${src.key}`"
                        class="border rounded-lg p-3 bg-white"
                        :data-testid="`cash-overview-source-${src.key}`"
                    >
                        <div class="text-xs uppercase text-gray-500">{{ src.label }}</div>
                        <div class="text-xl font-semibold mt-1">{{ formatMoney(src.stat.total) }}</div>
                        <div class="text-xs text-gray-500 mt-1">
                            {{ src.stat.count }} {{ $t('label.transactions_short') }}
                        </div>
                    </div>
                </section>

                <!-- Cash drawer reconciliation card -->
                <!--
                    [Wave X-C round-2 2026-05-21] Fix C-013 — the previous
                    diff cell was algebraically locked to `-opening_amount`
                    (cashDiff = collected - (opening + collected) = -opening),
                    so it always displayed `-100 € (manquant)` even when the
                    drawer was perfectly healthy. A real écart requires a
                    `counted_physical_cash` input compared to `expected_cash`
                    (= opening + collected). The cashier-count input is V1.0.2
                    backlog; until then we show 3 HONEST values + an info
                    note. No misleading diff cell.
                    Source: GET /api/admin/cash-overview cash_session payload.
                -->
                <section
                    v-if="!loading && cashSession"
                    class="px-4 sm:px-5 mb-4"
                    data-testid="cash-overview-reconciliation"
                >
                    <div class="border-l-4 border-amber-500 bg-amber-50 rounded p-3">
                        <div class="flex flex-wrap items-center gap-2 text-sm font-semibold text-amber-900">
                            <span>{{ $t('label.cash_drawer_reconciliation') }}</span>
                            <!--
                                [FIX-3 2026-08-25] Age badge. The card used to
                                render a bare `formatTime()` clock, which made a
                                drawer left open for 50 days look like it had
                                been opened this morning.
                            -->
                            <span
                                v-if="drawerAgeLabel"
                                class="px-2 py-0.5 rounded-full bg-amber-200 text-amber-900 text-xs font-medium"
                                data-testid="cash-overview-reconciliation-age"
                            >{{ drawerAgeLabel }}</span>
                        </div>
                        <div class="mt-2 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 text-sm">
                            <div>
                                <span class="text-gray-600">{{ $t('label.drawer_opened_at') }}:</span>
                                <!-- Dated, never a bare clock, unless it really is today. -->
                                <strong
                                    class="ml-1"
                                    data-testid="cash-overview-reconciliation-opened-at"
                                >{{ formatDateTime(cashSession.opened_at) }}</strong>
                            </div>
                            <div>
                                <span class="text-gray-600">{{ $t('label.opening_amount') }}:</span>
                                <strong
                                    class="ml-1"
                                    data-testid="cash-overview-reconciliation-opening"
                                >{{ formatMoneyEuro(cashSession.opening_amount) }}</strong>
                            </div>
                            <div>
                                <!--
                                    [FIX-3] Le chiffre etait celui de TOUTE la session sous un
                                    libelle « aujourd'hui » : la page parlait d'especes prises 45
                                    jours plus tot. Restreint a la periode.

                                    [AUDIT-SUPERVISEUR 2026-08-25 · D-001, P0] La contradiction
                                    SUBSISTAIT, parce que restreindre le chiffre ne suffisait pas :
                                    le libelle disait « Especes ENCAISSEES », donc des ventes, sur
                                    un nombre qui est la somme signee des MOUVEMENTS DU TIROIR
                                    (appoints, prelevements, saisies manuelles) du seul tiroir
                                    ouvert. Deux grandeurs differentes sous deux libelles quasi
                                    identiques, a 40 px l'une de l'autre : la banniere annoncait
                                    7,50 € quand « Repartition par mode » montrait 5,00 € et que
                                    la page ne contenait que deux lignes especes a 2,50 €. Les
                                    2,50 € restants n'etaient NULLE PART.

                                    On nomme donc chaque grandeur, et surtout on affiche leur
                                    ECART — c'est lui le signal de piste d'especes que cette
                                    banniere pretend surveiller, et il n'etait affiche nulle part.
                                -->
                                <span class="text-gray-600">{{ $t('label.cash_sales_in_period') }}:</span>
                                <strong
                                    class="ml-1"
                                    data-testid="cash-overview-reconciliation-sales"
                                >{{ formatMoneyEuro(ventesEspecesPeriode) }}</strong>
                            </div>
                            <div>
                                <span class="text-gray-600">{{ $t('label.cash_collected_in_period') }}:</span>
                                <strong
                                    class="ml-1"
                                    data-testid="cash-overview-reconciliation-collected"
                                >{{ formatMoneyEuro(cashSession.cash_collected_in_period) }}</strong>
                            </div>
                            <div :title="$t('label.cash_reconciliation_gap_help')">
                                <span class="text-gray-600">{{ $t('label.cash_reconciliation_gap') }}:</span>
                                <strong
                                    class="ml-1"
                                    :class="ecartEspeces === 0 ? 'text-emerald-800' : 'text-red-700'"
                                    data-testid="cash-overview-reconciliation-gap"
                                >{{ ecartEspeces === 0
                                    ? $t('label.cash_reconciliation_gap_none')
                                    : formatMoneyEuro(ecartEspeces) }}</strong>
                            </div>
                            <div>
                                <span class="text-gray-600">{{ $t('label.expected_in_drawer') }}:</span>
                                <strong
                                    class="ml-1 text-amber-900"
                                    data-testid="cash-overview-reconciliation-expected"
                                >{{ formatMoneyEuro(cashSession.expected_cash) }}</strong>
                            </div>
                        </div>
                        <div
                            class="mt-2 text-xs text-amber-800"
                            data-testid="cash-overview-reconciliation-note"
                        >
                            <!--
                                `expected_cash` is a LIFETIME figure (fond +
                                every movement since opening). Say so explicitly
                                whenever the drawer predates the window, so the
                                two amounts above can never be read as the same
                                period.
                            -->
                            <span v-if="drawerAgeLabel" data-testid="cash-overview-reconciliation-lifetime-note">
                                Tiroir ouvert le {{ formatDateTime(cashSession.opened_at) }} et jamais clôturé —
                                « {{ $t('label.expected_in_drawer') }} » cumule
                                {{ formatMoneyEuro(cashSession.cash_collected) }} de mouvements depuis l'ouverture,
                                pas seulement la période affichée.
                            </span>
                            <span v-else>{{ $t('label.cash_drawer_count_pending_note') }}</span>
                        </div>
                    </div>
                </section>

                <!--
                    [FIX-3 2026-08-25] Drawers left `open` with no activity over
                    the displayed period. Bounding the reconciliation card to the
                    period is the honest fix, but on its own it would hide the
                    abandoned drawers that still hold their fond de caisse — and
                    it would leave « pourquoi aucun tiroir ? » unanswered. Owner
                    mandate « détecter écarts (cash manquant) ». Read-only: this
                    page never closes a session.
                -->
                <section
                    v-if="!loading && staleOpenDrawers && staleOpenDrawers.count > 0"
                    class="px-4 sm:px-5 mb-4"
                    data-testid="cash-overview-stale-drawers"
                >
                    <div class="border-l-4 border-orange-400 bg-orange-50 rounded p-3">
                        <div class="flex items-center text-sm font-semibold text-orange-800">
                            <span class="mr-2">🗄️</span>
                            <span>Tiroirs restés ouverts</span>
                        </div>
                        <div
                            class="mt-1 text-sm text-orange-700"
                            data-testid="cash-overview-stale-drawers-message"
                        >
                            {{ staleOpenDrawers.message }}
                        </div>
                        <div class="mt-2 text-xs text-orange-600">
                            Sessions concernées : #{{ staleOpenDrawers.ids.join(', #') }} — leur fond de caisse
                            n'est rattaché à aucune clôture. À clôturer depuis la caisse.
                        </div>
                    </div>
                </section>

                <!--
                    [TRAP-3 2026-06-04] Cash-trail discrepancy banner. Flags cash
                    collected at the counter with NO open drawer session: the
                    order is PAID + fiscal-seq allocated but NO cash_movement row
                    exists, so end-of-day reconciliation silently under-counts.
                    The sale was never blocked (NF525-safe) — this makes the gap
                    VISIBLE where the écart manifests, not only via the ephemeral
                    collect-time toast. Owner mandate « détecter écarts (cash
                    manquant) ». Hidden when count === 0 (no false alarm).
                -->
                <section
                    v-if="!loading && unrecordedCash && unrecordedCash.count > 0"
                    class="px-4 sm:px-5 mb-4"
                    data-testid="cash-overview-unrecorded-cash"
                >
                    <div class="border-l-4 border-red-500 bg-red-50 rounded p-3">
                        <div class="flex items-center text-sm font-semibold text-red-800">
                            <span class="mr-2">⚠️</span>
                            <span data-testid="cash-overview-unrecorded-cash-title">
                                Encaissements espèces sans session caisse
                            </span>
                        </div>
                        <div class="mt-1 text-sm text-red-700" data-testid="cash-overview-unrecorded-cash-message">
                            {{ unrecordedCash.message }}
                        </div>
                        <div class="mt-2 grid grid-cols-1 sm:grid-cols-2 gap-2 text-sm">
                            <div>
                                <span class="text-gray-600">Commandes concernées:</span>
                                <strong
                                    class="ml-1 text-red-800"
                                    data-testid="cash-overview-unrecorded-cash-count"
                                >{{ unrecordedCash.count }}</strong>
                            </div>
                            <div>
                                <span class="text-gray-600">Montant non rattaché:</span>
                                <strong
                                    class="ml-1 text-red-800"
                                    data-testid="cash-overview-unrecorded-cash-total"
                                >{{ unrecordedCash.total_currency_price || formatMoneyEuro(unrecordedCash.total) }}</strong>
                            </div>
                        </div>
                        <div class="mt-2 text-xs text-red-600">
                            À régulariser : ouvrir une session caisse avant d'encaisser, ou rattacher ces montants au fond de caisse lors de la clôture.
                        </div>
                    </div>
                </section>

                <!-- Mode breakdown -->
                <section
                    v-if="!loading && summary && Object.keys(summary.by_mode || {}).length"
                    class="px-4 sm:px-5 mb-4"
                    data-testid="cash-overview-mode-breakdown"
                >
                    <h4 class="text-sm font-semibold text-gray-700 mb-2">{{ $t('label.breakdown_by_method') }}</h4>
                    <div class="flex flex-wrap gap-2">
                        <div
                            v-for="(stat, mode) in summary.by_mode"
                            :key="`mode-${mode}`"
                            class="border rounded-full px-3 py-1 text-xs bg-gray-50"
                            :data-testid="`cash-overview-mode-${mode}`"
                        >
                            <strong>{{ modeLabel(mode) }}</strong>
                            <span class="text-gray-500 ml-1">{{ stat.count }} · {{ formatMoney(stat.total) }}</span>
                        </div>
                    </div>
                </section>

                <!-- Loading state -->
                <div v-if="loading" class="p-6 text-center text-gray-500">
                    {{ $t('label.loading') }}…
                </div>

                <!--
                    [Wave Z Q6 2026-05-21] Empty-state polish — owner mandate :
                    illustration (inline SVG, brand-tone, aria-hidden) + copy
                    ≥20 chars + primary reset CTA. Replaces the previous bare
                    `Aucune donnée` plain-text dead-end which left admins
                    wondering whether the page had failed silently.
                -->
                <div
                    v-else-if="!transactions.length"
                    class="p-8 text-center"
                    data-testid="cash-overview-empty"
                >
                    <svg
                        class="mx-auto mb-4 text-gray-300"
                        xmlns="http://www.w3.org/2000/svg"
                        width="96"
                        height="96"
                        viewBox="0 0 96 96"
                        fill="none"
                        aria-hidden="true"
                        data-testid="cash-overview-empty-illustration"
                    >
                        <!-- Cayenne brand-tone: subtle cash-drawer outline + diagonal line through, no decorative noise -->
                        <rect x="14" y="32" width="68" height="44" rx="4" stroke="currentColor" stroke-width="3"/>
                        <line x1="14" y1="48" x2="82" y2="48" stroke="currentColor" stroke-width="3"/>
                        <circle cx="48" cy="62" r="6" stroke="currentColor" stroke-width="3"/>
                        <line x1="20" y1="20" x2="76" y2="84" stroke="#d1d5db" stroke-width="3" stroke-linecap="round"/>
                    </svg>
                    <p
                        class="text-base text-gray-700 max-w-md mx-auto mb-4"
                        data-testid="cash-overview-empty-copy"
                    >
                        {{ unFiltreEstActif ? $t('label.cash_overview_empty_copy') : $t('label.cash_overview_empty_vierge') }}
                    </p>
                    <!--
                        [ONB 2026-08-28] Ce bouton n'etait qu'a moitie honnete :
                        `clearFilters()` remet `from = to = aujourd'hui,
                        source = '', mode = ''`, c'est-a-dire l'etat PAR DEFAUT.
                        Sans filtre actif il ne faisait donc RIEN, tout en
                        affirmant qu'il y avait quelque chose a reinitialiser.
                    -->
                    <button
                        v-if="unFiltreEstActif"
                        type="button"
                        class="db-btn py-2 px-4 text-white bg-primary"
                        data-testid="cash-overview-empty-reset"
                        @click="clearFilters"
                    >
                        {{ $t('button.reset_filters') }}
                    </button>
                </div>

                <!-- Transactions table -->
                <div v-else class="px-4 sm:px-5 pb-5">
                    <div class="overflow-x-auto border rounded-lg" data-testid="cash-overview-table-wrapper">
                        <table class="w-full text-sm" data-testid="cash-overview-table">
                            <thead class="bg-gray-100 text-gray-700">
                                <tr>
                                    <th class="px-3 py-2 text-left">{{ $t('label.time') }}</th>
                                    <th class="px-3 py-2 text-left">{{ $t('label.order_number') }}</th>
                                    <th class="px-3 py-2 text-left">{{ $t('label.source') }}</th>
                                    <th class="px-3 py-2 text-left">{{ $t('label.payment_method') }}</th>
                                    <th class="px-3 py-2 text-left">{{ $t('label.delivery_boy') }}</th>
                                    <th class="px-3 py-2 text-right">{{ $t('label.amount') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="tx in transactions"
                                    :key="tx.id"
                                    class="border-t hover:bg-gray-50"
                                    :data-testid="`cash-overview-row-${tx.id}`"
                                >
                                    <td class="px-3 py-2 whitespace-nowrap">{{ formatTime(tx.created_at) }}</td>
                                    <td class="px-3 py-2">
                                        <span v-if="tx.queue_number">N°{{ tx.queue_number }}</span>
                                        <span v-else class="text-gray-400">#{{ tx.order_id }}</span>
                                    </td>
                                    <td class="px-3 py-2">
                                        <span
                                            class="inline-flex items-center px-2 py-0.5 rounded text-xs"
                                            :class="sourceClass(tx.source)"
                                        >
                                            {{ sourceLabel(tx.source) }}
                                        </span>
                                    </td>
                                    <td class="px-3 py-2">{{ modeLabel(tx.mode_bucket) }}</td>
                                    <td class="px-3 py-2">{{ tx.delivery_boy_name || '—' }}</td>
                                    <td class="px-3 py-2 text-right font-semibold">{{ formatMoney(tx.amount) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <p
                        v-if="meta.capped"
                        class="text-xs text-amber-700 mt-2"
                        data-testid="cash-overview-cap-notice"
                    >
                        {{ $t('label.cash_overview_capped_notice') }}
                    </p>
                </div>
            </div>
        </div>
    </div>
</template>

<script>
import axios from 'axios';

export default {
    name: 'CashOverviewComponent',
    data() {
        const today = new Date().toISOString().slice(0, 10);
        return {
            loading: false,
            transactions: [],
            summary: null,
            cashSession: null,
            // [TRAP-3 2026-06-04] Cash-trail gap block — cash collected with no
            // open drawer session (PAID order, NO cash_movement). Surfaces the
            // discrepancy here, where the écart actually manifests, instead of
            // only via the ephemeral collect-time toast.
            unrecordedCash: null,
            // [FIX-3 2026-08-25] Drawers left `open` with no activity over the
            // displayed period (11 of them existed in dev). Surfaced instead of
            // silently letting the most-recently-opened one impersonate today's
            // drawer.
            staleOpenDrawers: null,
            meta: { capped: false, row_count: 0 },
            filters: {
                from: today,
                to: today,
                source: '',
                mode: '',
            },
        };
    },
    computed: {

        /**
         * [ONB 2026-08-28] Un filtre est-il REELLEMENT pose ?
         *
         * L'etat par defaut est « aujourd'hui, toutes sources, tous modes ».
         * Un journal vide dans cet etat ne veut pas dire « votre filtre est
         * trop etroit », il veut dire « aucune vente n'a encore ete
         * encaissee » — la situation NORMALE d'un commercant qui vient de
         * terminer son installation.
         */
        unFiltreEstActif() {
            const aujourdHui = new Date().toISOString().slice(0, 10);
        
            return Boolean(this.filters.source)
                || Boolean(this.filters.mode)
                || Boolean(this.filters.from && this.filters.from !== aujourdHui)
                || Boolean(this.filters.to && this.filters.to !== aujourdHui);
        },
        // Render fixed source order : caisse / borne / livreur, populating
        // zero-count buckets so the dashboard layout stays stable.
        /**
         * [AUDIT-SUPERVISEUR 2026-08-25 · D-001] Les ventes encaissees en especes sur la
         * periode — LA MEME SOURCE que le bloc « Repartition par mode » affiche juste en
         * dessous. C'est tout l'objet du correctif : les deux chiffres de la banniere
         * doivent etre comparables a ce que la page montre par ailleurs, sinon la banniere
         * contredit la page.
         */
        ventesEspecesPeriode() {
            const modes = (this.summary && this.summary.by_mode) || {};
            const espece = modes.cash || modes.espece || modes['Espèce'] || null;

            return espece ? Number(espece.total || 0) : 0;
        },

        /**
         * L'ECART, arrondi au centime. C'est le signal que cette banniere existe pour
         * donner et qu'elle ne donnait pas : un tiroir dont les mouvements ne collent pas
         * aux ventes demande une explication (appoint, prelevement, saisie oubliee).
         */
        ecartEspeces() {
            const brut = this.ventesEspecesPeriode
                - Number((this.cashSession && this.cashSession.cash_collected_in_period) || 0);

            return Math.round(brut * 100) / 100;
        },

        displayedSources() {
            /*
             * [AUDIT-SUPERVISEUR 2026-08-25 · D-002] UN TOTAL DONT LA DECOMPOSITION NE BOUCLE PAS.
             *
             * Mesure : « GRAND TOTAL 247,70 € / 27 tx » contre « BORNE 222,70 € / 17 tx », caisse
             * et livreur a zero. 25,00 € et 10 transactions — 10 % du chiffre de la periode —
             * n'apparaissaient dans AUCUNE carte, sans le moindre avertissement.
             *
             * Cause : cette liste etait FIGEE sur trois cles. Le grand total, lui, vient de
             * `summary.total`, qui contient tout. Toute autre cle de `by_source` — en pratique
             * `unknown`, le seau des paiements dont la commande n'existe plus — etait jetee en
             * silence. J'avais expose ces orphelins cote serveur plus tot dans cette mission ;
             * l'ecran les rejetait toujours. Un correctif a moitie fait ment aussi bien qu'une
             * absence de correctif.
             *
             * On garde les trois cles connues pour que la mise en page reste stable meme a zero,
             * et on ajoute TOUTE cle reellement presente. Un total qui ne boucle pas devient
             * impossible a afficher sans le dire.
             */
            const known = ['caisse', 'borne', 'livreur'];
            const stats = (this.summary && this.summary.by_source) || {};
            const autres = Object.keys(stats).filter((k) => !known.includes(k));

            return known.concat(autres).map((key) => ({
                key,
                label: this.sourceLabel(key),
                stat: stats[key] || { count: 0, total: 0 },
            }));
        },
        // [Wave X-C round-2 2026-05-21] Fix C-013 — dropped `cashDiff`,
        // `diffLabel`, `diffClass` computed props (and the diff cell that
        // used them). The previous diff was algebraically `-opening_amount`
        // and never reflected a real écart. Until V1.0.2 ships a cashier
        // count-input feature the page displays 3 honest values
        // (opening / collected_today / expected_in_drawer) without a
        // misleading diff.

        /**
         * [FIX-3 2026-08-25] Non-empty ONLY when the drawer was opened on an
         * earlier day than today. Drives both the age badge and the
         * "lifetime cumulative" disclaimer, so the card can never present an
         * old drawer as if it had been opened this morning.
         */
        drawerAgeLabel() {
            if (!this.cashSession) return '';
            if (this.cashSession.opened_today) return '';
            const days = Number(this.cashSession.age_days || 0);
            if (!Number.isFinite(days) || days < 1) return '';
            return days === 1 ? 'ouvert depuis hier' : `ouvert depuis ${days} jours`;
        },
    },
    // [Wave Z Q8 2026-05-21] Hydrate filter state from `$route.query` BEFORE
    // mount so an inbound shareable link (e.g. ?source=borne&from=2026-05-01)
    // restores the same view for the receiver. Without this hook the form
    // would default to today's date and the URL params would be ignored
    // until the user clicked Search.
    created() {
        this.hydrateFiltersFromRoute();
    },
    mounted() {
        this.fetch();
    },
    watch: {
        // [Wave X-C round-1 2026-05-21 / extended Wave Z Q8 2026-05-21]
        // The `$route.query` watcher is now the SOLE fetch trigger after
        // mount — `applyFilters` and `clearFilters` push to the URL and
        // this watcher fires `fetch()`. That prevents a double-fetch
        // (push → watcher fires AND we manually call fetch). It also
        // covers the original Wave X-C use-case: external ?branch_id= flip.
        '$route.query': {
            handler() {
                this.hydrateFiltersFromRoute();
                this.fetch();
            },
            deep: true,
        },
    },
    methods: {
        async fetch() {
            this.loading = true;
            try {
                const params = {};
                if (this.filters.from) params.from = this.filters.from;
                if (this.filters.to) params.to = this.filters.to;
                if (this.filters.source) params.source = this.filters.source;
                if (this.filters.mode) params.mode = this.filters.mode;

                // [Wave X-C round-1 2026-05-21] Honour ?branch_id= URL query
                // so admin can pin reconciliation to a specific branch via
                // a shareable link. Filter form has no branch_id field — the
                // top-bar branch selector / direct URL is the SSOT for that
                // dimension. Backend silently ignores branch_id for staff
                // (they're forced to their own branch).
                const routeBranch = this.$route && this.$route.query
                    ? Number(this.$route.query.branch_id || 0)
                    : 0;
                if (routeBranch > 0) {
                    params.branch_id = routeBranch;
                }

                const res = await (window.axios || axios).get('admin/cash-overview', { params });
                const payload = res.data || {};
                this.transactions = Array.isArray(payload.data) ? payload.data : [];
                this.summary = payload.summary || null;
                this.cashSession = payload.cash_session || null;
                this.unrecordedCash = payload.unrecorded_cash || null;
                this.staleOpenDrawers = payload.stale_open_drawers || null;
                this.meta = payload.meta || { capped: false, row_count: 0 };
            } catch (e) {
                // eslint-disable-next-line no-console
                console.error('[CashOverview] load failed', e);
                this.transactions = [];
                this.summary = null;
                this.cashSession = null;
                this.unrecordedCash = null;
                this.staleOpenDrawers = null;
            } finally {
                this.loading = false;
            }
        },
        // [Wave Z Q8 2026-05-21] Read serializable filters from `$route.query`
        // and apply them to `this.filters`. `branch_id` is NOT mirrored here —
        // it stays read-from-route inside `fetch()` because the form has no
        // branch_id field (top-bar selector / direct URL is its SSOT).
        hydrateFiltersFromRoute() {
            const q = (this.$route && this.$route.query) || {};
            if (typeof q.from === 'string' && q.from) this.filters.from = q.from;
            if (typeof q.to === 'string' && q.to) this.filters.to = q.to;
            this.filters.source = typeof q.source === 'string' ? q.source : '';
            this.filters.mode = typeof q.mode === 'string' ? q.mode : '';
        },
        // [Wave Z Q8 2026-05-21] Form-submit handler. Pushes current filter
        // state to the URL so refresh / share preserves the view. The
        // `$route.query` watcher picks up the change and calls `fetch()` —
        // do NOT call `fetch()` here or you'll get a double-fetch.
        // `.catch(()=>{})` swallows Vue Router's NavigationDuplicated which
        // fires when submitting the same filter state twice. When the URL
        // already matches (idempotent submit), the watcher won't fire so we
        // call `fetch()` directly to preserve the click-Search UX.
        applyFilters() {
            const q = this.buildRouteQuery();
            const sameAsCurrent = this.routeQueryEquals(q, (this.$route && this.$route.query) || {});
            if (sameAsCurrent) {
                this.fetch();
            } else {
                this.$router.push({ query: q }).catch(() => {});
            }
        },
        // [Wave Z Q8 2026-05-21] Shallow string-equality compare for route
        // query objects (Vue Router stores all values as strings or arrays).
        // Used to detect a no-op submit and avoid relying on
        // NavigationDuplicated as a control-flow signal.
        routeQueryEquals(a, b) {
            const keys = new Set([...Object.keys(a || {}), ...Object.keys(b || {})]);
            for (const k of keys) {
                if (String((a || {})[k] || '') !== String((b || {})[k] || '')) {
                    return false;
                }
            }
            return true;
        },
        // [Wave Z Q8 2026-05-21] Build the next URL query from current
        // filter state, preserving `branch_id` if it was already in the URL
        // so the branch-pin feature (Wave X-C round-1) stays intact across
        // filter changes.
        buildRouteQuery() {
            const q = {};
            if (this.filters.from) q.from = this.filters.from;
            if (this.filters.to) q.to = this.filters.to;
            if (this.filters.source) q.source = this.filters.source;
            if (this.filters.mode) q.mode = this.filters.mode;
            const currentBranch = this.$route && this.$route.query
                ? this.$route.query.branch_id
                : null;
            if (currentBranch) q.branch_id = currentBranch;
            return q;
        },
        clearFilters() {
            const today = new Date().toISOString().slice(0, 10);
            this.filters = { from: today, to: today, source: '', mode: '' };
            // [Wave Z Q8 2026-05-21] Push a clean URL (preserving branch_id
            // if pinned) and let the `$route.query` watcher trigger fetch.
            const q = {};
            const currentBranch = this.$route && this.$route.query
                ? this.$route.query.branch_id
                : null;
            if (currentBranch) q.branch_id = currentBranch;
            // If URL already matches (no query), router.push is a no-op for
            // the watcher — in that case fall back to a direct fetch.
            const currentQ = (this.$route && this.$route.query) || {};
            const isAlreadyClean = !currentQ.from && !currentQ.to
                && !currentQ.source && !currentQ.mode;
            if (isAlreadyClean) {
                this.fetch();
            } else {
                this.$router.push({ query: q }).catch(() => {});
            }
        },
        sourceLabel(s) {
            switch (s) {
                case 'caisse':  return this.$t('label.source_caisse');
                case 'borne':   return this.$t('label.source_borne');
                case 'livreur': return this.$t('label.source_livreur');
                // [AUDIT-SUPERVISEUR 2026-08-25 · D-003] Le `default` rendait la CLE BRUTE :
                // une pastille portant littéralement le mot anglais « unknown » s'affichait
                // dans la colonne Source, au milieu de pastilles traduites, sur un produit à
                // locale immuable. Ce seau a un sens métier — un paiement dont la commande
                // n'existe plus — il mérite son nom, en français.
                case 'unknown': return this.$t('label.cash_source_unknown');
                default:        return s || '—';
            }
        },
        sourceClass(s) {
            switch (s) {
                case 'caisse':  return 'bg-blue-100 text-blue-800';
                case 'borne':   return 'bg-purple-100 text-purple-800';
                case 'livreur': return 'bg-emerald-100 text-emerald-800';
                default:        return 'bg-gray-100 text-gray-800';
            }
        },
        modeLabel(m) {
            switch (m) {
                case 'cash':   return this.$t('label.mode_cash');
                case 'card':   return this.$t('label.mode_card');
                case 'mobile': return this.$t('label.mode_mobile');
                case 'ticket': return this.$t('label.mode_ticket');
                case 'other':  return this.$t('label.mode_other');
                default:       return m || '—';
            }
        },
        // [Wave Z Q5 2026-05-21] Owner-mandated unification — `formatMoney`
        // and `formatMoneyEuro` now share the EUR-formatted body. Previously
        // `formatMoney` returned bare decimals (`82.50`) on aggregate cards +
        // chips + table cells, which confused audit-fiscal scans (no € symbol
        // visible). Aliasing the two functions means every currency render
        // surfaces `82,50 €` consistently. `formatMoneyEuro` is retained as
        // an alias so existing call-sites (reconciliation strip) keep working
        // without a callsite sweep.
        formatMoney(v) {
            const n = Number(v || 0);
            try {
                const locale = (this.$i18n && this.$i18n.locale) || 'fr-FR';
                return new Intl.NumberFormat(locale, {
                    style: 'currency',
                    currency: 'EUR',
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2,
                }).format(n);
            } catch (e) {
                return `${n.toFixed(2)} €`;
            }
        },
        formatMoneyEuro(v) {
            return this.formatMoney(v);
        },
        formatTime(iso) {
            if (!iso) return '—';
            try {
                const d = new Date(iso);
                const locale = (this.$i18n && this.$i18n.locale) || 'fr-FR';
                return d.toLocaleTimeString(locale, { hour: '2-digit', minute: '2-digit' });
            } catch (e) {
                return iso;
            }
        },
        /**
         * [FIX-3 2026-08-25] A bare clock is only honest for today. `formatTime`
         * rendered « 20:56 » for a drawer opened 50 days earlier, which is what
         * made the staleness invisible. Anything not from today is DATED.
         */
        formatDateTime(iso) {
            if (!iso) return '—';
            try {
                const d = new Date(iso);
                if (Number.isNaN(d.getTime())) return iso;
                const locale = (this.$i18n && this.$i18n.locale) || 'fr-FR';
                const time = d.toLocaleTimeString(locale, { hour: '2-digit', minute: '2-digit' });
                const now = new Date();
                const sameDay = d.getFullYear() === now.getFullYear()
                    && d.getMonth() === now.getMonth()
                    && d.getDate() === now.getDate();
                if (sameDay) return time;
                const date = d.toLocaleDateString(locale, {
                    day: '2-digit', month: '2-digit', year: 'numeric',
                });
                return `${date} ${time}`;
            } catch (e) {
                return iso;
            }
        },
    },
};
</script>
