<template>
    <div
        v-if="isOpen"
        class="composer-diff-modal-backdrop"
        role="dialog"
        aria-modal="true"
        :aria-labelledby="'composer-diff-modal-title-' + profileId"
        @click.self="close"
    >
        <div ref="dialog" class="composer-diff-modal" tabindex="-1" @keydown.esc="close">
            <header class="composer-diff-modal__header">
                <h2 :id="'composer-diff-modal-title-' + profileId">
                    {{ $t('studio.composer.diff.title') }}
                </h2>
                <button
                    type="button"
                    class="composer-diff-modal__close"
                    :aria-label="$t('studio.composer.diff.cancel')"
                    @click="close"
                >
                    &times;
                </button>
            </header>

            <div class="composer-diff-modal__body">
                <div v-if="loading" class="composer-diff-modal__loading" data-testid="diff-loading">
                    <span>{{ $t('studio.composer.diff.loading') }}</span>
                </div>

                <div v-else-if="error" class="composer-diff-modal__error" data-testid="diff-error">
                    <p>{{ error }}</p>
                    <button type="button" @click="fetchDiff">
                        {{ $t('studio.composer.diff.retry') }}
                    </button>
                </div>

                <div v-else-if="diffPayload" data-testid="diff-content">
                    <p v-if="diffPayload.is_clean" class="composer-diff-modal__empty" data-testid="diff-no-changes">
                        {{ $t('studio.composer.diff.no_changes') }}
                    </p>

                    <template v-else>
                        <section
                            v-if="diffPayload.added && diffPayload.added.length"
                            class="composer-diff-modal__section composer-diff-modal__section--added"
                            data-testid="diff-section-added"
                        >
                            <h3>{{ $t('studio.composer.diff.added') }} ({{ diffPayload.added.length }})</h3>
                            <ul>
                                <li v-for="entry in diffPayload.added" :key="'added-' + entry.step_key">
                                    {{ entry.step_key }}
                                </li>
                            </ul>
                        </section>

                        <section
                            v-if="diffPayload.removed && diffPayload.removed.length"
                            class="composer-diff-modal__section composer-diff-modal__section--removed"
                            data-testid="diff-section-removed"
                        >
                            <h3>{{ $t('studio.composer.diff.removed') }} ({{ diffPayload.removed.length }})</h3>
                            <ul>
                                <li v-for="entry in diffPayload.removed" :key="'removed-' + entry.step_key">
                                    {{ entry.step_key }}
                                </li>
                            </ul>
                        </section>

                        <section
                            v-if="diffPayload.modified && diffPayload.modified.length"
                            class="composer-diff-modal__section composer-diff-modal__section--modified"
                            data-testid="diff-section-modified"
                        >
                            <h3>{{ $t('studio.composer.diff.modified') }} ({{ diffPayload.modified.length }})</h3>
                            <ul>
                                <li v-for="entry in diffPayload.modified" :key="'modified-' + entry.step_key">
                                    <strong>{{ entry.step_key }}</strong>
                                    <span v-if="entry.changed_fields && entry.changed_fields.length">
                                        : {{ entry.changed_fields.join(', ') }}
                                    </span>
                                </li>
                            </ul>
                        </section>
                    </template>
                </div>
            </div>

            <footer class="composer-diff-modal__footer">
                <button type="button" class="composer-diff-modal__secondary" data-testid="diff-cancel-button" @click="close">
                    {{ $t('studio.composer.diff.cancel') }}
                </button>
                <button
                    type="button"
                    class="composer-diff-modal__primary"
                    :disabled="loading || error || (diffPayload && diffPayload.is_clean)"
                    data-testid="diff-confirm-publish-button"
                    @click="confirmPublish"
                >
                    {{ $t('studio.composer.diff.confirm_publish') }}
                </button>
            </footer>
        </div>
    </div>
</template>

<script>
import axios from 'axios';

