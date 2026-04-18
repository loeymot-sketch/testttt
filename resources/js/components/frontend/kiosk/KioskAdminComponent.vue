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
          <!-- [PHASE-6.5] Badge hardware health en haut du panel -->
          <div
            class="kiosk-admin-health-badge"
            :class="['tone-' + healthBadge.tone]"
            data-testid="kiosk-admin-health-badge"
            :title="$t('kiosk.admin_screen.health_badge_hint')"
          >
            <span class="kiosk-admin-health-dot" aria-hidden="true" />
            <span>{{ healthBadge.label }}</span>
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

        <!-- [PHASE-6.5] Hardware health snapshot (détaillé) -->
        <div class="kiosk-admin-section" data-testid="kiosk-admin-health-section">
          <h3>{{ $t('kiosk.admin_screen.section_health') }}</h3>
          <div class="kiosk-admin-status-row">
            <span>{{ $t('kiosk.admin_screen.health_state_label') }}</span>
            <span :class="'status-' + healthBadge.tone" data-testid="kiosk-admin-health-state">
              {{ healthBadge.label }}
            </span>
          </div>
          <div v-if="healthSnapshot?.components" class="kiosk-admin-health-list">
            <div
              v-for="(val, key) in healthSnapshot.components"
              :key="key"
              class="kiosk-admin-health-item"
            >
              <span class="kiosk-admin-health-key">{{ key }}</span>
              <span class="kiosk-admin-health-val" :class="{ ko: val === false || val === 'error' || val === 'paper_out' }">
                {{ String(val) }}
              </span>
            </div>
          </div>
          <button
            class="kiosk-admin-btn"
            @click="refreshHealth"
            :disabled="busy === 'health'"
            data-testid="kiosk-admin-health-refresh"
          >
            <span class="btn-icon">🩺</span>
            <span>{{ busy === 'health' ? $t('kiosk.admin_screen.health_refresh_busy') : $t('kiosk.admin_screen.health_refresh') }}</span>
          </button>
        </div>

        <!-- [PHASE-6.5] Idle timeouts — configurables par staff, persistés localStorage -->
        <div class="kiosk-admin-section" data-testid="kiosk-admin-idle-section">
          <h3>{{ $t('kiosk.admin_screen.section_idle') }}</h3>
          <p class="kiosk-admin-idle-hint">{{ $t('kiosk.admin_screen.idle_hint') }}</p>
          <div v-if="idleDraft" class="kiosk-admin-idle-grid">
            <label class="kiosk-admin-field">
              <span>{{ $t('kiosk.admin_screen.idle_field') }}</span>
              <input
                type="number"
                :min="idleBounds.idleMs.min"
                :max="idleBounds.idleMs.max"
                step="1000"
                v-model.number="idleDraft.idleMs"
                data-testid="kiosk-admin-idle-idle"
              />
              <small>{{ $t('kiosk.admin_screen.idle_range', { min: idleBounds.idleMs.min / 1000, max: idleBounds.idleMs.max / 1000 }) }}</small>
            </label>
            <label class="kiosk-admin-field">
              <span>{{ $t('kiosk.admin_screen.idle_confirm_field') }}</span>
              <input
                type="number"
                :min="idleBounds.confirmMs.min"
                :max="idleBounds.confirmMs.max"
                step="500"
                v-model.number="idleDraft.confirmMs"
                data-testid="kiosk-admin-idle-confirm"
              />
              <small>{{ $t('kiosk.admin_screen.idle_range', { min: idleBounds.confirmMs.min / 1000, max: idleBounds.confirmMs.max / 1000 }) }}</small>
            </label>
            <label class="kiosk-admin-field">
              <span>{{ $t('kiosk.admin_screen.idle_receipt_field') }}</span>
              <input
                type="number"
                :min="idleBounds.receiptMs.min"
                :max="idleBounds.receiptMs.max"
                step="1000"
                v-model.number="idleDraft.receiptMs"
                data-testid="kiosk-admin-idle-receipt"
              />
              <small>{{ $t('kiosk.admin_screen.idle_range', { min: idleBounds.receiptMs.min / 1000, max: idleBounds.receiptMs.max / 1000 }) }}</small>
            </label>
          </div>
          <div class="kiosk-admin-actions-row">
            <button
              class="kiosk-admin-btn"
              @click="saveIdleTimeouts"
              :disabled="busy === 'idle'"
              data-testid="kiosk-admin-idle-save"
            >
              <span class="btn-icon">💾</span>
              <span>{{ busy === 'idle' ? $t('kiosk.admin_screen.idle_saving') : $t('kiosk.admin_screen.idle_save') }}</span>
            </button>
            <button
              class="kiosk-admin-btn"
              @click="resetIdleTimeouts"
              data-testid="kiosk-admin-idle-reset"
            >
              <span class="btn-icon">↺</span>
              <span>{{ $t('kiosk.admin_screen.idle_reset_defaults') }}</span>
            </button>
          </div>
        </div>

        <!-- [PHASE-6.5] RGPD — consent admin override (debug / reset opt-out) -->
        <div class="kiosk-admin-section" data-testid="kiosk-admin-consent-section">
          <h3>{{ $t('kiosk.admin_screen.section_consent') }}</h3>
          <p class="kiosk-admin-idle-hint">{{ $t('kiosk.admin_screen.consent_hint') }}</p>
          <label class="kiosk-admin-consent-row">
            <input
              type="checkbox"
              v-model="consentDraft.analytics"
              data-testid="kiosk-admin-consent-analytics"
            />
            <span>{{ $t('kiosk.admin_screen.consent_analytics') }}</span>
          </label>
          <label class="kiosk-admin-consent-row">
            <input
              type="checkbox"
              v-model="consentDraft.loyalty"
              data-testid="kiosk-admin-consent-loyalty"
            />
            <span>{{ $t('kiosk.admin_screen.consent_loyalty') }}</span>
          </label>
          <button
            class="kiosk-admin-btn"
            @click="saveConsent"
            :disabled="busy === 'consent'"
            data-testid="kiosk-admin-consent-save"
          >
            <span class="btn-icon">💾</span>
            <span>{{ busy === 'consent' ? $t('kiosk.admin_screen.consent_saving') : $t('kiosk.admin_screen.consent_save') }}</span>
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
// [PHASE-6.5] Passage à l'API unifiée — plus d'accès direct à window.borne.*.
import kioskHardware from '../../../services/kioskHardware';
// [PHASE-6.5] Bornes/valeurs par défaut pour les sliders idle config.
import { KIOSK_SETTINGS_CONSTANTS } from '../../../store/modules/kioskSettings';

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
      // [PHASE-6.5] Hardware health snapshot (rafraîchi sur unlock + bouton refresh).
      healthSnapshot: null,
      _healthRefreshTimer: null,
      // [PHASE-6.5] Edits locaux des sliders idle — appliqués au store sur save.
      idleDraft: null, // { idleMs, confirmMs, receiptMs }
      consentDraft: { analytics: false, loyalty: false },
    };
  },

  computed: {
    isElectron() {
      // [PHASE-6.5] Via le service unifié — évite un accès direct à window.borne.
      return kioskHardware.isKioskBridge();
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

    // [PHASE-6.5] Sliders idle config — bornes et défauts exposés par kioskSettings.
    idleBounds() {
      return KIOSK_SETTINGS_CONSTANTS.IDLE_BOUNDS;
    },
    idleDefaults() {
      return KIOSK_SETTINGS_CONSTANTS.IDLE_DEFAULTS;
    },
    storedIdleTimeouts() {
      const s = this.$store.state.kioskSettings || {};
      return {
        idleMs: Number(s.idleMs) || this.idleDefaults.idleMs,
        confirmMs: Number(s.confirmMs) || this.idleDefaults.confirmMs,
        receiptMs: Number(s.receiptMs) || this.idleDefaults.receiptMs,
      };
    },
    storedConsent() {
      const s = this.$store.state.kioskSettings || {};
      return {
        analytics: !!s.consentAnalytics,
        loyalty: !!s.consentLoyalty,
      };
    },
    // [PHASE-6.5] Label rapide pour le badge hardware en haut du panel.
    healthBadge() {
      const hc = this.healthSnapshot;
      if (!hc) return { label: this.$t('kiosk.admin_screen.health_unknown'), tone: 'muted' };
      const state = hc.state || 'unknown';
      if (state === 'ok') return { label: this.$t('kiosk.admin_screen.health_ok'), tone: 'ok' };
      if (state === 'degraded') return { label: this.$t('kiosk.admin_screen.health_degraded'), tone: 'warn' };
      if (state === 'critical') return { label: this.$t('kiosk.admin_screen.health_critical'), tone: 'error' };
      return { label: state, tone: 'muted' };
    },
  },

  watch: {
    pinUnlocked(unlocked) {
      if (unlocked) {
        this._initEditableState();
        this._refreshHealth();
      }
    },
  },

  beforeUnmount() {
    clearTimeout(this._feedbackTimer);
    clearTimeout(this._pinErrTimer);
    clearInterval(this._pinLockCountdown);
    if (this._healthRefreshTimer) {
      clearInterval(this._healthRefreshTimer);
      this._healthRefreshTimer = null;
    }
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
        // [PHASE-6.5] Passage par kioskHardware — contrat {ok, error?} uniforme.
        const r = await kioskHardware.printReceipt({
          queue_number:    'TEST',
          total:           0,
          items:           [{ name: this.$t('kiosk.admin_screen.test_ticket_name'), quantity: 1, total_price: 0 }],
          restaurant_name: 'FoodKing',
        });
        if (r?.ok) {
          this.showFeedback(this.$t('kiosk.admin_screen.fb_print_ok'));
        } else {
          this.showFeedback(
            this.$t('kiosk.admin_screen.fb_print_err', {
              detail: r?.error || this.$t('kiosk.admin_screen.unknown_detail'),
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
        const r = await kioskHardware.openDrawer();
        if (r?.ok) {
          this.showFeedback(this.$t('kiosk.admin_screen.fb_drawer_ok'));
        } else {
          this.showFeedback(
            this.$t('kiosk.admin_screen.fb_drawer_err', {
              detail: r?.error || this.$t('kiosk.admin_screen.unknown_detail'),
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
        // [PHASE-6.5] On interroge le healthcheck unifié (interprète tpe_status).
        // Conserve l'ancienne clé globalState.lists + affiche un feedback détaillé.
        const hc = await kioskHardware.healthcheck();
        this.healthSnapshot = hc.ok ? hc : { state: 'critical', components: {} };
        const tpeKey = hc?.components?.tpe_status ?? hc?.components?.tpe;
        const connected = tpeKey === true || tpeKey === 'ready' || tpeKey === 'ok';
        this.terminalConnected = connected;
        this.terminalModel = hc?.raw?.tpe_model || hc?.components?.tpe_model || '';
        if (connected) {
          this.showFeedback(
            this.$t('kiosk.admin_screen.fb_terminal_ok', {
              model: this.terminalModel || this.$t('kiosk.admin_screen.unknown_detail'),
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

    /**
     * [PHASE-6.5] Rafraîchit le snapshot hardware (badge en tête du panel).
     * Appelé à l'unlock + bouton refresh. Silencieux sur erreur — le badge
     * affichera simplement `unknown` si le bridge ne répond pas.
     */
    async _refreshHealth() {
      try {
        const hc = await kioskHardware.healthcheck();
        if (hc && typeof hc === 'object') {
          this.healthSnapshot = hc.ok !== false ? hc : { state: 'critical', components: {} };
        }
      } catch (_) {
        this.healthSnapshot = { state: 'critical', components: {} };
      }
    },
    async refreshHealth() {
      this.busy = 'health';
      await this._refreshHealth();
      this.busy = null;
      this.showFeedback(this.$t('kiosk.admin_screen.fb_health_refreshed'));
    },

    /**
     * [PHASE-6.5] Initialise les drafts éditables depuis le store quand
     * l'admin rentre dans le panel (après validation du PIN).
     */
    _initEditableState() {
      this.idleDraft = { ...this.storedIdleTimeouts };
      this.consentDraft = { ...this.storedConsent };
    },

    /**
     * [PHASE-6.5] Sauvegarde les idle timeouts via l'action kioskSettings/setIdleTimeouts.
     * Les valeurs sont coercées (bornes min/max) côté store — le slider s'auto-corrige.
     */
    async saveIdleTimeouts() {
      if (!this.idleDraft) return;
      this.busy = 'idle';
      try {
        // [PHASE-7.1] Snapshot avant écriture pour traçabilité ActionLog (audit staff).
        const before = { ...this.storedIdleTimeouts };
        await this.$store.dispatch('kioskSettings/setIdleTimeouts', { ...this.idleDraft });
        // Refresh le draft avec les valeurs post-coerce du store.
        this.idleDraft = { ...this.storedIdleTimeouts };
        // [PHASE-7.1] Log côté serveur (ActionLog + hardware log). Best-effort.
        this._logAdminOverride('idle_timeouts', {
          before,
          after: { ...this.storedIdleTimeouts },
        });
        this.showFeedback(this.$t('kiosk.admin_screen.fb_idle_saved'));
      } catch (e) {
        this.showFeedback(this.$t('kiosk.admin_screen.fb_err_generic', { detail: e?.message || 'unknown' }), 'error');
      } finally {
        this.busy = null;
      }
    },

    resetIdleTimeouts() {
      this.idleDraft = { ...this.idleDefaults };
    },

    async saveConsent() {
      this.busy = 'consent';
      try {
        // [PHASE-7.1] Snapshot avant écriture pour traçabilité ActionLog (audit staff RGPD).
        const before = { ...this.storedConsent };
        await this.$store.dispatch('kioskSettings/setConsentAnalytics', this.consentDraft.analytics);
        await this.$store.dispatch('kioskSettings/setConsentLoyalty', this.consentDraft.loyalty);
        // [PHASE-7.1] Log côté serveur — override RGPD par staff, traçable légalement.
        this._logAdminOverride('consent_override', {
          before,
          after: { ...this.storedConsent },
        });
        this.showFeedback(this.$t('kiosk.admin_screen.fb_consent_saved'));
      } catch (e) {
        this.showFeedback(this.$t('kiosk.admin_screen.fb_err_generic', { detail: e?.message || 'unknown' }), 'error');
      } finally {
        this.busy = null;
      }
    },

    /**
     * [PHASE-7.1] Émet un event `admin_action` vers le backend pour tracer
     * les modifications staff sensibles (idle timeouts, consent override).
     *
     * Passe par `window.axios.post('frontend/kiosk-event', ...)` (kiosk token auth
     * via Sanctum). Silent fail — si le backend est injoignable, l'override local
     * persiste (localStorage) mais la trace serveur sera manquante. C'est documenté
     * comme un risque acceptable (pas de blocage staff en cas de réseau coupé).
     *
     * Pas de PII dans le payload — seulement des clés/valeurs de config.
     */
    _logAdminOverride(subtype, context) {
      try {
        if (!window.axios) return;
        window.axios.post('frontend/kiosk-event', {
          type: 'admin_action',
          subtype,
          context,
          details: `admin_override:${subtype}`,
        }).catch(() => {});
      } catch (_) { /* silent */ }
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
      // [PHASE-6.5] Via service unifié — fallback sur reload navigateur si bridge absent.
      if (this.isElectron) {
        const r = await kioskHardware.reload();
        if (!r?.ok) window.location.reload();
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
      if (this.isElectron) {
        await kioskHardware.quit();
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

/* [PHASE-6.5] Health badge dans le header admin */
.kiosk-admin-health-badge {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 6px 12px;
  border-radius: 999px;
  font-size: 12px;
  font-weight: 700;
  background: rgba(255, 255, 255, 0.06);
  border: 1px solid rgba(255, 255, 255, 0.12);
  color: rgba(255, 255, 255, 0.85);
  margin-left: auto;
  margin-right: 12px;
}
.kiosk-admin-health-dot {
  width: 8px; height: 8px; border-radius: 50%;
  background: currentColor;
}
.kiosk-admin-health-badge.tone-ok       { color: #2ecc71; border-color: rgba(46,204,113,0.35); }
.kiosk-admin-health-badge.tone-warn     { color: #f39c12; border-color: rgba(243,156,18,0.35); }
.kiosk-admin-health-badge.tone-error    { color: #e74c3c; border-color: rgba(231,76,60,0.35); }
.kiosk-admin-health-badge.tone-muted    { color: rgba(255,255,255,0.6); }

/* Health list */
.kiosk-admin-health-list {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 6px 12px;
  margin: 8px 0 10px;
}
.kiosk-admin-health-item {
  display: flex;
  justify-content: space-between;
  font-size: 12px;
  padding: 4px 8px;
  background: rgba(255,255,255,0.04);
  border-radius: 6px;
}
.kiosk-admin-health-key { color: rgba(255,255,255,0.6); font-weight: 600; }
.kiosk-admin-health-val { font-weight: 700; color: #2ecc71; }
.kiosk-admin-health-val.ko { color: #e74c3c; }

.status-ok     { color: #2ecc71; font-weight: 700; }
.status-warn   { color: #f39c12; font-weight: 700; }
.status-error  { color: #e74c3c; font-weight: 700; }
.status-muted  { color: rgba(255,255,255,0.6); }

/* Idle grid & consent rows */
.kiosk-admin-idle-hint {
  font-size: 12px;
  color: rgba(255,255,255,0.55);
  margin: 0 0 10px;
  line-height: 1.4;
}
.kiosk-admin-idle-grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 10px;
  margin-bottom: 10px;
}
.kiosk-admin-field {
  display: flex;
  flex-direction: column;
  gap: 4px;
  font-size: 12px;
  color: rgba(255,255,255,0.85);
}
.kiosk-admin-field input[type="number"] {
  background: rgba(255,255,255,0.08);
  border: 1px solid rgba(255,255,255,0.15);
  color: white;
  padding: 8px;
  border-radius: 8px;
  font-size: 14px;
  font-weight: 700;
}
.kiosk-admin-field small {
  color: rgba(255,255,255,0.45);
  font-size: 10px;
}
.kiosk-admin-actions-row {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
}
.kiosk-admin-consent-row {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 8px 0;
  font-size: 13px;
  color: rgba(255,255,255,0.85);
  cursor: pointer;
}
.kiosk-admin-consent-row input[type="checkbox"] {
  width: 20px;
  height: 20px;
  accent-color: #E8001C;
}

@media (max-width: 640px) {
  .kiosk-admin-idle-grid,
  .kiosk-admin-health-list { grid-template-columns: 1fr; }
}
</style>
