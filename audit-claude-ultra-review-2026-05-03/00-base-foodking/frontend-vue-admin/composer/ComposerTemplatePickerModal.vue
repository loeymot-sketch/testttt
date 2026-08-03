<template>
    <div v-if="show" class="fixed inset-0 z-50 flex items-center justify-center bg-black/45 px-4" data-testid="composer-template-picker-modal">
        <div class="w-full max-w-4xl rounded-lg bg-white p-5 shadow-xl">
            <div class="mb-4 flex items-start justify-between gap-3">
                <div>
                    <h3 class="text-xl font-semibold text-[#202824]">
                        {{ t('label.composer.choose_template', 'Choisir un template') }}
                    </h3>
                    <p class="mt-1 text-sm text-[#66756e]">
                        {{ t('message.composer.template_picker_hint', 'Selectionnez un point de depart, puis personnalisez les pages.') }}
                    </p>
                </div>
                <button type="button" class="db-btn-outline !px-3" data-testid="composer-template-close" @click="$emit('close')">
                    <i class="lab lab-close" aria-hidden="true"></i>
                </button>
            </div>

            <div class="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3">
                <button
                    v-for="template in templates"
                    :key="template.key"
                    type="button"
                    class="rounded-lg border border-[#d9dfdc] bg-[#fbfcfb] p-4 text-left transition hover:border-[#1ab759] hover:bg-[#f2fbf5]"
                    :data-testid="`composer-template-${template.key}`"
                    @click="$emit('select', template.key)"
                >
                    <span class="mb-3 flex h-10 w-10 items-center justify-center rounded-lg bg-[#e9f2ed] text-lg text-[#14743a]">
                        <i :class="template.icon" aria-hidden="true"></i>
                    </span>
                    <span class="block text-base font-semibold text-[#202824]">
                        {{ template.label }}
                    </span>
                    <span class="mt-1 block text-sm leading-5 text-[#66756e]">
                        {{ template.description }}
                    </span>
                </button>
            </div>
        </div>
    </div>
</template>

<script>
export default {
    name: 'ComposerTemplatePickerModal',
    props: {
        show: {
            type: Boolean,
            default: false,
        },
    },
    emits: ['close', 'select'],
    computed: {
        templates() {
            return [
                {
                    key: 'simple',
                    icon: 'lab lab-box',
                    label: this.t('label.composer.template_simple', 'Simple'),
                    description: this.t('message.composer.template_simple', 'Simple : quantite seule, sans parcours de composition.'),
                },
                {
                    key: 'sandwich',
                    icon: 'lab lab-burger',
                    label: this.t('label.composer.template_sandwich', 'Sandwich'),
                    description: this.t('message.composer.template_sandwich', 'Sandwich : pain, viande, sauce, garnitures et supplements.'),
                },
                {
                    key: 'tacos',
                    icon: 'lab lab-menu',
                    label: this.t('label.composer.template_tacos', 'Tacos'),
                    description: this.t('message.composer.template_tacos', 'Tacos : taille, viande, sauce, garnitures, supplements et menu.'),
                },
                {
                    key: 'assiette',
                    icon: 'lab lab-dish',
                    label: this.t('label.composer.template_assiette', 'Assiette'),
                    description: this.t('message.composer.template_assiette', 'Assiette : viande, sauce et garnitures, sans page menu.'),
                },
                {
                    key: 'snacking',
                    icon: 'lab lab-cookie',
                    label: this.t('label.composer.template_snacking', 'Snacking'),
                    description: this.t('message.composer.template_snacking', 'Snacking : options rapides et supplements.'),
                },
                {
                    key: 'menu',
                    icon: 'lab lab-category',
                    label: this.t('label.composer.template_menu', 'Menu'),
                    description: this.t('message.composer.template_menu', 'Menu : formule complete avec boisson et accompagnement.'),
                },
                {
                    key: 'custom',
                    icon: 'lab lab-edit',
                    label: this.t('label.composer.template_custom', 'Custom'),
                    description: this.t('message.composer.template_custom', 'Custom : demarrer vide et composer les pages manuellement.'),
                },
            ];
        },
    },
    methods: {
        t(key, fallback) {
            return typeof this.$t === 'function' ? this.$t(key) : fallback;
        },
    },
};
</script>
