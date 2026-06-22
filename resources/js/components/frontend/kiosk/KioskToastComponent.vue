<template>
  <transition-group
    name="toast-slide"
    tag="div"
    class="kiosk-toast-container"
    role="status"
    aria-live="polite"
    aria-relevant="additions text"
  >
    <div
      v-for="toast in toasts"
      :key="toast.id"
      class="kiosk-toast"
      :class="toast.type"
      :role="(toast.type === 'error' || toast.type === 'warning') ? 'alert' : null"
      :aria-live="(toast.type === 'error' || toast.type === 'warning') ? 'assertive' : null"
    >
      <span class="kiosk-toast-icon" aria-hidden="true">{{ ICONS[toast.type] || 'ℹ️' }}</span>
      <span class="kiosk-toast-msg">{{ toast.message }}</span>
      <button
        type="button"
        class="kiosk-toast-close"
        aria-label="Fermer la notification"
        @click.stop="remove(toast.id)"
      >×</button>
    </div>
  </transition-group>
</template>

<script>
const ICONS = { success: '✓', error: '✕', info: 'ℹ', warning: '⚠' };
let _nextId = 1;

export default {
  name: 'KioskToastComponent',

  data() {
    return {
      toasts: [],
      ICONS,
    };
  },

  methods: {
    show(message, type = 'info', duration = 2800) {
      const id = _nextId++;
      this.toasts.push({ id, message, type });
      setTimeout(() => this.remove(id), duration);
    },

    remove(id) {
      const idx = this.toasts.findIndex(t => t.id === id);
      if (idx !== -1) this.toasts.splice(idx, 1);
    },
  },
};
</script>

<style scoped>
.kiosk-toast-container {
  position: fixed;
  bottom: 80px;
  left: 50%;
  transform: translateX(-50%);
  z-index: 99999;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 10px;
  pointer-events: none;
}

.kiosk-toast {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 16px 28px;
  border-radius: 50px;
  font-size: 17px;
  font-weight: 700;
  color: white;
  box-shadow: 0 8px 32px rgba(0, 0, 0, 0.35);
  pointer-events: all;
  cursor: pointer;
  white-space: nowrap;
  max-width: 90vw;
  backdrop-filter: blur(8px);
}

.kiosk-toast.success { background: rgba(34, 197, 94, 0.92); }
.kiosk-toast.error   { background: rgba(244, 80, 30, 0.92); }
.kiosk-toast.info    { background: rgba(26, 26, 46, 0.92); border: 1px solid rgba(255,255,255,0.15); }
.kiosk-toast.warning { background: rgba(234, 179, 8, 0.92); color: #1a1a2e; }

.kiosk-toast-icon { font-size: 20px; flex-shrink: 0; }

.kiosk-toast-close {
  flex-shrink: 0;
  margin-inline-start: 8px;
  min-width: 44px;
  min-height: 44px;
  width: 44px;
  height: 44px;
  border: none;
  border-radius: 50%;
  background: rgba(0, 0, 0, 0.18);
  color: inherit;
  font-size: 22px;
  line-height: 1;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  padding: 0;
}
.kiosk-toast-close:focus-visible {
  outline: 3px solid var(--kiosk-focus-ring, #2563eb);
  outline-offset: 2px;
}

/* Transitions */
.toast-slide-enter-active { transition: all 0.3s cubic-bezier(0.34, 1.56, 0.64, 1); }
.toast-slide-leave-active { transition: all 0.2s ease; }
.toast-slide-enter-from   { opacity: 0; transform: translateY(20px) scale(0.9); }
.toast-slide-leave-to     { opacity: 0; transform: translateY(10px) scale(0.95); }
</style>
