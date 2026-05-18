<template>
  <KsModal
    :model-value="modelValue"
    :title="$t('kiosk.offline_conflict.title')"
    size="md"
    @update:modelValue="$emit('update:modelValue', $event)"
    @close="$emit('update:modelValue', false)"
  >
    <div class="kiosk-offline-conflict" data-testid="kiosk-offline-conflict-modal">
      <p class="kiosk-offline-conflict__intro">
        {{ $t('kiosk.offline_conflict.intro') }}
      </p>

      <ul
        v-if="entries.length > 0"
        class="kiosk-offline-conflict__list"
        data-testid="kiosk-offline-conflict-list"
      >
        <li
          v-for="entry in entries"
          :key="entry.localKey"
          class="kiosk-offline-conflict__item"
          :data-testid="`kiosk-offline-conflict-entry-${entry.localKey}`"
        >
          <div class="kiosk-offline-conflict__meta">
            <strong>{{ entry.localKey }}</strong>
            <span>{{ formatSavedAt(entry.savedAt) }}</span>
          </div>
          <p class="kiosk-offline-conflict__stale">
            {{ $t('kiosk.offline_conflict.products_impacted', { list: formatItemList(entry.staleItems) }) }}
          </p>
          <div class="kiosk-offline-conflict__actions">
            <button
              type="button"
              class="kiosk-offline-conflict__btn kiosk-offline-conflict__btn--ghost"
              :data-testid="`kiosk-offline-cancel-${entry.localKey}`"
              @click="$emit('cancel-entry', entry.localKey)"
            >
              {{ $t('kiosk.offline_conflict.cancel') }}
            </button>
            <button
              type="button"
              class="kiosk-offline-conflict__btn kiosk-offline-conflict__btn--primary"
              :data-testid="`kiosk-offline-force-${entry.localKey}`"
              @click="$emit('force-entry', entry.localKey)"
            >
              {{ $t('kiosk.offline_conflict.force_send') }}
            </button>
          </div>
        </li>
      </ul>

      <p v-else class="kiosk-offline-conflict__empty" data-testid="kiosk-offline-conflict-empty">
        {{ $t('kiosk.offline_conflict.empty') }}
      </p>
    </div>
  </KsModal>
</template>

<script>
import KsModal from './ds/KsModal.vue';

export default {
  name: 'KioskOfflineConflictModalComponent',
  components: { KsModal },
  props: {
    modelValue: { type: Boolean, default: false },
    entries: { type: Array, default: () => [] },
  },
  emits: ['update:modelValue', 'cancel-entry', 'force-entry', 'opened'],
  watch: {
    modelValue(value) {
      if (value) {
        this.$emit('opened');
      }
    },
  },
  methods: {
    formatItemList(staleItems) {
      if (!Array.isArray(staleItems) || staleItems.length === 0) {
        return this.$t('kiosk.offline_conflict.no_items');
      }
      return staleItems.join(', ');
    },
    formatSavedAt(savedAt) {
      if (!savedAt) return this.$t('kiosk.offline_conflict.date_unknown');
      try {
        return new Intl.DateTimeFormat('fr-FR', {
          dateStyle: 'short',
          timeStyle: 'short',
        }).format(savedAt);
      } catch (_) {
        return String(savedAt);
      }
    },
  },
};
</script>

<style scoped>
.kiosk-offline-conflict {
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.kiosk-offline-conflict__intro,
.kiosk-offline-conflict__empty,
.kiosk-offline-conflict__stale {
  margin: 0;
  line-height: 1.5;
  color: var(--kiosk-text-muted, #5a5a5a);
}

.kiosk-offline-conflict__list {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.kiosk-offline-conflict__item {
  border: 1px solid var(--kiosk-border, #e0e0e0);
  border-radius: 16px;
  padding: 16px;
  background: var(--kiosk-bg-2, #f7f7f8);
}

.kiosk-offline-conflict__meta {
  display: flex;
  justify-content: space-between;
  gap: 12px;
  flex-wrap: wrap;
  margin-bottom: 8px;
}

.kiosk-offline-conflict__actions {
  display: flex;
  gap: 12px;
  flex-wrap: wrap;
  margin-top: 12px;
}

.kiosk-offline-conflict__btn {
  min-height: 44px;
  border-radius: 999px;
  border: 1px solid transparent;
  padding: 10px 18px;
  font: inherit;
  font-weight: 700;
  cursor: pointer;
}

.kiosk-offline-conflict__btn--primary {
  background: var(--kiosk-primary, #f4501e);
  color: #fff;
}

.kiosk-offline-conflict__btn--ghost {
  background: #fff;
  border-color: var(--kiosk-border, #e0e0e0);
  color: var(--kiosk-text, #1a1a1a);
}

.kiosk-offline-conflict__btn:focus-visible {
  outline: 3px solid var(--kiosk-focus-ring, #2563eb);
  outline-offset: 2px;
}

@media (prefers-reduced-motion: reduce) {
  :deep(.ks-modal-enter-active),
  :deep(.ks-modal-leave-active),
  :deep(.ks-modal-enter-active .ks-modal__panel),
  :deep(.ks-modal-leave-active .ks-modal__panel) {
    transition: none;
  }
}

:global([data-kiosk-reduced-motion="true"]) :deep(.ks-modal-enter-active),
:global([data-kiosk-reduced-motion="true"]) :deep(.ks-modal-leave-active),
:global([data-kiosk-reduced-motion="true"]) :deep(.ks-modal-enter-active .ks-modal__panel),
:global([data-kiosk-reduced-motion="true"]) :deep(.ks-modal-leave-active .ks-modal__panel) {
  transition: none;
}
</style>
