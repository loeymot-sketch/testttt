<template>
    <div class="space-y-4">
        <div class="rounded border border-slate-200 bg-white p-4">
            <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                <div>
                    <h3 class="text-base font-semibold text-slate-800">Composition produit</h3>
                    <p class="mt-1 text-sm text-slate-500">
                        Controle les briques existantes du catalogue sans calculer le prix final cote interface.
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <router-link
                        v-if="item.id && wizardPerItemDemoEnabled"
                        :to="{ name: 'admin.items.composer', params: { id: item.id } }"
                        class="db-btn bg-primary text-white"
                    >
                        <i class="lab lab-edit"></i>
                        Configurer
                    </router-link>
                    <span class="rounded bg-slate-100 px-2 py-1 text-xs font-semibold text-slate-600">
                        Template: {{ wizardTemplate }}
                    </span>
                    <span class="rounded px-2 py-1 text-xs font-semibold" :class="menuBadgeClass">
                        {{ menuLabel }}
                    </span>
                </div>
            </div>

            <div class="mt-4 grid grid-cols-1 gap-3 md:grid-cols-3">
                <div class="rounded border border-slate-100 bg-slate-50 p-3">
                    <p class="text-xs font-semibold uppercase text-slate-500">Produit</p>
                    <p class="mt-1 text-sm font-semibold text-slate-800">{{ item.name || '-' }}</p>
                    <p class="mt-1 text-xs text-slate-500">{{ item.category_name || '-' }}</p>
                </div>
                <div class="rounded border border-slate-100 bg-slate-50 p-3">
                    <p class="text-xs font-semibold uppercase text-slate-500">Prix source</p>
                    <p class="mt-1 text-sm font-semibold text-slate-800">{{ item.flat_price || item.currency_price || '-' }}</p>
                    <p class="mt-1 text-xs text-slate-500">Final: PricingService backend</p>
                </div>
                <div class="rounded border border-slate-100 bg-slate-50 p-3">
                    <p class="text-xs font-semibold uppercase text-slate-500">Photo borne</p>
                    <p class="mt-1 text-sm font-semibold text-slate-800">{{ imageState }}</p>
                    <p class="mt-1 text-xs text-slate-500">Modifiable dans l'onglet Images</p>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-4 xl:grid-cols-2">
            <section class="rounded border border-slate-200 bg-white p-4">
                <div class="mb-3 flex items-center justify-between gap-3">
                    <h4 class="text-sm font-semibold text-slate-800">Etapes variations</h4>
                    <span class="rounded bg-blue-50 px-2 py-1 text-xs font-semibold text-blue-700">
                        {{ variationGroups.length }} groupe(s)
                    </span>
                </div>

                <div v-if="variationGroups.length" class="space-y-3">
                    <article v-for="group in variationGroups" :key="group.key" class="rounded border border-slate-100 p-3">
                        <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                            <div>
                                <p class="text-sm font-semibold text-slate-800">{{ group.name }}</p>
                                <p class="text-xs text-slate-500">
                                    min_select {{ group.min_select }} · max_select {{ group.max_select }} · allow_repeat {{ yesNo(group.allow_repeat) }}
                                </p>
                            </div>
                            <span class="rounded bg-slate-100 px-2 py-1 text-xs font-semibold text-slate-600">
                                {{ group.choices.length }} choix
                            </span>
                        </div>
                        <div class="mt-3 flex flex-wrap gap-2">
                            <span v-for="choice in group.choices" :key="choice.id || choice.name"
                                class="rounded border border-slate-200 bg-slate-50 px-2 py-1 text-xs text-slate-700">
                                {{ choice.name }} <span class="text-slate-400">{{ choice.flat_price || choice.currency_price || '' }}</span>
                            </span>
                        </div>
                    </article>
                </div>

                <p v-else class="rounded bg-slate-50 p-3 text-sm text-slate-500">
                    Aucun groupe de variations configure. Utilise l'onglet Variations pour ajouter pain, taille, viande ou autre choix structure.
                </p>
            </section>

            <section class="rounded border border-slate-200 bg-white p-4">
                <div class="mb-3 flex items-center justify-between gap-3">
                    <h4 class="text-sm font-semibold text-slate-800">Extras et supplements</h4>
                    <span class="rounded bg-amber-50 px-2 py-1 text-xs font-semibold text-amber-700">
                        {{ extras.length }} option(s)
                    </span>
                </div>

                <div v-if="extraGroups.length" class="space-y-3">
                    <article v-for="group in extraGroups" :key="group.name" class="rounded border border-slate-100 p-3">
                        <p class="text-sm font-semibold text-slate-800">{{ group.name }}</p>
                        <div class="mt-3 flex flex-wrap gap-2">
                            <span v-for="extra in group.items" :key="extra.id || extra.name"
                                class="rounded border border-slate-200 bg-slate-50 px-2 py-1 text-xs text-slate-700">
                                {{ extra.name }} <span class="text-slate-400">{{ extra.flat_price || extra.currency_price || '' }}</span>
                                <span class="ml-1 text-slate-400">· {{ surfaces(extra.visible_on) }}</span>
                            </span>
                        </div>
                    </article>
                </div>

                <p v-else class="rounded bg-slate-50 p-3 text-sm text-slate-500">
                    Aucun extra configure. Utilise l'onglet Extra pour ajouter sauces, crudites, garnitures ou supplements.
                </p>
            </section>
        </div>

        <section class="rounded border border-slate-200 bg-white p-4">
            <div class="mb-3 flex items-center justify-between gap-3">
                <h4 class="text-sm font-semibold text-slate-800">Addons et offres liees</h4>
                <span class="rounded bg-emerald-50 px-2 py-1 text-xs font-semibold text-emerald-700">
                    {{ addons.length }} addon(s)
                </span>
            </div>

            <div v-if="addons.length" class="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3">
                <article v-for="addon in addons" :key="addon.id" class="rounded border border-slate-100 p-3">
                    <p class="text-sm font-semibold text-slate-800">{{ addon.addon_item_name || '-' }}</p>
                    <p class="mt-1 text-xs text-slate-500">{{ addon.total_flat_price || addon.addon_item_flat_price || '-' }}</p>
                    <p class="mt-2 text-xs text-slate-500">Variations liees: {{ addonVariationCount(addon) }}</p>
                </article>
            </div>

            <p v-else class="rounded bg-slate-50 p-3 text-sm text-slate-500">
                Aucun addon configure. Utilise l'onglet Addon pour creer un menu, une boisson liee ou une offre composee.
            </p>
        </section>
    </div>
