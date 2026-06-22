<template>
  <div class="kiosk-login-screen">
    <div class="kiosk-login-card">
      <div class="kiosk-login-logo">
        <div class="kiosk-login-icon">🖥️</div>
        <h1 class="kiosk-login-title">{{ $t('kiosk.login_screen.title') }}</h1>
        <p class="kiosk-login-sub">{{ $t('kiosk.login_screen.sub') }}</p>
      </div>

      <div class="kiosk-login-form kiosk-login-auto">
        <div class="kiosk-login-status">
          <span v-if="loading" class="kiosk-login-spinner"></span>
          <span v-else class="kiosk-login-status-icon">!</span>
          <p class="kiosk-login-status-text">
            <template v-if="loading">{{ $t('kiosk.login_screen.status_loading') }}</template>
            <template v-else-if="setupRequired">{{ $t('kiosk.login_screen.status_missing_env') }}</template>
            <template v-else>{{ $t('kiosk.login_screen.status_retrying') }}</template>
          </p>
        </div>

        <transition name="fade">
          <p v-if="error" class="kiosk-login-error">{{ error }}</p>
        </transition>

        <p class="kiosk-login-hint kiosk-login-hint-center">
          {{ $t('kiosk.login_screen.public_hint') }}
        </p>

        <button type="button" class="kiosk-login-btn" :disabled="loading" @click="retryAutoLogin">
          <span v-if="!loading">{{ $t('kiosk.login_screen.retry') }}</span>
          <span v-else class="kiosk-login-spinner"></span>
        </button>

      </div>

      <p v-if="showDevSeedHint" class="kiosk-login-devhint">
        {{ $t('kiosk.login_screen.devhint') }}
      </p>
      <p class="kiosk-login-footer">
        {{ $t('kiosk.login_screen.footer') }}
      </p>
    </div>
  </div>
</template>

<script>
import { mapActions } from 'vuex';

export default {
  name: 'KioskLoginComponent',
  computed: {
    showDevSeedHint() {
      return process.env.NODE_ENV !== 'production';
    },
  },
  data() {
    return {
      loading: false,
      error: null,
      /** True when KIOSK_MACHINE_* / kioskAutoLogin absent — not a "reconnecting" WebSocket state */
      setupRequired: false,
      retryTimer: null,
      retryAttempts: 0,
    };
  },
  mounted() {
    this.startAutoLogin();
  },
  beforeUnmount() {
    clearTimeout(this.retryTimer);
  },
  methods: {
    ...mapActions('kioskCart', ['kioskLogin']),

    getAutoCredentials() {
      const auto = window.foodkingConfig?.kioskAutoLogin || null;
      if (!auto?.username || auto.password === undefined || auto.password === null || String(auto.password) === '') {
        return null;
      }
      return {
        username: String(auto.username).trim(),
        password: String(auto.password),
      };
    },

    scheduleRetry() {
      if (this.retryAttempts >= 10) {
        return;
      }
      clearTimeout(this.retryTimer);
      const delayMs = Math.min(30000, 4000 * Math.max(1, 2 ** Math.max(0, this.retryAttempts - 1)));
      this.retryTimer = setTimeout(() => {
        this.startAutoLogin();
      }, delayMs);
    },

    async startAutoLogin() {
      const auto = this.getAutoCredentials();
      if (!auto) {
        this.setupRequired = true;
        this.error = this.$t('kiosk.login_screen.err_no_credentials');
        return;
      }
      this.setupRequired = false;
      clearTimeout(this.retryTimer);
      this.loading = true;
      this.error = null;
      try {
        await this.kioskLogin(auto);
        this.retryAttempts = 0;
        this.$router.replace({ name: 'kiosk.idle' });
      } catch (err) {
        // [iter15-mega-fix D-007 2026-05-10] 429 must surface a localized
        // user-facing message — not the raw axios `Request failed with
        // status code 429`. The Laravel response body carries `{message,
        // retry_after}`, but the UI prefers the i18n key when available so
        // the wording matches the locale of the borne and never leaks
        // internal axios strings (D-007 i18n leak observed on iter15
        // mega-audit Wave D state 05).
        const status = err?.response?.status;
        let msg;
        if (status === 429) {
          msg = this.$t('kiosk.login_screen.err_rate_limited');
        } else {
          // [audit-360 2026-06-21 P1] PUBLIC borne: never echo the backend auth
          // copy ("Identifiants invalides ou compte bloqué") to a customer — it
          // is a staff/infra concern, meaningless and alarming on a public
          // screen. Auto-connect carries no on-screen credentials, so field/
          // validation errors are not customer-actionable. Always show the
          // localized public-safe message for non-429 failures. (429 keeps its
          // own localized message above.) The backend string stays correct for
          // the admin LoginController; only the borne suppresses it.
          msg = this.$t('kiosk.login_screen.err_login_failed');
        }
        this.error = msg;
        this.retryAttempts += 1;
        this.scheduleRetry();
      } finally {
        this.loading = false;
      }
    },

    retryAutoLogin() {
      this.retryAttempts = 0;
      this.startAutoLogin();
    },
  },
};
</script>

