<template>
    <!--
        OutboxOverviewComponent — CV1-OBSERVABILITY-OUTBOX-001.

        Ops dashboard that surfaces the domain_events outbox pipeline health
        so a crashed laravel-websockets / queue:work worker stops being a
        SILENT failure. Five sections, all data-driven by a single backend
        endpoint:

            GET /api/admin/observability/outbox

        1. domain_events pending      (count + last 50 rows)
        2. domain_events dispatched   (24h count + p50/p95/p99 latency)
        3. jobs queue=high            (count + oldest job age)
        4. failed_jobs                (count + last 20 with truncated error)
        5. health probes              (queue:work / websockets:serve UP|DOWN)

        Two admin-gated actions:
            POST /api/admin/observability/outbox/retry-failed
            POST /api/admin/observability/outbox/drain-failed

        Audit  : reports/red-r5/SYNTHESIS_FINAL.md §3
        Mission: missions/CV1-OBSERVABILITY-OUTBOX-001.md
    -->
    <section
        class="space-y-4"
        data-testid="outbox-overview-dashboard"
        :aria-busy="loading"
        :data-perime="perime ? 'true' : 'false'"
    >
        <!--
            [2026-09-02 · Codex P1-L] Cet écran est celui qu'on ouvre PRÉCISÉMENT quand
            quelque chose ne va pas. `loadAll()` n'avait aucun `catch` : une lecture en
            échec laissait les dernières valeurs à l'écran — zéro en attente, sondes « en
            service » — pendant que le tuyau était bouché. Un écran de supervision qui
            garde son vert quand il ne mesure plus rien est pire qu'un écran éteint.
        -->
        <div
            v-if="erreur"
            role="alert"
            data-testid="outbox-erreur"
            class="rounded border border-rose-300 bg-rose-50 p-3 text-sm text-rose-900"
        >
            <p class="font-semibold">{{ erreur }}</p>
            <p class="mt-1 text-xs">
                Les valeurs ci-dessous datent de la dernière lecture réussie{{ generatedAtHuman ? ` (${generatedAtHuman})` : '' }}
                — elles ne décrivent PAS l'état actuel.
            </p>
            <button
                type="button"
                class="db-btn db-btn-secondary mt-2 text-xs !text-slate-800"
                data-testid="outbox-reessayer"
                @click="loadAll"
            >Réessayer</button>
        </div>

        <p
            v-if="retourAction"
            :role="retourAction.type === 'alert' ? 'alert' : 'status'"
            data-testid="outbox-retour-action"
            class="rounded border p-3 text-sm"
            :class="retourAction.type === 'alert'
                ? 'border-rose-300 bg-rose-50 text-rose-900'
                : 'border-emerald-300 bg-emerald-50 text-emerald-900'"
        >{{ retourAction.texte }}</p>

        <!--
            Purger supprime des lignes DÉFINITIVEMENT. Une confirmation dans la page, pas
            un `confirm()` natif : celui-ci bloque toute la fenêtre et, en automatisation,
            fige la session sans rien dire.
        -->
        <div
            v-if="confirmationPurge"
            data-testid="outbox-drain-confirm"
            role="dialog"
            aria-modal="true"
            aria-labelledby="outbox-drain-titre"
            class="rounded border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900"
        >
            <!--
                [G2 2026-09-03 · T2.3 · défaut V-04] Cette confirmation annonçait
                `terminalFailures.count` — un compteur de `domain_events`. Or l'action
                supprime des lignes `failed_jobs` : deux tables sans rapport. On annonçait
                donc « 5 supprimés » là où la purge n'effaçait rien. Le compteur affiché
                est désormais celui de la table que l'action touche, et c'est le MÊME
                ensemble que le serveur purgera (helper partagé côté contrôleur).
            -->
            <p id="outbox-drain-titre" class="font-semibold">
                Purger les travaux en échec de plus de {{ purgeableFailedJobs.older_than_hours }} h ?
            </p>
            <p class="mt-1 text-xs">
                {{ purgeableFailedJobs.count }} travail(aux) en échec de la file (table
                <code>failed_jobs</code>, travaux outbox uniquement) seront supprimés définitivement.
                Les lignes sont exportées avant suppression et l'opération est consignée au
                journal d'audit.
            </p>
            <div class="mt-3 flex gap-2">
                <button
                    type="button"
                    class="db-btn db-btn-primary text-xs"
                    :disabled="draining"
                    data-testid="outbox-drain-confirmer"
                    @click="confirmerPurge"
                >Confirmer la purge</button>
                <button
                    type="button"
                    class="db-btn db-btn-secondary text-xs !text-slate-800"
                    data-testid="outbox-drain-annuler"
                    @click="annulerPurge"
                >Annuler</button>
            </div>
        </div>
        <header class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h2 class="text-lg font-semibold text-slate-800">
                    {{ $t('admin.observability_outbox.title') }}
                </h2>
                <p class="mt-1 text-sm text-slate-600">
                    {{ $t('admin.observability_outbox.subtitle') }}
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <span
                    v-if="generatedAtHuman"
                    class="text-xs text-slate-500"
                    data-testid="outbox-generated-at"
                >
                    {{ $t('admin.observability_outbox.generated_at') }} {{ generatedAtHuman }}
                </span>
                <button
                    type="button"
                    class="db-btn db-btn-secondary text-sm !text-slate-800"
                    :disabled="loading"
                    data-testid="outbox-refresh"
                    @click="loadAll"
                >
                    <i class="lab lab-reset" aria-hidden="true"></i>
                    {{ $t('admin.observability_outbox.refresh') }}
                </button>
                <!--
                    [2026-09-02] `.db-btn` n'a aucun style d'état désactivé : un bouton
                    inerte avait exactement l'apparence d'un bouton actif. Sur l'écran
                    qu'on ouvre quand quelque chose ne va pas, l'opérateur clique, rien ne
                    se passe, et il en conclut que l'outil est cassé. Le titre dit
                    POURQUOI, la mise en forme dit QUE.
                -->
                <!--
                    [G2 2026-09-03 · T2.3 · défaut V-04] Chaque bouton suit le compteur de
                    CE QU'IL FAIT :
                      - Rejouer  → `replayable_events` : événements pendants avec `last_error`,
                        hors violations de contrat (non rejouables) et dans la fenêtre d'âge
                        — exactement la sélection de `outboxRetryFailed`.
                      - Purger   → `purgeable_failed_jobs` : travaux `failed_jobs` outbox
                        au-delà du seuil — exactement la sélection de `outboxDrainFailed`.
                    Avant, LES DEUX suivaient `terminal_failures` (des `domain_events`), donc
                    la purge s'activait sur un compteur étranger à la table qu'elle vide.
                -->
                <button
                    type="button"
                    class="db-btn db-btn-secondary text-sm !text-slate-800"
                    :class="{ 'opacity-50 cursor-not-allowed': retrying || replayableEvents.count === 0 }"
                    :disabled="retrying || replayableEvents.count === 0"
                    :title="replayableEvents.count === 0
                        ? 'Aucun échec rejouable (une violation de contrat ne se rejoue pas)'
                        : `${replayableEvents.count} échec(s) à rejouer`"
                    data-testid="outbox-retry-failed"
                    @click="retryFailed"
                >
                    <i class="lab lab-reset" aria-hidden="true"></i>
                    {{ $t('admin.observability_outbox.retry_failed') }}
                </button>
                <button
                    type="button"
                    class="db-btn db-btn-secondary text-sm !text-slate-800"
                    :class="{ 'opacity-50 cursor-not-allowed': draining || purgeableFailedJobs.count === 0 }"
                    :disabled="draining || purgeableFailedJobs.count === 0"
                    :title="purgeableFailedJobs.count === 0
                        ? 'Aucun travail en échec à purger'
                        : `${purgeableFailedJobs.count} travail(aux) en échec purgeable(s)`"
                    data-testid="outbox-drain-failed"
                    @click="drainFailed"
                >
                    <i class="lab lab-trash" aria-hidden="true"></i>
                    {{ $t('admin.observability_outbox.drain_failed') }}
                </button>
            </div>
        </header>

        <!-- Section 5: Health probes (rendered first so ops see status at a glance) -->
        <article
            class="grid grid-cols-1 gap-3 sm:grid-cols-2"
            data-testid="outbox-health"
        >
            <div
                class="rounded border p-4"
                :class="health.queue_work.status === 'up'
                    ? 'border-emerald-200 bg-emerald-50'
                    : 'border-rose-200 bg-rose-50'"
                :data-testid="`outbox-health-queue-${health.queue_work.status}`"
            >
                <div class="flex items-center justify-between">
                    <h3 class="text-sm font-semibold text-slate-800">
                        queue:work
                    </h3>
                    <span
                        class="rounded px-2 py-1 text-xs font-semibold"
                        :class="health.queue_work.status === 'up'
                            ? 'bg-emerald-100 text-emerald-800'
                            : 'bg-rose-100 text-rose-800'"
                    >
                        {{ health.queue_work.status === 'up'
                            ? $t('admin.observability_outbox.up')
                            : $t('admin.observability_outbox.down') }}
                    </span>
                </div>
                <p class="mt-2 text-xs text-slate-600">
                    {{ formatHealthAge(health.queue_work.last_signal_age_seconds) }}
                </p>
            </div>
            <div
                class="rounded border p-4"
                :class="health.websockets_serve.status === 'up'
                    ? 'border-emerald-200 bg-emerald-50'
                    : 'border-rose-200 bg-rose-50'"
                :data-testid="`outbox-health-ws-${health.websockets_serve.status}`"
            >
                <div class="flex items-center justify-between">
                    <h3 class="text-sm font-semibold text-slate-800">
                        websockets:serve
                    </h3>
                    <span
                        class="rounded px-2 py-1 text-xs font-semibold"
                        :class="health.websockets_serve.status === 'up'
                            ? 'bg-emerald-100 text-emerald-800'
                            : 'bg-rose-100 text-rose-800'"
                    >
                        {{ health.websockets_serve.status === 'up'
                            ? $t('admin.observability_outbox.up')
                            : $t('admin.observability_outbox.down') }}
                    </span>
                </div>
                <p class="mt-2 text-xs text-slate-600">
                    {{ formatHealthAge(health.websockets_serve.last_signal_age_seconds) }}
                </p>
            </div>
        </article>

        <!--
            [G2 2026-09-03 · T2.2 · défaut V-03] Claims en cours et orphelins.

            `loadAll()` chargeait DÉJÀ `in_flight`, `stale_claimed` et `terminal_failures`
            dans l'état du composant — et le template n'en rendait AUCUN. Le cockpit
            pouvait donc connaître 2 149 claims orphelins (chiffre mesuré sur la base
            servie le 2026-09-02) et rester muet sur exactement la population qu'on vient
            y chercher. `terminal_failures` n'apparaissait que dans un `title` de bouton :
            invisible sans survol, absent de tout parcours au lecteur d'écran.

            `aria-live="polite"` : c'est la zone qui bouge au fil du rafraîchissement 10 s.
        -->
        <article
            class="rounded border border-slate-200 bg-white p-4"
            data-testid="outbox-claims"
            aria-live="polite"
        >
            <h3 class="text-sm font-semibold text-slate-800">
                Claims en cours, orphelins et échecs terminaux
            </h3>
            <dl class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-3">
                <div>
                    <dt class="text-xs text-slate-600">
                        En vol (claimé depuis moins de {{ inFlight.stale_after_minutes }} min)
                    </dt>
                    <dd
                        class="mt-1 text-sm font-semibold text-slate-800"
                        data-testid="outbox-in-flight-count"
                    >{{ inFlight.count }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-slate-600">
                        Orphelins (claimé depuis {{ inFlight.stale_after_minutes }} min ou plus, jamais diffusé)
                    </dt>
                    <dd
                        class="mt-1 text-sm font-semibold"
                        :class="staleClaimed.count > 0 ? 'text-rose-700' : 'text-slate-800'"
                        data-testid="outbox-stale-claimed-count"
                    >{{ staleClaimed.count }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-slate-600">Échecs terminaux</dt>
                    <dd
                        class="mt-1 text-sm font-semibold"
                        :class="terminalFailures.count > 0 ? 'text-rose-700' : 'text-slate-800'"
                        data-testid="outbox-terminal-count"
                    >
                        {{ terminalFailures.count }}
                        <span class="font-normal text-xs text-slate-600">
                            dont {{ terminalFailures.contract_violations }} violation(s) de contrat, non rejouable(s)
                        </span>
                    </dd>
                </div>
            </dl>

            <p v-if="staleClaimed.rows.length === 0" class="mt-3 text-sm text-slate-600">
                Aucun claim orphelin à lister.
            </p>
            <div v-else class="mt-3 overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="text-xs uppercase text-slate-500">
                            <th class="px-2 py-1 text-left">id</th>
                            <th class="px-2 py-1 text-left">{{ $t('admin.observability_outbox.event_type') }}</th>
                            <th class="px-2 py-1 text-left">{{ $t('admin.observability_outbox.branch_id') }}</th>
                            <th class="px-2 py-1 text-left">{{ $t('admin.observability_outbox.attempts') }}</th>
                            <th class="px-2 py-1 text-left">Claimé à</th>
                            <th class="px-2 py-1 text-left">{{ $t('admin.observability_outbox.last_error') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="row in staleClaimed.rows"
                            :key="`stale-${row.id}`"
                            class="border-t border-slate-100"
                            :data-testid="`outbox-stale-row-${row.id}`"
                        >
                            <td class="px-2 py-1 font-mono text-xs">{{ row.id }}</td>
                            <td class="px-2 py-1">{{ row.event_type }}</td>
                            <td class="px-2 py-1">{{ row.branch_id ?? '—' }}</td>
                            <td class="px-2 py-1">{{ row.attempts }}</td>
                            <td class="px-2 py-1 text-xs text-slate-600">{{ formatTimestamp(row.dispatched_at) }}</td>
                            <td class="px-2 py-1 text-xs text-rose-700">{{ row.last_error ?? '—' }}</td>
                        </tr>
                    </tbody>
                </table>
                <p v-if="staleClaimed.count > staleClaimed.rows.length" class="mt-2 text-xs text-slate-600">
                    {{ staleClaimed.rows.length }} ligne(s) affichée(s) sur {{ staleClaimed.count }}.
                </p>
            </div>
        </article>

        <!-- Section 1: Pending events -->
        <article
            class="rounded border border-slate-200 bg-white p-4"
            data-testid="outbox-pending"
        >
            <h3 class="text-sm font-semibold text-slate-800">
                {{ $t('admin.observability_outbox.pending') }}
                ({{ pending.count }})
            </h3>
            <p v-if="pending.rows.length === 0" class="mt-3 text-sm text-slate-600">
                {{ $t('admin.observability_outbox.no_pending') }}
            </p>
            <div v-else class="mt-3 overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="text-xs uppercase text-slate-500">
                            <th class="px-2 py-1 text-left">id</th>
                            <th class="px-2 py-1 text-left">{{ $t('admin.observability_outbox.event_type') }}</th>
                            <th class="px-2 py-1 text-left">{{ $t('admin.observability_outbox.branch_id') }}</th>
                            <th class="px-2 py-1 text-left">{{ $t('admin.observability_outbox.attempts') }}</th>
                            <th class="px-2 py-1 text-left">{{ $t('admin.observability_outbox.created_at') }}</th>
                            <th class="px-2 py-1 text-left">{{ $t('admin.observability_outbox.last_error') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="row in pending.rows"
                            :key="`pending-${row.id}`"
                            class="border-t border-slate-100"
                            :data-testid="`outbox-pending-row-${row.id}`"
                        >
                            <td class="px-2 py-1 font-mono text-xs">{{ row.id }}</td>
                            <td class="px-2 py-1">{{ row.event_type }}</td>
                            <td class="px-2 py-1">{{ row.branch_id ?? '—' }}</td>
                            <td class="px-2 py-1">
                                <span
                                    class="rounded px-2 py-0.5 text-xs font-semibold"
                                    :class="row.attempts > 3
                                        ? 'bg-rose-100 text-rose-800'
                                        : 'bg-amber-100 text-amber-800'"
                                >
                                    {{ row.attempts }}
                                </span>
                            </td>
                            <td class="px-2 py-1 text-xs text-slate-600">{{ formatTimestamp(row.created_at) }}</td>
                            <td class="px-2 py-1 text-xs text-rose-700">{{ row.last_error ?? '—' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </article>

        <!-- Section 2: Dispatched events (24h) -->
        <article
            class="rounded border border-slate-200 bg-white p-4"
            data-testid="outbox-dispatched"
        >
            <h3 class="text-sm font-semibold text-slate-800">
                {{ $t('admin.observability_outbox.dispatched_24h') }}
            </h3>
            <dl class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-5">
                <div>
                    <dt class="text-xs text-slate-600">{{ $t('admin.observability_outbox.count') }}</dt>
                    <dd class="mt-1 text-sm font-semibold text-slate-800" data-testid="outbox-delivered-count">{{ dispatched.count }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-slate-600">p50</dt>
                    <dd class="mt-1 text-sm font-semibold text-slate-800">
                        {{ dispatched.latency_p50_ms !== null ? `${dispatched.latency_p50_ms} ms` : '—' }}
                    </dd>
                </div>
                <div>
                    <dt class="text-xs text-slate-600">p95</dt>
                    <dd class="mt-1 text-sm font-semibold text-slate-800">
                        {{ dispatched.latency_p95_ms !== null ? `${dispatched.latency_p95_ms} ms` : '—' }}
                    </dd>
                </div>
                <div>
                    <dt class="text-xs text-slate-600">p99</dt>
                    <dd class="mt-1 text-sm font-semibold text-slate-800">
                        {{ dispatched.latency_p99_ms !== null ? `${dispatched.latency_p99_ms} ms` : '—' }}
                    </dd>
                </div>
                <div>
                    <dt class="text-xs text-slate-600">{{ $t('admin.observability_outbox.samples') }}</dt>
                    <dd class="mt-1 text-sm font-semibold text-slate-800">{{ dispatched.samples }}</dd>
                </div>
            </dl>
        </article>

        <!-- Section 3: Queue high lane -->
        <article
            class="rounded border border-slate-200 bg-white p-4"
            data-testid="outbox-queue-high"
        >
            <h3 class="text-sm font-semibold text-slate-800">
                {{ $t('admin.observability_outbox.queue_high') }}
            </h3>
            <dl class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-3">
                <div>
                    <dt class="text-xs text-slate-600">{{ $t('admin.observability_outbox.count') }}</dt>
                    <dd class="mt-1 text-sm font-semibold text-slate-800">{{ queueHigh.count }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-slate-600">{{ $t('admin.observability_outbox.oldest_age') }}</dt>
                    <dd class="mt-1 text-sm font-semibold text-slate-800">
                        {{ queueHigh.oldest_age_seconds !== null ? formatAgeSeconds(queueHigh.oldest_age_seconds) : '—' }}
                    </dd>
                </div>
                <div>
                    <dt class="text-xs text-slate-600">{{ $t('admin.observability_outbox.available') }}</dt>
                    <dd class="mt-1 text-sm font-semibold text-slate-800">{{ queueHigh.available ? '✓' : '—' }}</dd>
                </div>
            </dl>
        </article>

        <!-- Section 4: Failed jobs -->
        <article
            class="rounded border border-slate-200 bg-white p-4"
            data-testid="outbox-failed-jobs"
        >
            <h3 class="text-sm font-semibold text-slate-800">
                {{ $t('admin.observability_outbox.failed_jobs') }}
                ({{ failedJobs.count }})
            </h3>
            <p v-if="failedJobs.rows.length === 0" class="mt-3 text-sm text-slate-600">
                {{ $t('admin.observability_outbox.no_failed_jobs') }}
            </p>
            <div v-else class="mt-3 overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="text-xs uppercase text-slate-500">
                            <th class="px-2 py-1 text-left">id</th>
                            <th class="px-2 py-1 text-left">{{ $t('admin.observability_outbox.queue') }}</th>
                            <th class="px-2 py-1 text-left">{{ $t('admin.observability_outbox.failed_at') }}</th>
                            <th class="px-2 py-1 text-left">{{ $t('admin.observability_outbox.exception') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="row in failedJobs.rows"
                            :key="`failed-${row.id}`"
                            class="border-t border-slate-100"
                            :data-testid="`outbox-failed-row-${row.id}`"
                        >
                            <td class="px-2 py-1 font-mono text-xs">{{ row.id }}</td>
                            <td class="px-2 py-1">{{ row.queue }}</td>
                            <td class="px-2 py-1 text-xs text-slate-600">{{ formatTimestamp(row.failed_at) }}</td>
                            <td class="px-2 py-1 text-xs text-rose-700">{{ row.exception_first_line }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </article>
    </section>
</template>

<script>
/**
 * OutboxOverviewComponent
 *
 * Polling: every `pollIntervalMs` (default 10s) and on visibility-change.
 * Refresh is paused while the document is hidden to avoid burning quota
 * during long sessions.
 *
 * Admin-gated mutations:
 *   - retryFailed() POST /retry-failed → re-queues failed events
 *   - drainFailed() POST /drain-failed { older_than_hours: 24 } → safe purge
 */
import appService from '../../../services/appService';

export default {
    name: 'OutboxOverviewComponent',
    props: {
        pollIntervalMs: { type: Number, default: 10_000 },
    },
    data() {
        return {
            loading: false,
            retrying: false,
            draining: false,
            // [2026-09-02 · Codex P1-L] L'état de la MESURE elle-même, distinct des
            // valeurs mesurées : sans lui, une panne de lecture est indiscernable d'un
            // système sain.
            erreur: null,
            perime: false,
            retourAction: null,
            confirmationPurge: false,
            _lecture: null,
            generatedAt: null,
            pending: { count: 0, rows: [] },
            terminalFailures: { count: 0, contract_violations: 0 },
            // [G2 2026-09-03 · T2.3] Le compteur qui gouverne CHAQUE bouton, tiré de la
            // table que le bouton touche. Valeur initiale 0 : tant qu'on n'a pas mesuré,
            // on n'invite pas à une action destructrice.
            replayableEvents: { count: 0, max_age_days: 7 },
            purgeableFailedJobs: { count: 0, older_than_hours: 24 },
            // 10 min = `SyncOverviewController::CLAIM_STALE_MINUTES`. La valeur par défaut
            // était 5 : avant la première lecture, l'écran annonçait un seuil que le
            // serveur n'applique pas.
            inFlight: { count: 0, stale_after_minutes: 10 },
            staleClaimed: { count: 0, rows: [] },
            dispatched: {
                count: 0,
                latency_p50_ms: null,
                latency_p95_ms: null,
                latency_p99_ms: null,
                samples: 0,
            },
            queueHigh: { available: true, count: 0, oldest_age_seconds: null },
            failedJobs: { available: true, count: 0, rows: [] },
            health: {
                queue_work: { status: 'down', last_signal_age_seconds: null, method: '' },
                websockets_serve: { status: 'down', last_signal_age_seconds: null, method: '' },
            },
            _timer: null,
            _visibilityHandler: null,
        };
    },
    computed: {
        generatedAtHuman() {
            return this.generatedAt ? appService.dateHeureFr(this.generatedAt) : null;
        },
    },
    mounted() {
        this.loadAll();
        // [Polling] auto refresh every pollIntervalMs to surface a freshly-stuck pipeline.
        this._timer = setInterval(this.loadAll, this.pollIntervalMs);
        this._visibilityHandler = () => {
            if (!document.hidden) this.loadAll();
        };
        document.addEventListener('visibilitychange', this._visibilityHandler);
    },
    beforeUnmount() {
        if (this._timer) clearInterval(this._timer);
        if (this._visibilityHandler) {
            document.removeEventListener('visibilitychange', this._visibilityHandler);
        }
    },
    methods: {
        // Une seule lecture à la fois. Le rafraîchissement automatique (10 s), le retour
        // d'onglet et le bouton peuvent se déclencher ensemble : sans ce verrou, trois
        // requêtes partent et la dernière réponse arrivée gagne — pas forcément la plus
        // récente.
        loadAll() {
            if (document.hidden) return Promise.resolve();
            if (this._lecture) return this._lecture;

            this.loading = true;
            this._lecture = (async () => {
                try {
                    const { data } = await axios.get('/admin/observability/outbox');
                    this.generatedAt = data.generated_at || null;
                    this.pending = data.pending || { count: 0, rows: [] };
                    this.terminalFailures = data.terminal_failures || { count: 0, contract_violations: 0 };
                    this.inFlight = data.in_flight || this.inFlight;
                    this.staleClaimed = data.stale_claimed || { count: 0, rows: [] };
                    // `dispatched_24h` comptait un CLAIM comme une livraison : la clé a
                    // disparu du contrat. La lire encore afficherait zéro sans le dire.
                    this.dispatched = data.delivered_24h || this.dispatched;
                    this.queueHigh = data.queue_high || this.queueHigh;
                    this.failedJobs = data.failed_jobs || this.failedJobs;
                    // [G2 2026-09-03 · T2.3] Deux compteurs d'ACTION, distincts des
                    // compteurs d'état. Dégradation si un backend antérieur ne les envoie
                    // pas encore (repli à retirer après V1.1, comme `retried` ci-dessous) :
                    //  - rejouer : on retombe sur les échecs terminaux, l'ancien critère ;
                    //  - purger  : on retombe sur ZÉRO, jamais sur `failed_jobs.count` —
                    //    tous les travaux en échec ne sont pas des travaux outbox, et on
                    //    n'invite pas à une suppression définitive sur une mesure absente.
                    this.replayableEvents = data.replayable_events
                        || { count: this.terminalFailures.count ?? 0, max_age_days: this.replayableEvents.max_age_days };
                    this.purgeableFailedJobs = data.purgeable_failed_jobs
                        || { count: 0, older_than_hours: this.purgeableFailedJobs.older_than_hours };
                    this.health = data.health || this.health;
                    this.erreur = null;
                    this.perime = false;
                } catch (e) {
                    // Ne PAS remettre les compteurs à zéro : afficher zéro en attente
                    // pendant une panne de lecture serait un deuxième mensonge. On garde
                    // les dernières valeurs ET on dit qu'elles sont périmées.
                    this.erreur = this.messageDErreur(e);
                    this.perime = true;
                } finally {
                    this.loading = false;
                    this._lecture = null;
                }
            })();

            return this._lecture;
        },
        messageDErreur(e) {
            const code = e && e.response ? e.response.status : null;
            if (code === 403) return "Lecture refusée : ce compte n'a pas accès au cockpit outbox.";
            if (code === 401) return 'Session expirée : reconnectez-vous pour lire l’état du pipeline.';
            if (code) return `Le serveur a répondu ${code} : l’état affiché n’est plus à jour.`;

            return 'Impossible de lire l’état du pipeline (réseau ou serveur injoignable).';
        },
        async retryFailed() {
            this.retrying = true;
            this.retourAction = null;
            try {
                const { data } = await axios.post('/admin/observability/outbox/retry-failed');
                // [G2 2026-09-03 · T2.1 · défaut V-02] Le serveur répond `requeued`
                // (SyncOverviewController::outboxRetryFailed). Ce composant lisait
                // `retried` — une clé qu'AUCUNE réponse du contrôleur ne contient : le
                // compte était donc TOUJOURS `null` et l'écran affichait « Relance
                // demandée. », que 37 événements soient repartis ou aucun.
                // `retried` reste lu en REPLI DE DÉPRÉCIATION pour un backend non encore
                // redéployé — à retirer après V1.1 (posé le 2026-09-03).
                const combien = data && typeof data.requeued === 'number'
                    ? data.requeued
                    : (data && typeof data.retried === 'number' ? data.retried : null);
                // Une relance partiellement en échec n'est pas une relance réussie : le
                // serveur compte séparément les audits et les ré-expéditions manqués.
                const rates = [];
                if (data && Number(data.audit_failed) > 0) rates.push(`${data.audit_failed} sans trace d'audit`);
                if (data && Number(data.dispatch_failed) > 0) rates.push(`${data.dispatch_failed} non ré-expédié(s)`);

                this.retourAction = {
                    type: rates.length > 0 ? 'alert' : 'status',
                    texte: combien === null
                        ? 'Relance demandée (le serveur n’a pas dit combien).'
                        : `${combien} événement(s) remis en file.`
                            + (rates.length > 0 ? ` Échecs : ${rates.join(', ')}.` : ''),
                };
                await this.loadAll();
            } catch (e) {
                // Une action qui échoue en silence fait croire qu'elle a réussi — et on
                // attend une reprise qui ne viendra pas.
                this.retourAction = { type: 'alert', texte: 'La relance a échoué : ' + this.messageDErreur(e) };
            } finally {
                this.retrying = false;
            }
        },
        // Purger supprime définitivement : on demande, on n'agit pas.
        drainFailed() {
            this.retourAction = null;
            this.confirmationPurge = true;
        },
        annulerPurge() {
            this.confirmationPurge = false;
        },
        async confirmerPurge() {
            this.draining = true;
            try {
                const { data } = await axios.post('/admin/observability/outbox/drain-failed', {
                    older_than_hours: 24,
                });
                const combien = data && typeof data.deleted === 'number' ? data.deleted : null;
                // [G2 2026-09-03 · T2.3] Ce sont des TRAVAUX EN ÉCHEC (`failed_jobs`) qui
                // sont supprimés, pas des événements : nommer la mauvaise table fait
                // croire à l'opérateur qu'il vient d'effacer des `domain_events`.
                this.retourAction = {
                    type: 'status',
                    texte: combien === null
                        ? 'Purge effectuée.'
                        : `${combien} travail(aux) en échec supprimé(s) définitivement.`,
                };
                await this.loadAll();
            } catch (e) {
                this.retourAction = { type: 'alert', texte: 'La purge a échoué : ' + this.messageDErreur(e) };
            } finally {
                this.draining = false;
                this.confirmationPurge = false;
            }
        },
        formatTimestamp(value) {
            if (!value) return '—';
            try {
                return appService.dateHeureFr(value);
            } catch (e) {
                return String(value);
            }
        },
        formatAgeSeconds(seconds) {
            if (seconds == null) return '—';
            if (seconds < 60) return `${seconds}s`;
            if (seconds < 3600) return `${Math.floor(seconds / 60)}m`;
            return `${Math.floor(seconds / 3600)}h`;
        },
        formatHealthAge(seconds) {
            if (seconds == null) return this.$t('admin.observability_outbox.no_signal');
            return this.$t('admin.observability_outbox.last_signal') + ' ' + this.formatAgeSeconds(seconds);
        },
    },
};
</script>
