<template>
  <div class="kiosk-admin-overlay" @click.self="handleOverlayClick">

    <!-- ── PIN screen ─────────────────────────────────────────────────── -->
    <transition name="fade-scale" mode="out-in">
      <div v-if="!pinUnlocked" key="pin" class="kiosk-admin-card kiosk-pin-card">
        <button class="kiosk-admin-close" @click="$emit('close')">✕</button>

        <div class="kiosk-pin-icon">🔐</div>
        <h2 class="kiosk-pin-title">{{ $t('kiosk.admin_screen.pin_title') }}</h2>
        <p class="kiosk-pin-sub">{{ $t('kiosk.admin_screen.pin_sub') }}</p>

        <!-- Dots display -->
        <div class="kiosk-pin-dots">
          <div
            v-for="i in 4"
            :key="i"
            class="kiosk-pin-dot"
            :class="{ filled: pinInput.length >= i, error: pinError }"
          />
        </div>

        <!-- Error message -->
        <transition name="fade">
          <p v-if="pinError" class="kiosk-pin-error">{{ $t('kiosk.admin_screen.pin_wrong') }}</p>
        </transition>

        <!-- [C5] Lockout message -->
        <transition name="fade">
          <p v-if="pinLocked" class="kiosk-pin-locked">
            {{ $t('kiosk.admin_screen.pin_locked', { n: pinLockSeconds }) }}
          </p>
        </transition>

        <!-- Numpad -->
        <div class="kiosk-pin-numpad">
          <button
            v-for="n in [1,2,3,4,5,6,7,8,9,'',0,'⌫']"
            :key="n"
            class="kiosk-pin-key"
            :class="{ 'is-empty': n === '', 'is-del': n === '⌫' }"
            :disabled="n === ''"
            @click="handlePinKey(n)"
          >
            {{ n }}
          </button>
        </div>
      </div>

      <!-- ── Admin panel ─────────────────────────────────────────────── -->
      <div v-else key="panel" class="kiosk-admin-card">
        <div class="kiosk-admin-header">
          <div>
            <h2>{{ $t('kiosk.admin_screen.panel_title') }}</h2>
            <p class="kiosk-admin-sub">{{ $t('kiosk.admin_screen.panel_sub') }}</p>
          </div>
          <button class="kiosk-admin-close" @click="$emit('close')">✕</button>
        </div>

        <!-- Imprimante -->
        <div class="kiosk-admin-section">
          <h3>{{ $t('kiosk.admin_screen.section_printer') }}</h3>
          <button class="kiosk-admin-btn" @click="testPrint" :disabled="busy === 'print'">
            <span class="btn-icon">🖨️</span>
            <span>{{ busy === 'print' ? $t('kiosk.admin_screen.print_busy') : $t('kiosk.admin_screen.print_test') }}</span>
          </button>
          <button class="kiosk-admin-btn" @click="openDrawer" :disabled="busy === 'drawer'">
            <span class="btn-icon">🗄️</span>
            <span>{{ busy === 'drawer' ? $t('kiosk.admin_screen.drawer_busy') : $t('kiosk.admin_screen.drawer_open') }}</span>
          </button>
        </div>

        <!-- Terminal de paiement -->
        <div class="kiosk-admin-section">
          <h3>{{ $t('kiosk.admin_screen.section_terminal') }}</h3>
          <div class="kiosk-admin-status-row">
            <span>{{ $t('kiosk.admin_screen.status_label') }}</span>
            <span :class="terminalConnected ? 'status-ok' : 'status-err'">
              {{
                terminalConnected
                  ? $t('kiosk.admin_screen.terminal_connected', { model: terminalModel })
                  : $t('kiosk.admin_screen.terminal_disconnected')
              }}
            </span>
          </div>
          <button class="kiosk-admin-btn" @click="checkTerminal" :disabled="busy === 'terminal'">
            <span class="btn-icon">🔌</span>
            <span>{{ busy === 'terminal' ? $t('kiosk.admin_screen.terminal_check_busy') : $t('kiosk.admin_screen.terminal_check') }}</span>
          </button>
        </div>

        <!-- Menu cache -->
        <div class="kiosk-admin-section">
          <h3>{{ $t('kiosk.admin_screen.section_menu') }}</h3>
          <div class="kiosk-admin-status-row">
            <span>{{ $t('kiosk.admin_screen.cache_label') }}</span>
            <span :class="menuCacheAge !== null ? 'status-ok' : 'status-err'">
              {{
                menuCacheAge !== null
                  ? $t('kiosk.admin_screen.cache_fresh', { n: menuCacheAge })
                  : $t('kiosk.admin_screen.cache_empty')
              }}
            </span>
          </div>
          <button class="kiosk-admin-btn" @click="refreshMenu" :disabled="busy === 'menu'">
            <span class="btn-icon">🔄</span>
            <span>{{ busy === 'menu' ? $t('kiosk.admin_screen.menu_refresh_busy') : $t('kiosk.admin_screen.menu_refresh') }}</span>
          </button>
        </div>

        <!-- Application -->
        <div class="kiosk-admin-section">
          <h3>{{ $t('kiosk.admin_screen.section_app') }}</h3>
          <button class="kiosk-admin-btn" @click="reloadApp">
            <span class="btn-icon">🔄</span>
            <span>{{ $t('kiosk.admin_screen.app_reload') }}</span>
          </button>
          <button class="kiosk-admin-btn danger" @click="logout">
            <span class="btn-icon">🔓</span>
            <span>{{ $t('kiosk.admin_screen.logout') }}</span>
          </button>
          <button class="kiosk-admin-btn danger" @click="quitApp" v-if="isElectron">
            <span class="btn-icon">⏻</span>
            <span>{{ $t('kiosk.admin_screen.quit_app') }}</span>
          </button>
        </div>

        <!-- Feedback -->
        <transition name="fade">
          <div v-if="feedback" :class="['kiosk-admin-feedback', feedbackType]">
            {{ feedback }}
          </div>
        </transition>
      </div>
    </transition>

    <!-- [C5] Maintenance mode confirmation dialog -->
    <transition name="fade">
      <div v-if="showMaintenanceConfirm" class="kiosk-maintenance-overlay" @click.self="cancelMaintenanceMode">
        <div class="kiosk-maintenance-card">
          <div class="kiosk-maintenance-icon">⚠️</div>
          <h3>{{ $t('kiosk.admin_screen.maintenance_title') }}</h3>
          <p class="kiosk-maintenance-body-text">{{ $t('kiosk.admin_screen.maintenance_body') }}</p>
          <div class="kiosk-maintenance-actions">
            <button class="kiosk-admin-btn danger" @click="enterMaintenanceMode">
              {{ $t('kiosk.admin_screen.maintenance_enable') }}
            </button>
            <button class="kiosk-admin-btn" @click="cancelMaintenanceMode">
              {{ $t('kiosk.admin_screen.maintenance_cancel') }}
            </button>
          </div>
        </div>
      </div>
    </transition>
  </div>