</template>

<script>
export default {
    name: "ProductComposerSummaryComponent",
    props: {
        item: {
            type: Object,
            default: () => ({})
        }
    },
    computed: {
        wizardTemplate() {
            return this.item.wizard_template || this.item?.category?.wizard_template || 'simple';
        },
        hasMenu() {
            return Boolean(this.item.has_menu || this.item?.category?.has_menu);
        },
        menuLabel() {
            return this.hasMenu ? 'Menu actif' : 'Produit simple';
        },
        menuBadgeClass() {
            return this.hasMenu ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-600';
        },
        imageState() {
            return this.item.preview || this.item.thumb || this.item.cover ? 'Image configuree' : 'Image manquante';
        },
        wizardPerItemDemoEnabled() {
            return typeof window !== 'undefined'
                && window.foodkingConfig?.features?.wizard_per_item_demo === true;
        },
        variationGroups() {
            const attributes = this.toArray(this.item.itemAttributes);
            const buckets = this.item.variations || {};

            if (attributes.length) {
                return attributes.map((attribute) => ({
                    key: attribute.id || attribute.name,
                    name: attribute.name || 'Variation',
                    min_select: Number(attribute.min_select ?? 0),
                    max_select: Number(attribute.max_select ?? 1),
                    allow_repeat: Boolean(attribute.allow_repeat),
                    choices: this.toArray(buckets[attribute.id] || buckets[String(attribute.id)] || [])
                }));
            }

            return Object.keys(buckets).map((key) => ({
                key,
                name: `Variation ${key}`,
                min_select: 0,
                max_select: 1,
                allow_repeat: false,
                choices: this.toArray(buckets[key])
            }));
        },
        extras() {
            return this.toArray(this.item.extras);
        },
        extraGroups() {
            const groups = {};

            this.extras.forEach((extra) => {
                const name = extra.group_label || 'Extras';
                if (!groups[name]) {
                    groups[name] = [];
                }
                groups[name].push(extra);
            });

            return Object.keys(groups).map((name) => ({
                name,
                items: groups[name]
            }));
        },
        addons() {
            return this.toArray(this.item.addons);
        }
    },
    methods: {
        toArray(value) {
            if (Array.isArray(value)) {
                return value;
            }

            if (value && typeof value === 'object') {
                return Object.values(value);
            }

            return [];
        },
        surfaces(value) {
            if (!value) {
                return 'POS + kiosk';
            }

            if (Array.isArray(value)) {
                return value.join(' + ');
            }

            return String(value);
        },
        addonVariationCount(addon) {
            return this.toArray(addon.variations).length;
        },
        yesNo(value) {
            return value ? 'oui' : 'non';
        }
    }
}
</script>
