<template>
    <!--
        ItemPreviewComponent — Mission #2 Vague 1 action 1.2.

        Provides an inline POS / Kiosk preview of the item being edited so the
        admin can see what the cashier and the customer will actually see,
        without leaving the admin tab.

        Backed by MenuProjectionService::forChannel('pos'|'kiosk', $branchId)
        which already exists (route /api/admin/menu-projection, see
        app/Http/Controllers/Admin/MenuProjectionController.php) but has no
        runtime consumer in V1 — this component is the first one.

        Audit  : reports/audit/CLAUDE_ULTRA_REVIEW_MISSION_2_STOCK_COMPOSITION_2026-05-02.md §B #9
        Plan   : plans/PLAN_CV1-LIFECYCLE-UX-001_2026-05-02.md task 1.2
        Status : implemented (task 1.2).
    -->
    <section
        class="rounded border border-slate-200 bg-white p-4 space-y-4"
        data-testid="admin-item-preview"
        :aria-busy="loading"
        aria-labelledby="item-preview-title"
    >
        <header class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h3 id="item-preview-title" class="text-base font-semibold text-slate-800">
                    {{ $t('admin.item_preview.title') }}
                </h3>
                <p class="mt-1 text-sm text-slate-500">
                    {{ $t('admin.item_preview.subtitle') }}
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <label class="text-xs font-semibold text-slate-600" for="item-preview-branch">
                    {{ $t('label.branch') }}
                </label>
                <select
                    id="item-preview-branch"
                    v-model="selectedBranchId"
                    class="db-form-select text-sm"
                    data-testid="admin-item-preview-branch-select"
                    @change="refreshAll"
                >
                    <option v-for="branch in branches" :key="branch.id" :value="branch.id">
                        {{ branch.name }}
                    </option>
                </select>
                <button
                    type="button"
                    class="db-btn db-btn-secondary text-sm"
                    data-testid="admin-item-preview-refresh"
                    :disabled="loading"
                    @click="refreshAll"
                >
                    <i class="lab lab-refresh" aria-hidden="true"></i>
                    {{ $t('label.refresh') }}
                </button>
            </div>
        </header>

        <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
            <article
                class="rounded border border-slate-200 p-4"
                data-testid="admin-item-preview-pos"
                aria-label="POS preview"
            >
                <header class="mb-3 flex items-center justify-between">
                    <h4 class="text-sm font-semibold text-slate-800">
                        <i class="lab lab-cashier" aria-hidden="true"></i>
                        {{ $t('admin.item_preview.surface_pos') }}
                    </h4>
                    <span class="rounded bg-slate-100 px-2 py-1 text-xs font-semibold text-slate-600">
                        {{ posSummary.statusLabel }}
                    </span>
                </header>

                <div v-if="posProjection" class="space-y-2">
                    <p class="text-sm font-semibold text-slate-700">{{ posProjection.name }}</p>
                    <p class="text-xs text-slate-500">{{ posProjection.category_name }}</p>
                    <p class="text-sm">{{ formatPrice(posProjection.flat_price) }}</p>
                </div>
                <p v-else class="text-sm text-slate-400">
                    {{ loading ? $t('admin.item_preview.loading') : $t('admin.item_preview.no_pos_data') }}
                </p>

                <div class="mt-4 rounded border border-slate-200 bg-slate-50 p-3">
                    <h5 class="mb-3 text-sm font-semibold text-slate-800">
                        {{ $t('label.composer.preview_steps_title') }}
                    </h5>
                    <p v-if="!stepsForChannel('pos').length" class="text-sm italic text-slate-500">
                        {{ $t('label.composer.preview_no_steps') }}
                    </p>
                    <ol v-else class="space-y-3">
                        <li
                            v-for="(step, idx) in stepsForChannel('pos')"
                            :key="step.id || step._uid || step.step_key || idx"
                            class="flex items-start gap-3 text-sm"
                        >
                            <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-rose-100 text-xs font-bold text-rose-700">
                                {{ idx + 1 }}
                            </span>
                            <div class="flex-1">
                                <div class="font-medium text-slate-800">
                                    {{ step.label || step.step_key || $t('label.composer.preview_step_unnamed') }}
                                </div>
                                <div class="mt-0.5 text-xs text-slate-600">
                                    {{ stepChoiceLabel(step) }}
                                    <span v-if="sourceTypeLabel(step)" class="ml-1">
                                        - {{ $t('label.source') }}: {{ sourceTypeLabel(step) }}
                                    </span>
                                </div>
                                <div v-if="sourceOptionsPreview(step).length" class="mt-1 text-xs text-slate-500">
                                    {{ sourceOptionsPreview(step).join(', ') }}{{ sourceOptionsCount(step) > 4 ? '...' : '' }}
                                </div>
                                <div class="mt-2 flex flex-wrap gap-1 text-[11px] font-semibold">
                                    <span :class="visibilityBadgeClass(step, 'pos')">POS</span>
                                    <span :class="visibilityBadgeClass(step, 'kiosk')">Kiosk</span>
                                </div>
                            </div>
                        </li>
                    </ol>
                </div>
            </article>

            <article
                class="rounded border border-slate-200 p-4"
                data-testid="admin-item-preview-kiosk"
                aria-label="Kiosk preview"
            >
                <header class="mb-3 flex items-center justify-between">
                    <h4 class="text-sm font-semibold text-slate-800">
                        <i class="lab lab-kiosk" aria-hidden="true"></i>
                        {{ $t('admin.item_preview.surface_kiosk') }}
                    </h4>
                    <span class="rounded bg-slate-100 px-2 py-1 text-xs font-semibold text-slate-600">
                        {{ kioskSummary.statusLabel }}
                    </span>
                </header>

                <div v-if="kioskProjection" class="space-y-2">
                    <p class="text-sm font-semibold text-slate-700">{{ kioskProjection.kiosk_label || kioskProjection.name }}</p>
                    <p v-if="kioskProjection.kiosk_emoji || kioskProjection.emoji" class="text-2xl" aria-hidden="true">
                        {{ kioskProjection.kiosk_emoji || kioskProjection.emoji }}
                    </p>
                    <p class="text-xs text-slate-500">{{ kioskProjection.category_name }}</p>
                    <p class="text-sm">{{ formatPrice(kioskProjection.flat_price) }}</p>
                </div>
                <p v-else class="text-sm text-slate-400">
                    {{ loading ? $t('admin.item_preview.loading') : $t('admin.item_preview.no_kiosk_data') }}
                </p>

                <div class="mt-4 rounded border border-slate-200 bg-slate-50 p-3">
                    <h5 class="mb-3 text-sm font-semibold text-slate-800">
                        {{ $t('label.composer.preview_steps_title') }}
                    </h5>
                    <p v-if="!stepsForChannel('kiosk').length" class="text-sm italic text-slate-500">
                        {{ $t('label.composer.preview_no_steps') }}
                    </p>
                    <ol v-else class="space-y-3">
                        <li
                            v-for="(step, idx) in stepsForChannel('kiosk')"
                            :key="step.id || step._uid || step.step_key || idx"
                            class="flex items-start gap-3 text-sm"
                        >
                            <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-rose-100 text-xs font-bold text-rose-700">
                                {{ idx + 1 }}
                            </span>
                            <div class="flex-1">
                                <div class="font-medium text-slate-800">
                                    {{ step.label || step.step_key || $t('label.composer.preview_step_unnamed') }}
                                </div>
                                <div class="mt-0.5 text-xs text-slate-600">
                                    {{ stepChoiceLabel(step) }}
                                    <span v-if="sourceTypeLabel(step)" class="ml-1">
                                        - {{ $t('label.source') }}: {{ sourceTypeLabel(step) }}
                                    </span>
                                </div>
                                <div v-if="sourceOptionsPreview(step).length" class="mt-1 text-xs text-slate-500">
                                    {{ sourceOptionsPreview(step).join(', ') }}{{ sourceOptionsCount(step) > 4 ? '...' : '' }}
                                </div>
                                <div class="mt-2 flex flex-wrap gap-1 text-[11px] font-semibold">
                                    <span :class="visibilityBadgeClass(step, 'pos')">POS</span>
                                    <span :class="visibilityBadgeClass(step, 'kiosk')">Kiosk</span>
                                </div>
                            </div>
                        </li>
                    </ol>
                </div>
            </article>
        </div>

        <div
            v-if="projectionError"
            class="rounded border border-red-300 bg-red-50 p-3 text-sm text-red-800"
            role="alert"
            aria-live="assertive"
            data-testid="admin-item-preview-error"
        >
            {{ projectionError }}
        </div>

        <div
            v-if="parityWarning"
            class="rounded border border-amber-300 bg-amber-50 p-3 text-sm text-amber-800"
            role="alert"
            data-testid="admin-item-preview-parity-warning"
        >
            <i class="lab lab-warning" aria-hidden="true"></i>
            {{ parityWarning }}
        </div>

        <span class="sr-only" aria-live="polite">{{ branchAnnounce }}</span>
    </section>