</template>

<script>
import { mapActions } from 'vuex';

// No default PIN fallback: the admin code must come from server settings.
const DEFAULT_PIN = '';

export default {
  name: 'KioskAdminComponent',
  emits: ['close'],

  data() {
    return {
      // PIN
      pinInput:      '',
      pinUnlocked:   false,
      pinError:      false,
      _pinErrTimer:  null,
      // [C5] Rate-limit: lock after 3 consecutive failures for 30s
      _pinFailCount: 0,
      pinLocked:     false,
      pinLockSeconds: 0,
      _pinLockTimer:  null,
      _pinLockCountdown: null,
      // Admin
      busy:              null,
      feedback:          '',
      feedbackType:      'success',
      terminalConnected: false,
      terminalModel:     '',
      _feedbackTimer:    null,
      // [C5] Maintenance mode state
      showMaintenanceConfirm: false,
    };
  },

  computed: {
    isElectron() {
      return !!window.borne?.isElectron;
    },

    // Read PIN from settings (kiosk_admin_pin) — falls back to DEFAULT_PIN
    adminPin() {
      const lists = this.$store.state.globalState?.lists;
      return lists?.kiosk_admin_pin || DEFAULT_PIN;
    },

    menuCacheAge() {
      const lastFetch = this.$store.state.kioskMenu?.lastFetchedAt;
      if (!lastFetch) return null;
      return Math.floor((Date.now() - lastFetch) / 60000);
    },
  },

  methods: {
    ...mapActions('kioskCart', ['reset']),
    ...mapActions('kioskMenu', ['fetchMenu']),

    // ── PIN ──────────────────────────────────────────────────────────────

    handleOverlayClick() {
      // Only close if PIN not yet entered (prevent accidental close on admin panel)
      if (!this.pinUnlocked) this.$emit('close');
    },

    handlePinKey(key) {
      // [C5] Reject input while locked
      if (this.pinLocked) return;

      if (key === '⌫') {
        this.pinInput = this.pinInput.slice(0, -1);
        this.pinError = false;
        return;
      }
      if (typeof key !== 'number') return;
      if (this.pinInput.length >= 4) return;

      this.pinInput += String(key);

      if (this.pinInput.length === 4) {
        this._checkPin();
      }
    },

    _checkPin() {
      if (!this.adminPin) {
        this.pinInput = '';
        this.pinError = true;
        clearTimeout(this._pinErrTimer);
        this._pinErrTimer = setTimeout(() => {
          this.pinError = false;
        }, 1600);
        this.feedback = this.$t('kiosk.admin_screen.pin_unconfigured');
        this.feedbackType = 'error';
        return;
      }

      if (this.pinInput === this.adminPin) {
        this.pinUnlocked = true;
        this.pinError = false;
        this._pinFailCount = 0;
      } else {
        this._pinFailCount++;
        this.pinError = true;
        clearTimeout(this._pinErrTimer);
        this._pinErrTimer = setTimeout(() => {
          this.pinInput = '';
          this.pinError = false;
        }, 1200);

        // [C5] Lock after 3 consecutive failures for 30s
        if (this._pinFailCount >= 3) {
          this._startPinLockout();
        }
      }
    },

    _startPinLockout() {
      const LOCK_SECONDS = 30;
      this.pinLocked = true;
      this.pinLockSeconds = LOCK_SECONDS;
      this.pinInput = '';
      this.pinError = false;
      this._pinFailCount = 0;

      clearInterval(this._pinLockCountdown);
      this._pinLockCountdown = setInterval(() => {
        this.pinLockSeconds--;
        if (this.pinLockSeconds <= 0) {
          this.pinLocked = false;
          clearInterval(this._pinLockCountdown);
          this._pinLockCountdown = null;
        }
      }, 1000);
    },

    // ── Admin actions ─────────────────────────────────────────────────────

    showFeedback(msg, type = 'success') {
      clearTimeout(this._feedbackTimer);
      this.feedback = msg;
      this.feedbackType = type;
      this._feedbackTimer = setTimeout(() => { this.feedback = ''; }, 3500);
    },

    async testPrint() {
      this.busy = 'print';
      try {
        if (!this.isElectron) {
          this.showFeedback(this.$t('kiosk.admin_screen.fb_print_browser'));
          return;
        }
        const result = await window.borne.printReceipt({
          queue_number:    'TEST',
          total:           0,
          items:           [{ name: this.$t('kiosk.admin_screen.test_ticket_name'), quantity: 1, total_price: 0 }],
          restaurant_name: 'FoodKing',
        });
        if (result?.success || result?.skipped) {
          this.showFeedback(this.$t('kiosk.admin_screen.fb_print_ok'));
        } else {
          this.showFeedback(
            this.$t('kiosk.admin_screen.fb_print_err', {
              detail: result?.error || this.$t('kiosk.admin_screen.unknown_detail'),
            }),
            'error'
          );
        }
      } catch (e) {
        this.showFeedback(this.$t('kiosk.admin_screen.fb_err_generic', { detail: e.message }), 'error');
      } finally {
        this.busy = null;
      }
    },

    async openDrawer() {
      this.busy = 'drawer';
      try {
        if (!this.isElectron) {
          this.showFeedback(this.$t('kiosk.admin_screen.fb_drawer_browser'));
          return;
        }
        const result = await window.borne.openDrawer();
        if (result?.success || result?.skipped) {
          this.showFeedback(this.$t('kiosk.admin_screen.fb_drawer_ok'));
        } else {
          this.showFeedback(
            this.$t('kiosk.admin_screen.fb_drawer_err', {
              detail: result?.error || this.$t('kiosk.admin_screen.unknown_detail'),
            }),
            'error'
          );
        }
      } catch (e) {
        this.showFeedback(this.$t('kiosk.admin_screen.fb_err_generic', { detail: e.message }), 'error');
      } finally {
        this.busy = null;
      }
    },

    async checkTerminal() {
      this.busy = 'terminal';
      try {
        if (!this.isElectron) {
          this.terminalConnected = true;
          this.terminalModel = this.$t('kiosk.admin_screen.fb_terminal_stub');
          this.showFeedback(this.$t('kiosk.admin_screen.fb_terminal_browser'));
          return;
        }
        const status = await window.borne.getPaymentStatus();
        this.terminalConnected = !!status?.connected;
        this.terminalModel = status?.model || '';
        if (status?.connected) {
          this.showFeedback(
            this.$t('kiosk.admin_screen.fb_terminal_ok', {
              model: status.model || this.$t('kiosk.admin_screen.unknown_detail'),
            })
          );
        } else {
          this.showFeedback(this.$t('kiosk.admin_screen.fb_terminal_err'), 'error');
        }
      } catch (e) {
        this.terminalConnected = false;
        this.showFeedback(this.$t('kiosk.admin_screen.fb_err_generic', { detail: e.message }), 'error');
      } finally {
        this.busy = null;
      }
    },

    async refreshMenu() {
      this.busy = 'menu';
      try {
        const branchId = this.$store.state.kioskCart?.branchId;
        await this.fetchMenu({ force: true, branchId });
        const count = this.$store.getters['kioskMenu/allItems'].length;
        this.showFeedback(this.$t('kiosk.admin_screen.fb_menu_ok', { count }));
      } catch (e) {
        this.showFeedback(
          this.$t('kiosk.admin_screen.fb_menu_err', { detail: e.message }),
          'error'
        );
      } finally {
        this.busy = null;
      }
    },

    async reloadApp() {
      if (this.isElectron && window.borne?.reload) {
        await window.borne.reload();
      } else {
        window.location.reload();
      }
    },

    async logout() {
      const hasAutoLogin = !!(
        typeof window !== 'undefined' &&
        window.foodkingConfig?.kioskAutoLogin?.username
      );
      if (hasAutoLogin) {
        // [C5] Auto-login is active — warn staff and offer maintenance mode
        this.showMaintenanceConfirm = true;
        return;
      }
      await this._doLogout();
    },

    async _doLogout() {
      await this.reset();
      this.$router.push({ name: 'kiosk.login' });
    },

    async enterMaintenanceMode() {
      // [C5] Set sessionStorage flag so getKioskAutoCredentials() returns null
      // until the page is reloaded (session cleared on next normal boot).
      try {
        sessionStorage.setItem('kiosk_maintenance_mode', '1');
      } catch (_) { /* ignore */ }
      this.showMaintenanceConfirm = false;
      await this._doLogout();
    },

    cancelMaintenanceMode() {
      this.showMaintenanceConfirm = false;
    },

    async quitApp() {
      if (this.isElectron && window.borne?.quit) {
        await window.borne.quit();
      }
    },
  },

  beforeUnmount() {
    clearTimeout(this._feedbackTimer);
    clearTimeout(this._pinErrTimer);
    clearTimeout(this._pinLockTimer);
    clearInterval(this._pinLockCountdown);
  },
};
</script>

