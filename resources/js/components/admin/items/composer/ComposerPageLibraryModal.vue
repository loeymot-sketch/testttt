<template>
    <div v-if="show" class="fixed inset-0 z-50 flex items-center justify-center bg-black/45 px-4"
        role="dialog" aria-modal="true" aria-labelledby="composer-page-library-title"
        tabindex="-1" @keydown.esc="$emit('close')"
        data-testid="composer-page-library-modal">
        <div class="w-full max-w-3xl rounded-lg bg-white p-5 shadow-xl">
            <div class="mb-4 flex items-start justify-between gap-3">
                <div>
                    <h3 id="composer-page-library-title" class="text-xl font-semibold text-[#202824]">Ajouter une page</h3>
                    <p class="mt-1 text-sm text-[#66756e]">
                        Prenez une page déjà enregistrée (elle garde ses choix et ses prix), ou partez d'une page vide.
                    </p>
                </div>
                <button type="button" class="db-btn-outline !px-3" data-testid="composer-page-library-close"
                    aria-label="Fermer" @click="$emit('close')">
                    <i class="lab lab-close" aria-hidden="true"></i>
                </button>
            </div>

            <div v-if="loading" class="py-8 text-center text-sm text-[#66756e]">Chargement des pages…</div>

            <div v-else class="max-h-[55vh] space-y-2 overflow-y-auto pr-1">
                <p v-if="error" role="alert"
                    class="rounded-lg border border-[#f0c2c2] bg-[#fdf2f2] p-4 text-sm font-semibold text-[#8c2f2f]"
                    data-testid="composer-page-library-error">
                    {{ error }}
                </p>
                <p v-else-if="pages.length === 0" class="rounded-lg border border-dashed border-[#ccd5d0] bg-[#f8faf9] p-4 text-sm text-[#66756e]"
                    data-testid="composer-page-library-empty">
                    Aucune page enregistrée pour l'instant. Créez-en une dans « Pages de wizard », ou ajoutez une page vide ci-dessous.
                </p>

                <article v-for="page in pages" :key="page.id"
                    class="rounded-lg border border-[#d9dfdc] bg-[#fbfcfb] p-3"
                    :class="{ 'opacity-60': usedPageIds.includes(page.id) }"
                    :data-testid="`composer-page-library-row-${page.id}`">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="text-sm font-semibold text-[#202824]">
                                {{ page.label }}
                                <span v-if="!page.is_library"
                                    class="ml-2 rounded-full bg-[#fff7ed] px-2 py-0.5 text-[11px] font-semibold text-[#9a3412]">
                                    personnalisée
                                </span>
                            </p>
                            <p class="text-xs text-[#66756e]">
                                {{ kindLabel(page.kind) }} · {{ page.choices_count ?? 0 }} choix ·
                                {{ ruleSummary(page) }}
                            </p>
                            <p v-if="usedPageIds.includes(page.id)" class="mt-1 text-xs font-semibold text-[#8a6812]">
                                Déjà dans ce parcours — pour la personnaliser, ouvrez-la dans la liste des pages à gauche
                            </p>
                            <p v-if="page.is_active === false" class="mt-1 text-xs font-semibold text-[#8a6812]">
                                Page éteinte : elle n'apparaîtra ni en caisse ni sur la borne tant qu'elle n'est pas rallumée.
                            </p>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <button type="button" class="db-btn py-2 bg-[#334238] text-white"
                                :disabled="usedPageIds.includes(page.id)"
                                :data-testid="`composer-page-library-use-${page.id}`"
                                @click="$emit('use', page)">
                                Utiliser
                            </button>
                            <button v-if="page.is_library" type="button" class="db-btn-outline"
                                :disabled="usedPageIds.includes(page.id)"
                                :data-testid="`composer-page-library-customize-${page.id}`"
                                @click="$emit('customize', page)">
                                Personnaliser
                            </button>
                        </div>
                    </div>
                </article>
            </div>

            <div class="mt-4 flex flex-wrap items-center justify-between gap-2 border-t border-[#e2e8f0] pt-4">
                <router-link :to="{ name: 'admin.wizard.pages' }" class="text-sm font-semibold text-[#14743a] hover:underline"
                    data-testid="composer-page-library-manage">
                    Gérer les pages et leurs prix
                </router-link>
                <button type="button" class="db-btn-outline" data-testid="composer-page-library-blank"
                    @click="$emit('blank')">
                    <i class="lab lab-add-circle" aria-hidden="true"></i>
                    Page vide
                </button>
            </div>
        </div>
    </div>
</template>

<script>
export default {
    name: 'ComposerPageLibraryModal',
    props: {
        show: { type: Boolean, default: false },
        pages: { type: Array, default: () => [] },
        loading: { type: Boolean, default: false },
        usedPageIds: { type: Array, default: () => [] },
        error: { type: String, default: '' },
    },
    emits: ['close', 'use', 'customize', 'blank'],
    data() {
        return {
            kinds: {
                pain: 'Pain / base',
                taille: 'Taille',
                viande: 'Viande / protéine',
                sauce: 'Sauce',
                garnitures: 'Garnitures',
                supplements: 'Suppléments',
                menu: 'Formule / boisson',
                generic: 'Autre question',
            },
        };
    },
    watch: {
        show(open) {
            if (typeof document === 'undefined') return;
            if (open) {
                document.addEventListener('keydown', this.onKeydown);
            } else {
                document.removeEventListener('keydown', this.onKeydown);
            }
        },
    },
    beforeUnmount() {
        if (typeof document !== 'undefined') {
            document.removeEventListener('keydown', this.onKeydown);
        }
    },
    methods: {
        /** Échap ferme la modale : sans ça, au clavier, on ne pouvait sortir qu'en atteignant le ×. */
        onKeydown(event) {
            if (event.key === 'Escape') {
                this.$emit('close');
            }
        },
        kindLabel(kind) {
            return this.kinds[kind] || kind;
        },
        ruleSummary(page) {
            const min = Number(page.min_select) || 0;
            const max = Number(page.max_select) || 0;
            if (min === 0 && max === 1) return 'optionnel, 1 choix';
            if (min === 0) return `optionnel, jusqu'à ${max}`;
            if (min === max) return `obligatoire, ${min} choix`;
            return `de ${min} à ${max} choix`;
        },
    },
};
</script>