<style scoped>
/* [audit-360 2026-06-21 P2] Re-skinned dark -> LIGHT. The borne mandate is
   light-mode 100% (CONSTITUTION §3bis; kiosk-fallback.css forces light on the
   app/idle). This auto-connect screen was the lone hardcoded-dark island —
   now aligned to the light palette (#FFFFFF canvas, #1A1A1A text, #F4501E accent). */
.kiosk-login-screen {
  min-height: 100vh;
  background: linear-gradient(160deg, #FFF4EE 0%, #FFFFFF 100%);
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 2rem;
}
.kiosk-login-card {
  background: #FFFFFF;
  border: 1px solid #F0E2DA;
  box-shadow: 0 10px 40px rgba(26,26,26,0.08);
  border-radius: 24px;
  padding: 3rem 2.5rem;
  width: 100%;
  max-width: 420px;
  display: flex;
  flex-direction: column;
  gap: 2rem;
}
.kiosk-login-logo {
  text-align: center;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.5rem;
}
.kiosk-login-icon { font-size: 3rem; }
.kiosk-login-title {
  margin: 0;
  font-size: 1.6rem;
  font-weight: 800;
  color: #1A1A1A;
}
.kiosk-login-sub {
  margin: 0;
  font-size: 0.9rem;
  color: #6B7280;
  text-transform: uppercase;
  letter-spacing: 0.08em;
}
.kiosk-login-form {
  display: flex;
  flex-direction: column;
  gap: 1.25rem;
}
.kiosk-login-auto {
  align-items: stretch;
}
.kiosk-login-status {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.85rem;
}
.kiosk-login-status-icon {
  width: 22px;
  height: 22px;
  border-radius: 999px;
  background: #F3F4F6;
  color: #6B7280;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-weight: 800;
}
.kiosk-login-status-text {
  margin: 0;
  color: #374151;
  text-align: center;
  font-size: 1rem;
  font-weight: 600;
}
.kiosk-login-field {
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
}
.kiosk-login-label {
  font-size: 0.85rem;
  font-weight: 600;
  color: #6B7280;
  text-transform: uppercase;
  letter-spacing: 0.05em;
}
.kiosk-login-input {
  background: #F9FAFB;
  border: 1px solid #E5E7EB;
  border-radius: 12px;
  color: #1A1A1A;
  font-size: 1rem;
  padding: 0.85rem 1rem;
  outline: none;
  transition: border-color 0.2s;
}
.kiosk-login-input:focus { border-color: #f4501e; }
.kiosk-login-input::placeholder { color: #9CA3AF; }
.kiosk-login-input:disabled { opacity: 0.5; }
.kiosk-login-hint {
  margin: 0;
  font-size: 0.78rem;
  line-height: 1.35;
  color: #6B7280;
}
.kiosk-login-hint-center {
  text-align: center;
}
.kiosk-login-hint strong { color: #374151; font-weight: 600; }
.kiosk-login-devhint {
  margin: 0;
  font-size: 0.74rem;
  line-height: 1.4;
  color: #6B7280;
  text-align: center;
}
.kiosk-login-devhint code {
  font-size: 0.85em;
  padding: 0.1em 0.35em;
  border-radius: 4px;
  background: #F3F4F6;
  color: #374151;
}
.kiosk-login-error {
  margin: 0;
  background: rgba(244,80,30,0.12);
  border: 1px solid rgba(244,80,30,0.3);
  border-radius: 10px;
  padding: 0.7rem 1rem;
  color: #B91C1C;
  font-size: 0.9rem;
  text-align: center;
}
.kiosk-login-btn {
  background: #f4501e;
  color: #fff;
  border: none;
  border-radius: 50px;
  padding: 1rem;
  font-size: 1.05rem;
  font-weight: 700;
  cursor: pointer;
  transition: background 0.2s, opacity 0.2s;
  display: flex;
  align-items: center;
  justify-content: center;
  min-height: 52px;
}
.kiosk-login-btn:hover:not(:disabled) { background: #D63E15; } /* [audit-360] off-brand red #c0001a -> brand-orange-dark */
.kiosk-login-btn:disabled { opacity: 0.45; cursor: default; }
.kiosk-login-secondary-btn {
  background: transparent;
  color: #374151;
  border: 1px solid #D1D5DB;
  border-radius: 50px;
  padding: 0.95rem 1rem;
  font-size: 0.96rem;
  font-weight: 700;
  cursor: pointer;
}
.kiosk-login-spinner {
  width: 22px; height: 22px;
  border: 3px solid rgba(255,255,255,0.3);
  border-top-color: #fff;
  border-radius: 50%;
  animation: kspin 0.7s linear infinite;
  display: inline-block;
}
@keyframes kspin { to { transform: rotate(360deg); } }
.kiosk-login-footer {
  text-align: center;
  font-size: 0.78rem;
  color: #6B7280; /* [audit-360] was rgba(255,255,255,0.22) — invisible on the new light canvas */
  margin: 0;
}
.fade-enter-active, .fade-leave-active { transition: opacity 0.25s; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
</style>