<style scoped>
.kiosk-admin-overlay {
  position: fixed;
  inset: 0;
  z-index: 9999;
  background: rgba(0, 0, 0, 0.88);
  display: flex;
  align-items: center;
  justify-content: center;
  backdrop-filter: blur(8px);
}

.kiosk-admin-card {
  background: #1a1a2e;
  border: 1px solid rgba(255, 255, 255, 0.1);
  border-radius: 28px;
  padding: 40px;
  width: 480px;
  max-height: 90vh;
  overflow-y: auto;
  position: relative;
  box-shadow: 0 40px 80px rgba(0, 0, 0, 0.6);
}

/* ── PIN card ─────────────────────────────────────────────────────────── */
.kiosk-pin-card {
  width: 380px;
  text-align: center;
  padding: 48px 40px 40px;
}

.kiosk-pin-icon { font-size: 48px; margin-bottom: 16px; }

.kiosk-pin-title {
  font-size: 22px;
  font-weight: 800;
  color: white;
  margin: 0 0 8px;
}

.kiosk-pin-sub {
  font-size: 14px;
  color: rgba(255, 255, 255, 0.45);
  margin: 0 0 28px;
}

.kiosk-pin-dots {
  display: flex;
  justify-content: center;
  gap: 16px;
  margin-bottom: 12px;
}

