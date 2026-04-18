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

        <button
          v-if="maintenanceMode"
          type="button"
          class="kiosk-login-secondary-btn"
          :disabled="loading"
          @click="disableMaintenanceMode"
        >
          {{ $t('kiosk.login_screen.exit_maintenance') }}
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
    maintenanceMode() {
      try {
        return sessionStorage.getItem('kiosk_maintenance_mode') === '1';
      } catch (_) {
        return false;
      }
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
      try {
        if (sessionStorage.getItem('kiosk_maintenance_mode') === '1') {
          return null;
        }
      } catch (_) { /* ignore if sessionStorage unavailable */ }
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
        this.error = this.maintenanceMode
          ? this.$t('kiosk.login_screen.err_maintenance')
          : this.$t('kiosk.login_screen.err_no_credentials');
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
        const msg = err?.response?.data?.errors?.validation
          || err?.response?.data?.errors?.username?.[0]
          || err?.response?.data?.errors?.password?.[0]
          || err?.message
          || this.$t('kiosk.login_screen.err_login_failed');
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

    disableMaintenanceMode() {
      try {
        sessionStorage.removeItem('kiosk_maintenance_mode');
      } catch (_) { /* ignore */ }
      this.retryAttempts = 0;
      this.startAutoLogin();
    },
  },
};
</script>

<style scoped>
.kiosk-login-screen {
  min-height: 100vh;
  background: linear-gradient(160deg, #0f0f1a 0%, #1a1a2e 100%);
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 2rem;
}
.kiosk-login-card {
  background: rgba(255,255,255,0.04);
  border: 1px solid rgba(255,255,255,0.1);
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
  color: #fff;
}
.kiosk-login-sub {
  margin: 0;
  font-size: 0.9rem;
  color: rgba(255,255,255,0.4);
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
  background: rgba(255,255,255,0.14);
  color: rgba(255,255,255,0.72);
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-weight: 800;
}
.kiosk-login-status-text {
  margin: 0;
  color: rgba(255,255,255,0.78);
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
  color: rgba(255,255,255,0.55);
  text-transform: uppercase;
  letter-spacing: 0.05em;
}
.kiosk-login-input {
  background: rgba(255,255,255,0.07);
  border: 1px solid rgba(255,255,255,0.14);
  border-radius: 12px;
  color: #fff;
  font-size: 1rem;
  padding: 0.85rem 1rem;
  outline: none;
  transition: border-color 0.2s;
}
.kiosk-login-input:focus { border-color: #e8001c; }
.kiosk-login-input::placeholder { color: rgba(255,255,255,0.25); }
.kiosk-login-input:disabled { opacity: 0.5; }
.kiosk-login-hint {
  margin: 0;
  font-size: 0.78rem;
  line-height: 1.35;
  color: rgba(255,255,255,0.38);
}
.kiosk-login-hint-center {
  text-align: center;
}
.kiosk-login-hint strong { color: rgba(255,255,255,0.55); font-weight: 600; }
.kiosk-login-devhint {
  margin: 0;
  font-size: 0.74rem;
  line-height: 1.4;
  color: rgba(255,255,255,0.32);
  text-align: center;
}
.kiosk-login-devhint code {
  font-size: 0.85em;
  padding: 0.1em 0.35em;
  border-radius: 4px;
  background: rgba(255,255,255,0.08);
  color: rgba(255,255,255,0.65);
}
.kiosk-login-error {
  margin: 0;
  background: rgba(232,0,28,0.12);
  border: 1px solid rgba(232,0,28,0.3);
  border-radius: 10px;
  padding: 0.7rem 1rem;
  color: #ff6b7a;
  font-size: 0.9rem;
  text-align: center;
}
.kiosk-login-btn {
  background: #e8001c;
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
.kiosk-login-btn:hover:not(:disabled) { background: #c0001a; }
.kiosk-login-btn:disabled { opacity: 0.45; cursor: default; }
.kiosk-login-secondary-btn {
  background: transparent;
  color: rgba(255,255,255,0.82);
  border: 1px solid rgba(255,255,255,0.16);
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
  color: rgba(255,255,255,0.22);
  margin: 0;
}
.fade-enter-active, .fade-leave-active { transition: opacity 0.25s; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
</style>
