<template>
  <div
    v-if="visible"
    class="connection-status-banner"
    :class="bannerClass"
    role="alert"
    :aria-live="isSessionInvalid ? 'assertive' : 'polite'"
  >
    <span class="connection-status-banner__text">{{ bannerText }}</span>

    <button
      v-if="isSessionInvalid"
      type="button"
      class="connection-status-banner__action"
      @click="reloadPage"
    >
      {{ reloadLabel }}
    </button>

    <button
      v-else
      type="button"
      class="connection-status-banner__close"
      aria-label="Fermer"
      @click="dismissed = true"
    >
      ×
    </button>
  </div>
</template>

<script>
import { wsService, WS_STATE } from "../../services/WebSocketService";

export default {
  name: "ConnectionStatusBanner",
  data() {
    return {
      wsState: WS_STATE.INITIALIZED,
      disconnectedSince: null,
      bannerTick: 0,
      dismissed: false,
      _onStateChange: null,
      _bannerInterval: null,
    };
  },
  computed: {
    isSessionInvalid() {
      return this.wsState === WS_STATE.SESSION_INVALID;
    },
    showTransientBanner() {
      if (this.bannerTick < 0) return false;
      if (!this.disconnectedSince) return false;
      return Date.now() - this.disconnectedSince > 5000;
    },
    isOffline() {
      if (this.bannerTick < 0) return false;
      if (!this.disconnectedSince) return false;
      return Date.now() - this.disconnectedSince > 30000;
    },
    visible() {
      // [F-12] session_invalid takes precedence and ignores the dismissal flag.
      if (this.isSessionInvalid) return true;
      return this.showTransientBanner && !this.dismissed;
    },
    bannerClass() {
      if (this.isSessionInvalid) return "connection-status-banner--session-invalid";
      return this.isOffline
        ? "connection-status-banner--offline"
        : "connection-status-banner--reconnecting";
    },
    bannerText() {
      if (this.isSessionInvalid) {
        return this.$te && this.$te("ws_session_invalid_text")
          ? this.$t("ws_session_invalid_text")
          : "Session expirée — votre session est terminée. Rechargez la page.";
      }
      return this.isOffline
        ? "Connexion perdue — hors ligne"
        : "Reconnexion en cours…";
    },
    reloadLabel() {
      return this.$te && this.$te("ws_session_invalid_action")
        ? this.$t("ws_session_invalid_action")
        : "Recharger";
    },
  },
  watch: {
    visible(val) {
      if (!val) this.dismissed = false;
    },
  },
  mounted() {
    this.wsState = wsService.getState();
    if (this.wsState !== WS_STATE.CONNECTED && this.wsState !== WS_STATE.SESSION_INVALID) {
      this.disconnectedSince = Date.now();
    }

    this._onStateChange = ({ previous, current }) => {
      this.wsState = current;
      if (current === WS_STATE.CONNECTED) {
        this.disconnectedSince = null;
        this.dismissed = false;
      } else if (current === WS_STATE.SESSION_INVALID) {
        // session_invalid is terminal until reload — keep disconnectedSince as-is
        this.dismissed = false;
      } else if (previous === WS_STATE.CONNECTED) {
        this.disconnectedSince = Date.now();
      }
    };
    wsService.on("state_change", this._onStateChange);

    this._bannerInterval = setInterval(() => {
      this.bannerTick += 1;
    }, 1000);
  },
  beforeUnmount() {
    if (this._onStateChange) {
      wsService.off("state_change", this._onStateChange);
    }
    if (this._bannerInterval) {
      clearInterval(this._bannerInterval);
    }
  },
  methods: {
    reloadPage() {
      if (typeof window !== "undefined" && window.location && typeof window.location.reload === "function") {
        window.location.reload();
      }
    },
  },
};
</script>

<style scoped>
.connection-status-banner {
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  z-index: 9999;
  width: 100%;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 12px;
  padding: 8px 36px;
  box-sizing: border-box;
  color: #fff;
  font-size: 13px;
  font-weight: 600;
  text-align: center;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
}

.connection-status-banner--reconnecting {
  background: #ca8a04;
}

.connection-status-banner--offline {
  background: #b91c1c;
}

.connection-status-banner--session-invalid {
  background: #7f1d1d;
}

.connection-status-banner__text {
  flex: 1;
}

.connection-status-banner__close {
  position: absolute;
  right: 8px;
  top: 50%;
  transform: translateY(-50%);
  background: transparent;
  border: none;
  color: #fff;
  font-size: 22px;
  line-height: 1;
  cursor: pointer;
  padding: 4px 8px;
  opacity: 0.85;
}

.connection-status-banner__close:hover {
  opacity: 1;
}

.connection-status-banner__action {
  background: #fff;
  color: #7f1d1d;
  border: none;
  border-radius: 4px;
  padding: 6px 14px;
  font-weight: 700;
  font-size: 13px;
  cursor: pointer;
  margin-left: auto;
}

.connection-status-banner__action:hover {
  background: #fef2f2;
}
</style>
