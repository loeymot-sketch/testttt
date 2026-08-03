<template>
    <aside
        class="mb-4"
        role="complementary"
        :aria-labelledby="headingId"
    >
        <div class="cv1-warning-badge" data-severity="info">
            <div class="flex items-start gap-2">
                <i
                    class="fa-solid fa-circle-info mt-0.5 shrink-0 opacity-90"
                    aria-hidden="true"
                />
                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        <h4
                            :id="headingId"
                            class="m-0 text-sm font-semibold leading-tight"
                        >
                            {{ titleText }}
                        </h4>
                        <button
                            type="button"
                            class="shrink-0 rounded px-2 py-0.5 text-sm font-medium underline decoration-slate-400 underline-offset-2 hover:decoration-primary hover:text-primary focus:outline-none focus-visible:rounded focus-visible:bg-slate-100 focus-visible:[outline:var(--cv1-focus-ring-width)_solid_var(--cv1-focus-ring-color)] focus-visible:[outline-offset:var(--cv1-focus-ring-offset)]"
                            :aria-expanded="expanded"
                            :aria-controls="contentId"
                            @click="expanded = !expanded"
                        >
                            {{ expanded ? $t("admin.help.toggle_hide") : $t("admin.help.toggle_show") }}
                        </button>
                    </div>
                    <div
                        v-show="expanded"
                        :id="contentId"
                        class="catalog-concept-help__body mt-2 text-sm leading-relaxed whitespace-pre-line"
                        role="region"
                    >
                        {{ bodyText }}
                        <a
                            v-if="learnMoreHref"
                            :href="learnMoreHref"
                            class="mt-2 block text-sm font-medium text-primary underline focus:outline-none focus-visible:rounded focus-visible:[outline:var(--cv1-focus-ring-width)_solid_var(--cv1-focus-ring-color)] focus-visible:[outline-offset:var(--cv1-focus-ring-offset)]"
                            target="_blank"
                            rel="noopener noreferrer"
                        >
                            {{ $t("admin.help.learn_more") }}
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </aside>
</template>

<script>
export default {
    name: "CatalogConceptHelpComponent",
    props: {
        concept: {
            type: String,
            required: true,
            validator: (v) =>
                ["attribute", "variation", "extra", "addon"].includes(v),
        },
        learnMoreHref: {
            type: String,
            default: "",
        },
    },
    data() {
        return {
            expanded: true,
            contentId: `catalog-help-content-${Math.random().toString(36).slice(2, 11)}`,
            headingId: `catalog-help-heading-${Math.random().toString(36).slice(2, 11)}`,
        };
    },
    computed: {
        titleText() {
            return this.$t(`admin.help.${this.concept}.title`);
        },
        bodyText() {
            return this.$t(`admin.help.${this.concept}.body`);
        },
    },
};
</script>