.kiosk-pin-dot {
  width: 18px;
  height: 18px;
  border-radius: 50%;
  border: 2px solid rgba(255, 255, 255, 0.25);
  background: transparent;
  transition: all 0.15s;
}
.kiosk-pin-dot.filled {
  background: #e8001c;
  border-color: #e8001c;
  transform: scale(1.1);
}
.kiosk-pin-dot.error {
  border-color: #ff6b7a;
  background: rgba(232, 0, 28, 0.3);
  animation: pin-shake 0.3s ease;
}

@keyframes pin-shake {
  0%, 100% { transform: translateX(0); }
  25%       { transform: translateX(-4px); }
  75%       { transform: translateX(4px); }
}

.kiosk-pin-error {
  font-size: 13px;
  color: #ff6b7a;
  margin: 0 0 16px;
  height: 18px;
}

.kiosk-pin-numpad {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 12px;
  margin-top: 20px;
}

.kiosk-pin-key {
  height: 72px;
  background: rgba(255, 255, 255, 0.06);
  border: 1px solid rgba(255, 255, 255, 0.1);
  border-radius: 16px;
  color: white;
  font-size: 24px;
  font-weight: 700;
  cursor: pointer;
  transition: all 0.12s;
  display: flex;
  align-items: center;
  justify-content: center;
}
.kiosk-pin-key:hover:not(:disabled) {
  background: rgba(255, 255, 255, 0.12);
  border-color: rgba(255, 255, 255, 0.2);
}
.kiosk-pin-key:active:not(:disabled) {
  transform: scale(0.94);
  background: rgba(232, 0, 28, 0.15);
  border-color: rgba(232, 0, 28, 0.4);
}
.kiosk-pin-key.is-empty {
  background: transparent;
  border-color: transparent;
  cursor: default;
  pointer-events: none;
}
.kiosk-pin-key.is-del {
  color: rgba(255, 255, 255, 0.5);
  font-size: 20px;
}