export default {
    name: 'ComposerPublishDiffModal',
    props: {
        profileId: {
            type: Number,
            required: true,
        },
        isOpen: {
            type: Boolean,
            default: false,
        },
    },
    emits: ['update:isOpen', 'confirm-publish'],
    data() {
        return {
            loading: false,
            error: null,
            diffPayload: null,
        };
    },
    watch: {
        isOpen: {
            handler(open) {
                if (open) {
                    this.$nextTick(() => {
                        this.$refs.dialog?.focus();
                    });
                    this.fetchDiff();
                }
            },
            immediate: true,
        },
    },
    methods: {
        async fetchDiff() {
            this.loading = true;
            this.error = null;
            this.diffPayload = null;

            try {
                const response = await axios.get(`admin/composer/profiles/${this.profileId}/diff`);
                this.diffPayload = response.data?.data ?? response.data;
            } catch (err) {
                this.error = err.response?.data?.message ?? this.$t('studio.composer.diff.error');
            } finally {
                this.loading = false;
            }
        },
        close() {
            this.$emit('update:isOpen', false);
        },
        confirmPublish() {
            this.$emit('confirm-publish');
            this.close();
        },
    },
};
</script>

<style scoped>
.composer-diff-modal-backdrop {
    position: fixed;
    inset: 0;
    z-index: 50;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 1rem;
    background: rgba(14, 20, 17, 0.58);
}

.composer-diff-modal {
    width: min(100%, 42rem);
    max-height: min(90vh, 46rem);
    overflow: hidden;
    border-radius: 1rem;
    background: #ffffff;
    box-shadow: 0 24px 70px rgba(14, 20, 17, 0.28);
    outline: none;
}

.composer-diff-modal__header,
.composer-diff-modal__footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    padding: 1rem 1.25rem;
    border-bottom: 1px solid #e4e8e6;
}

.composer-diff-modal__footer {
    justify-content: flex-end;
    border-top: 1px solid #e4e8e6;
    border-bottom: 0;
}

.composer-diff-modal__header h2 {
    margin: 0;
    color: #202824;
    font-size: 1.125rem;
    font-weight: 700;
}

.composer-diff-modal__close {
    border: 0;
    background: transparent;
    color: #52635b;
    font-size: 1.5rem;
    line-height: 1;
    cursor: pointer;
}

.composer-diff-modal__body {
    max-height: 60vh;
    overflow-y: auto;
    padding: 1.25rem;
}

.composer-diff-modal__loading,
.composer-diff-modal__empty,
.composer-diff-modal__error {
    border-radius: 0.75rem;
    padding: 1rem;
    background: #f5f7f6;
    color: #405149;
}

.composer-diff-modal__error {
    border: 1px solid #f3b6b6;
    background: #fff1f1;
    color: #9b2f2f;
}

.composer-diff-modal__error button,
.composer-diff-modal__secondary,
.composer-diff-modal__primary {
    min-height: 2.5rem;
    border-radius: 0.65rem;
    padding: 0.55rem 0.9rem;
    font-weight: 700;
    cursor: pointer;
}

.composer-diff-modal__section {
    margin-top: 0.85rem;
    border-radius: 0.85rem;
    padding: 1rem;
}

.composer-diff-modal__section h3 {
    margin: 0 0 0.65rem;
    font-size: 0.95rem;
    font-weight: 700;
}

.composer-diff-modal__section ul {
    margin: 0;
    padding-left: 1.25rem;
}

.composer-diff-modal__section--added {
    border: 1px solid #b9e7c8;
    background: #edf9f1;
    color: #14743a;
}

.composer-diff-modal__section--removed {
    border: 1px solid #f3b6b6;
    background: #fff1f1;
    color: #9b2f2f;
}

.composer-diff-modal__section--modified {
    border: 1px solid #f1d59a;
    background: #fff8df;
    color: #8a6812;
}

.composer-diff-modal__secondary {
    border: 1px solid #99a69f;
    background: #ffffff;
    color: #405149;
}

.composer-diff-modal__primary {
    border: 1px solid #1ab759;
    background: #1ab759;
    color: #ffffff;
}

.composer-diff-modal__primary:disabled {
    opacity: 0.55;
    cursor: not-allowed;
}

@media (max-width: 640px) {
    .composer-diff-modal__footer {
        flex-direction: column-reverse;
        align-items: stretch;
    }
}
</style>
