<template>
    <!--
        [V2-5 Phase 2] Kiosk theme preview card.

        Visually applies the theme's surface / text / brand variables LOCALLY
        on `.theme-preview` via inline `data-kiosk-theme` so the user can
        compare swatches side-by-side without globally switching the app.
        The actual global apply is triggered by the parent on click via
        the `@select` event.

        A11y :
          - Acts as a `radio` inside a parent `radiogroup`.
          - Tabbable + Enter/Space activate select.
          - aria-checked reflects `isActive`.
    -->
    <div
        class="theme-card"
        :class="{ active: isActive }"
        role="radio"
        :aria-checked="isActive ? 'true' : 'false'"
        :tabindex="0"
        :data-testid="`kiosk-theme-card-${theme.id}`"
        @click="$emit('select')"
        @keydown.enter.prevent="$emit('select')"
        @keydown.space.prevent="$emit('select')"
    >
        <div class="theme-preview" :data-kiosk-theme="theme.id">
            <span class="theme-emoji" aria-hidden="true">{{ theme.emoji }}</span>
            <h3 class="theme-name">{{ theme.name }}</h3>
        </div>
        <p class="theme-description">{{ theme.description }}</p>
        <div
            v-if="isActive"
            class="active-badge"
            :aria-label="$t('kiosk.admin.theme_active_aria')"
        >
            <span aria-hidden="true">✓</span>
            {{ $t('kiosk.admin.theme_active_label') }}
        </div>
    </div>
</template>

<script>
/**
 * KioskThemePreviewCard.vue — [V2-5 Phase 2] Greenfield admin atom.
 *
 * Pure presentational : pas de store, pas de fetch, pas de side-effect global.
 * Emits `select` ; le parent (KioskThemeManagerPage) gère le PATCH backend.
 */
export default {
    name: 'KioskThemePreviewCard',
    props: {
        theme: {
            type: Object,
            required: true,
            validator(value) {
                return (
                    value &&
                    typeof value.id === 'string' &&
                    typeof value.name === 'string' &&
                    typeof value.emoji === 'string' &&
                    typeof value.description === 'string'
                );
            },
        },
        isActive: {
            type: Boolean,
            default: false,
        },
    },
    emits: ['select'],
};
</script>

<style scoped>
.theme-card {
    background: #fff;
    border: 2px solid #EEE6D9;
    border-radius: 12px;
    overflow: hidden;
    cursor: pointer;
    transition: transform 0.15s ease, border-color 0.15s ease, box-shadow 0.15s ease;
    position: relative;
    user-select: none;
}

.theme-card:hover,
.theme-card:focus-visible {
    transform: translateY(-2px);
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.1);
    border-color: #E8001C;
    outline: none;
}

.theme-card.active {
    border-color: #E8001C;
    box-shadow: 0 0 0 4px rgba(232, 0, 28, 0.15);
}

/*
    Note : on duplique localement quelques surfaces de thème pour le rendu
    "swatch" SANS dépendre de l'attribut global `:root[data-kiosk-theme]`.
    C'est OK : la duplication reste limitée à 3 entrées (standard, halloween,
    christmas) et permet l'aperçu côte à côte sans switcher l'app entière.
*/
.theme-preview {
    padding: 32px 16px;
    text-align: center;
    background: #FFFBF5;
    color: #1A1A1A;
}

.theme-preview[data-kiosk-theme="halloween"] {
    background: #1A0A1F;
    color: #FFE5CC;
}

.theme-preview[data-kiosk-theme="christmas"] {
    background: #F5F8F0;
    color: #1A1A1A;
}

.theme-emoji {
    font-size: 64px;
    line-height: 1;
    display: block;
    margin-bottom: 8px;
}

.theme-name {
    margin: 0;
    font-size: 20px;
    font-weight: 800;
    letter-spacing: 0.5px;
}

.theme-description {
    padding: 16px;
    margin: 0;
    color: #5A5A5A;
    font-size: 14px;
    text-align: center;
    min-height: 48px;
}

.active-badge {
    position: absolute;
    top: 12px;
    right: 12px;
    background: #E8001C;
    color: #fff;
    padding: 4px 10px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    display: flex;
    align-items: center;
    gap: 4px;
    box-shadow: 0 2px 6px rgba(232, 0, 28, 0.35);
}
</style>
