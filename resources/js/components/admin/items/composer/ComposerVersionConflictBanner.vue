<template>
    <div
        v-if="isVisible"
        class="composer-version-conflict-banner"
        role="alert"
        aria-live="assertive"
        data-testid="composer-version-conflict-banner"
    >
        <div class="composer-version-conflict-banner__content">
            <div class="composer-version-conflict-banner__icon" aria-hidden="true">⚠</div>
            <div class="composer-version-conflict-banner__text">
                <h3>{{ $t('studio.composer.conflict.title') }}</h3>
                <p>{{ $t('studio.composer.conflict.message') }}</p>
                <p v-if="currentVersion !== null && expectedVersion !== null" class="composer-version-conflict-banner__details">
                    <small>v{{ currentVersion }} → v{{ expectedVersion }}</small>
                </p>
            </div>
            <button
                type="button"
                class="composer-version-conflict-banner__reload"
                @click="$emit('reload')"
                data-testid="composer-version-conflict-banner-reload"
            >
                {{ $t('studio.composer.conflict.reload') }}
            </button>
        </div>
    </div>
</template>

<script>
export default {
    name: 'ComposerVersionConflictBanner',
    props: {
        isVisible: { type: Boolean, default: false },
        currentVersion: { type: Number, default: null },
        expectedVersion: { type: Number, default: null },
    },
    emits: ['reload'],
};
</script>

<style scoped>
.composer-version-conflict-banner {
    position: sticky;
    top: 0;
    z-index: 30;
    border-bottom: 1px solid #dc2626;
    background: #fee2e2;
    padding: 1rem;
    color: #7f1d1d;
}

.composer-version-conflict-banner__content {
    display: flex;
    align-items: center;
    gap: 1rem;
    max-width: 1760px;
    margin: 0 auto;
}

.composer-version-conflict-banner__icon {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 2.5rem;
    height: 2.5rem;
    border-radius: 9999px;
    background: #fecaca;
    font-size: 1.25rem;
    font-weight: 700;
}

.composer-version-conflict-banner__text {
    flex: 1;
    min-width: 0;
}

.composer-version-conflict-banner__text h3 {
    margin: 0;
    font-size: 1rem;
    font-weight: 700;
}

.composer-version-conflict-banner__text p {
    margin: 0.25rem 0 0;
    font-size: 0.875rem;
}

.composer-version-conflict-banner__details {
    color: #991b1b;
}

.composer-version-conflict-banner__reload {
    border: 1px solid #b91c1c;
    border-radius: 0.5rem;
    background: #b91c1c;
    color: #fff;
    cursor: pointer;
    font-weight: 700;
    padding: 0.65rem 1rem;
}

.composer-version-conflict-banner__reload:hover,
.composer-version-conflict-banner__reload:focus {
    background: #991b1b;
}

@media (max-width: 640px) {
    .composer-version-conflict-banner__content {
        align-items: flex-start;
        flex-direction: column;
    }
}
</style>