/* ── Admin panel ──────────────────────────────────────────────────────── */
.kiosk-admin-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  margin-bottom: 32px;
}

.kiosk-admin-header h2 {
  font-size: 24px;
  font-weight: 800;
  color: white;
  margin: 0 0 6px;
}

.kiosk-admin-sub {
  font-size: 14px;
  color: rgba(255, 255, 255, 0.4);
  margin: 0;
}

.kiosk-admin-close {
  width: 40px;
  height: 40px;
  background: rgba(255, 255, 255, 0.08);
  border: 1px solid rgba(255, 255, 255, 0.12);
  border-radius: 50%;
  color: rgba(255, 255, 255, 0.6);
  font-size: 16px;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: all 0.15s;
  flex-shrink: 0;
  /* For PIN card absolute positioning */
  position: absolute;
  top: 20px;
  right: 20px;
}
.kiosk-admin-close:hover { background: rgba(255, 255, 255, 0.15); color: white; }

.kiosk-admin-section {
  margin-bottom: 24px;
  padding-bottom: 24px;
  border-bottom: 1px solid rgba(255, 255, 255, 0.07);
}
.kiosk-admin-section:last-of-type { border-bottom: none; margin-bottom: 0; }

.kiosk-admin-section h3 {
  font-size: 11px;
  font-weight: 700;
  color: rgba(255, 255, 255, 0.3);
  text-transform: uppercase;
  letter-spacing: 1.5px;
  margin: 0 0 12px;
}