</template>

<script>
/**
 * ItemPreviewComponent — admin inline projection preview.
 *
 * Props:
 *   item: Object       The item being edited (must contain id and branch_ids[]).
 *   branches: Array    [{id, name}] available branches for the dropdown.
 *   steps: Array       Wizard steps shown in the live customer journey preview.
 *
 * Emits:
 *   parity-warning(message: string) — non-blocking divergence indicator.
 *
 */
import axios from 'axios';
import { announcer } from '../../../helpers/a11y/announcer';

export default {
    name: 'ItemPreviewComponent',
    props: {
        item: { type: Object, required: true },
        branches: { type: Array, default: () => [] },
        steps: { type: Array, default: () => [] },
    },
    data() {
        return {
            loading: false,
            posProjection: null,
            kioskProjection: null,
            selectedBranchId: null,
            parityWarning: '',
            projectionError: '',
            branchAnnounce: '',
        };
    },
    computed: {
        posSummary() {
            return {
                statusLabel: this.posProjection?.is_available ? this.$t('label.available') : this.$t('label.unavailable'),
            };
        },
        kioskSummary() {
            return {
                statusLabel: this.kioskProjection?.is_available ? this.$t('label.available') : this.$t('label.unavailable'),
            };
        },
    },
    mounted() {
        this.selectedBranchId = this.defaultBranchId();
        if (this.selectedBranchId && this.item?.id) {
            this.refreshAll();
        }
    },
    watch: {
        'item.id'(id) {
            if (id && this.selectedBranchId) {
                this.refreshAll();
            }
        },
    },
    methods: {
        /**
         * Succursale sur laquelle l'aperçu s'ouvre.
         *
         * [CHEF 2026-09-01] Avant : `this.branches[0]?.id`. Or `BranchService::list()`
         * trie par défaut en `id desc` — la liste arrive donc 10, 9, 8, 7, 2, 1, et
         * « Le Cayenne (principal) », qui porte l'id 1, se retrouve EN DERNIER.
         * L'aperçu s'ouvrait ainsi sur « Collier and Sons Branch », une succursale
         * héritée du jeu de test où aucun produit n'est publié, et annonçait
         * « Article non disponible » pour un produit parfaitement en vente.
         * Vérifié côté service : `MenuProjectionService::forChannel('pos'|'kiosk', 1)`
         * rend 12 catégories / 46 articles et contient bien le produit — le défaut
         * n'était jamais dans la projection, seulement dans la succursale interrogée.
         *
         * On ouvre donc sur une succursale ACTIVE. Ce n'est pas une heuristique :
         * `Status::ACTIVE = 5`, et seule la principale porte ce statut ; les cinq
         * autres sont à `status = 1`, une valeur qui ne correspond à aucun statut du
         * domaine — reliquat de peuplement. Si un jour plusieurs succursales sont
         * réellement actives, la première active reste un défaut correct, et le
         * sélecteur laisse l'utilisateur en changer.
         */
        defaultBranchId() {
            const list = this.branches || [];
            const active = list.find((b) => Number(b.status) === 5);
            return (active || list[0])?.id ?? null;
        },
        async refreshAll() {
            if (!this.selectedBranchId || !this.item?.id) return;
            this.loading = true;
            this.parityWarning = '';
            this.projectionError = '';
            this.branchAnnounce = '';
            try {
                const [pos, kiosk] = await Promise.all([
                    this.loadProjection('pos', this.selectedBranchId),
                    this.loadProjection('kiosk', this.selectedBranchId),
                ]);
                this.posProjection = pos;
                this.kioskProjection = kiosk;
                this.computeParityWarning();
                const b = this.branches.find((x) => Number(x.id) === Number(this.selectedBranchId));
                this.branchAnnounce = b ? `${this.$t('label.branch')} ${b.name}` : '';
            } catch (e) {
                // eslint-disable-next-line no-console
                console.error(e);
                this.posProjection = null;
                this.kioskProjection = null;
                const errMsg = this.$t('admin.item_preview.load_error');
                this.projectionError = errMsg;
                announcer.assertive(errMsg);
                this.$emit('parity-warning', errMsg);
            } finally {
                this.loading = false;
            }
        },
        async loadProjection(channel, branchId) {
            const { data } = await axios.get('admin/menu-projection', {
                params: { channel, branch_id: branchId },
            });
            const categories = data?.categories ?? [];
            const itemId = Number(this.item.id);
            for (let i = 0; i < categories.length; i += 1) {
                const cat = categories[i];
                const rawItems = cat?.items ?? [];
                for (let j = 0; j < rawItems.length; j += 1) {
                    const row = rawItems[j];
                    if (Number(row.id) === itemId) {
                        const merged = { ...row, category_name: cat.name ?? row.category_name ?? '' };
                        if (merged.flat_price == null && merged.price != null) {
                            merged.flat_price = merged.price;
                        }
                        if (merged.emoji && !merged.kiosk_emoji) {
                            merged.kiosk_emoji = merged.emoji;
                        }
                        return merged;
                    }
                }
            }
            return null;
        },
        computeParityWarning() {
            const pos = this.posProjection;
            const kos = this.kioskProjection;
            let msg = '';
            const posMissing = pos == null;
            const kosMissing = kos == null;
            if (posMissing !== kosMissing) {
                msg = this.$t('admin.item_preview.divergence_warn_visibility');
            } else if (pos && kos) {
                const pPrice = pos.flat_price ?? pos.price;
                const kPrice = kos.flat_price ?? kos.price;
                if (pPrice !== kPrice) {
                    msg = this.$t('admin.item_preview.divergence_warn_price');
                } else if (!!pos.is_available !== !!kos.is_available) {
                    msg = this.$t('admin.item_preview.divergence_warn_availability');
                }
            }
            this.parityWarning = msg;
            if (msg) {
                this.$emit('parity-warning', msg);
            }
        },
        formatPrice(value) {
            if (value === null || value === undefined) return '';
            return value;
        },
        stepsForChannel(channelKey) {
            if (!Array.isArray(this.steps)) return [];
            return this.steps.filter((step) => {
                const visibleOn = step.visible_on || ['pos', 'kiosk'];
                return Array.isArray(visibleOn) ? visibleOn.includes(channelKey) : true;
            });
        },
        stepMin(step) {
            return Number(step.min_select ?? step.min ?? 0);
        },
        stepMax(step) {
            const min = this.stepMin(step);
            const max = Number(step.max_select ?? step.max ?? min);
            return Math.max(max, min);
        },
        stepChoiceLabel(step) {
            const min = this.stepMin(step);
            const max = this.stepMax(step);
            if (min === 0 && max === 1) {
                return this.$t('label.composer.preview_optional_one');
            }
            if (min === max) {
                return this.$t('label.composer.preview_required_n', { n: min });
            }
            return this.$t('label.composer.preview_min_max', { min, max });
        },
        sourceTypeLabel(step) {
            const labels = {
                item_attribute: this.$t('label.composer.source_item_attribute'),
                extra_group: this.$t('label.composer.source_extra_group'),
                addon: this.$t('label.composer.source_addon'),
            };
            return labels[step.source_type] || step.source_type || '';
        },
        sourceOptionsCount(step) {
            return Array.isArray(step.source_options_preview) ? step.source_options_preview.length : 0;
        },
        sourceOptionsPreview(step) {
            if (!Array.isArray(step.source_options_preview)) return [];
            return step.source_options_preview.slice(0, 4);
        },
        isStepVisibleOn(step, channelKey) {
            const visibleOn = step.visible_on || ['pos', 'kiosk'];
            return Array.isArray(visibleOn) ? visibleOn.includes(channelKey) : true;
        },
        visibilityBadgeClass(step, channelKey) {
            const base = 'rounded-full border px-2 py-0.5';
            return this.isStepVisibleOn(step, channelKey)
                ? `${base} border-emerald-200 bg-emerald-50 text-emerald-700`
                : `${base} border-slate-200 bg-white text-slate-400`;
        },
    },
};
</script>
