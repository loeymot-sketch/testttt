<template>
  <div class="kiosk-login-screen">
    <div class="kiosk-login-card">
      <!-- Logo / Branding -->
      <div class="kiosk-login-logo">
        <div class="kiosk-login-icon">🖥️</div>
        <h1 class="kiosk-login-title">Borne de commande</h1>
        <p class="kiosk-login-sub">Authentification machine</p>
      </div>

      <!-- Form -->
      <form class="kiosk-login-form" @submit.prevent="login">
        <div class="kiosk-login-field">
          <label class="kiosk-login-label">Identifiant machine</label>
          <input
            v-model="username"
            type="text"
            class="kiosk-login-input"
            placeholder="Nom de la borne"
            autocomplete="off"
            :disabled="loading"
          />
        </div>
        <div class="kiosk-login-field">
          <label class="kiosk-login-label">Mot de passe</label>
          <input
            v-model="password"
            type="password"
            class="kiosk-login-input"
            placeholder="••••••••"
            autocomplete="off"
            :disabled="loading"
          />
        </div>

        <transition name="fade">
          <p v-if="error" class="kiosk-login-error">{{ error }}</p>
        </transition>

        <button
          type="submit"
          class="kiosk-login-btn"
          :disabled="!username || !password || loading"
        >
          <span v-if="!loading">Se connecter</span>
          <span v-else class="kiosk-login-spinner"></span>
        </button>
      </form>

      <p class="kiosk-login-footer">
        Configurez cette borne dans l'espace admin → Paramètres → Bornes
      </p>
    </div>
  </div>
</template>

<script>
import { mapActions } from 'vuex';

export default {
  name: 'KioskLoginComponent',
  data() {
    return {
      username: '',
      password: '',
      loading: false,
      error: null,
    };
  },
  methods: {
    ...mapActions('kioskCart', ['kioskLogin']),

    async login() {
      if (!this.username || !this.password) return;
      this.loading = true;
      this.error = null;
      try {
        await this.kioskLogin({ username: this.username, password: this.password });
        // Redirect to idle screen after successful login
        this.$router.replace({ name: 'kiosk.idle' });
      } catch (err) {
        const msg = err?.response?.data?.errors?.validation
          || err?.response?.data?.errors?.username?.[0]
          || err?.response?.data?.errors?.password?.[0]
          || err?.message
          || 'Identifiants incorrects.';
        this.error = msg;
      } finally {
        this.loading = false;
      }
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
