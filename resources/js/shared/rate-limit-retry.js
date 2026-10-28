/**
 * [ANTI-429 2026-10-28] Rejoue EN SILENCE une requête refusée par un plafond (HTTP 429).
 *
 * POURQUOI : propriétaire — « chaque fois que je clique, que je quitte, que je vais au panier, ça met des
 * erreurs : trop de requêtes ». Le plafond est côté serveur (relevé le même jour, planchers dans
 * config/*.php) ; ce module est la SECONDE ceinture : si un pic dépasse quand même un plafond, l'écran
 * attend la fin de la fenêtre et rejoue l'appel, plutôt que d'afficher une erreur à un caissier ou à un
 * client qui n'a rien fait de mal.
 *
 * POURQUOI C'EST SÛR DE REJOUER, MÊME UN POST : le middleware `throttle` répond 429 AVANT que le contrôleur
 * ne s'exécute. Rien n'a été écrit, rien n'a été facturé, aucun numéro fiscal consommé. Les mutations
 * partent de toute façon avec leur `X-Idempotency-Key` (conservée à l'identique au rejeu).
 *
 * CE QUE CE MODULE NE FAIT PAS :
 *   · il ne touche PAS aux routes d'authentification (connexion, code SMS, mot de passe, PIN) : ces
 *     plafonds sont des protections anti-piratage, les marteler les ferait durer ;
 *   · il ne masque RIEN au-delà de `MAX_RETRIES` : si le plafond tient encore, l'erreur remonte telle
 *     quelle et le message « Trop de requêtes » de bootstrap.js s'affiche — on ne cache jamais un blocage
 *     réel (cf. inflight-dedupe.js : « on retire les requêtes, jamais l'alerte »).
 *
 * Échappatoire : `__noRateLimitRetry: true` dans la configuration axios.
 */

export const MAX_RETRIES = 4;
// Attente bornée par essai : assez pour laisser passer une fenêtre courte, jamais assez pour figer l'écran.
export const MAX_WAIT_MS = 8000;
const MIN_WAIT_MS = 800;

// Plafonds de sécurité (anti force brute) : jamais rejoués automatiquement.
const AUTH_URL = /(login|logout|otp|password|forgot|reset|\bpin\b|unlock|verify|signup|social)/i;
// Télémétrie « tire et oublie » : perdre un événement est acceptable, inutile de la rejouer.
const TELEMETRY_URL = /(frontend\/kiosk[/-]event|admin\/client-metrics)/i;

export function retryDelayMs(error, attempt, rand = Math.random) {
    const headers = error?.response?.headers || {};
    const raw = headers['retry-after'] ?? headers['Retry-After'] ?? error?.response?.data?.retry_after;
    const seconds = parseInt(raw, 10);
    const wanted = Number.isFinite(seconds) && seconds > 0 ? seconds * 1000 : MIN_WAIT_MS * (attempt + 1);
    // Gigue : sans elle, tous les appels bloqués ensemble repartent ensemble et re-saturent le plafond.
    return Math.min(MAX_WAIT_MS, Math.max(MIN_WAIT_MS, wanted)) + Math.floor(rand() * 400);
}

export function shouldRetry(error) {
    if (Number(error?.response?.status) !== 429) return false;
    const cfg = error?.config;
    if (!cfg || cfg.__noRateLimitRetry) return false;
    if ((cfg.__rlRetries || 0) >= MAX_RETRIES) return false;
    const url = String(cfg.url || '');
    if (AUTH_URL.test(url) || TELEMETRY_URL.test(url)) return false;
    return true;
}

export function installRateLimitRetry(axiosInstance, { sleep = (ms) => new Promise((r) => setTimeout(r, ms)) } = {}) {
    const id = axiosInstance.interceptors.response.use(
        (response) => response,
        async (error) => {
            if (!shouldRetry(error)) return Promise.reject(error);
            const cfg = error.config;
            const attempt = cfg.__rlRetries || 0;
            cfg.__rlRetries = attempt + 1;
            await sleep(retryDelayMs(error, attempt));
            return axiosInstance.request(cfg);
        }
    );
    return id;
}