.kiosk-admin-btn {
  display: flex;
  align-items: center;
  gap: 12px;
  width: 100%;
  padding: 13px 16px;
  margin-bottom: 8px;
  background: rgba(255, 255, 255, 0.05);
  border: 1px solid rgba(255, 255, 255, 0.08);
  border-radius: 12px;
  color: rgba(255, 255, 255, 0.85);
  font-size: 14px;
  font-weight: 600;
  cursor: pointer;
  text-align: left;
  transition: all 0.15s;
}
.kiosk-admin-btn:last-child { margin-bottom: 0; }
.kiosk-admin-btn:hover:not(:disabled) {
  background: rgba(255, 255, 255, 0.1);
  border-color: rgba(255, 255, 255, 0.15);
  color: white;
}
.kiosk-admin-btn:disabled { opacity: 0.4; cursor: not-allowed; }
.kiosk-admin-btn.danger { color: #ff6b7a; border-color: rgba(232, 0, 28, 0.2); }
.kiosk-admin-btn.danger:hover:not(:disabled) {
  background: rgba(232, 0, 28, 0.1);
  border-color: rgba(232, 0, 28, 0.35);
}

.btn-icon { font-size: 18px; }

.kiosk-admin-status-row {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 10px;
  font-size: 14px;
  color: rgba(255, 255, 255, 0.55);
}
.status-ok  { color: #22c55e; font-weight: 700; }
.status-err { color: #ff6b7a; font-weight: 700; }

.kiosk-admin-feedback {
  margin-top: 16px;
  padding: 12px 16px;
  border-radius: 10px;
  font-size: 13px;
  font-weight: 600;
  text-align: center;
}
.kiosk-admin-feedback.success {
  background: rgba(34, 197, 94, 0.12);
  border: 1px solid rgba(34, 197, 94, 0.3);
  color: #22c55e;
}
.kiosk-admin-feedback.error {
  background: rgba(232, 0, 28, 0.1);
  border: 1px solid rgba(232, 0, 28, 0.3);
  color: #ff6b7a;
}

/* [C5] PIN lockout message */
.kiosk-pin-locked {
  font-size: 13px;
  color: #f39c12;
  margin: 0 0 16px;
  font-weight: 600;
}

/* [C5] Maintenance mode overlay */
.kiosk-maintenance-overlay {
  position: fixed;
  inset: 0;
  z-index: 10000;
  background: rgba(0, 0, 0, 0.75);
  display: flex;
  align-items: center;
  justify-content: center;
  backdrop-filter: blur(4px);
}

.kiosk-maintenance-card {
  background: #1a1a2e;
  border: 1px solid rgba(243, 156, 18, 0.4);
  border-radius: 20px;
  padding: 36px;
  width: 440px;
  max-width: 90vw;
  text-align: center;
}

.kiosk-maintenance-icon { font-size: 40px; margin-bottom: 12px; }

.kiosk-maintenance-card h3 {
  font-size: 18px;
  font-weight: 800;
  color: white;
  margin: 0 0 12px;
}

.kiosk-maintenance-card p {
  font-size: 14px;
  color: rgba(255, 255, 255, 0.6);
  line-height: 1.6;
  margin: 0 0 24px;
}

.kiosk-maintenance-actions {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

/* Transitions */
.fade-enter-active, .fade-leave-active { transition: opacity 0.25s; }
.fade-enter-from, .fade-leave-to { opacity: 0; }

.fade-scale-enter-active, .fade-scale-leave-active { transition: all 0.2s ease; }
.fade-scale-enter-from { opacity: 0; transform: scale(0.95); }
.fade-scale-leave-to   { opacity: 0; transform: scale(1.02); }
</style>
