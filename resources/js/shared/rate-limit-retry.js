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
// Attente bornée par essai : assez pour franchir une fenêtre de plafond (≤ 60 s en deux essais), jamais plus.
// [test-e2e 2026-10-29] 8 s → 30 s : la mesure sur le vrai serveur a produit un 429 annonçant `Retry-After: 47` (fenêtre d'une
// minute entamée tôt). Avec 4 essais de 8 s, l'écran abandonnait à 32 s et affichait « Trop de requêtes » AVANT la fin de la
// fenêtre. À 30 s par essai, deux essais suffisent à franchir une fenêtre pleine (la valeur annoncée diminue à chaque essai).
export const MAX_WAIT_MS = 30000;
const MIN_WAIT_MS = 800;

// Plafonds de sécurité (anti force brute) : jamais rejoués automatiquement.
const AUTH_URL = /(login|logout|otp|password|forgot|reset|\bpin\b|unlock|verify|signup|social)/i;
// Télémétrie « tire et oublie » : perdre un événement est acceptable, inutile de la rejouer.
const TELEMETRY_URL = /(frontend\/kiosk[/-]event|admin\/client-metrics)/i;

// Attente annoncée par le serveur (secondes) : en-tête `Retry-After`, sinon corps `retry_after`. NaN si absente.
function announcedSeconds(error) {
    const headers = error?.response?.headers || {};
    const raw = headers['retry-after'] ?? headers['Retry-After'] ?? error?.response?.data?.retry_after;
    return parseInt(raw, 10);
}

export function retryDelayMs(error, attempt, rand = Math.random) {
    const seconds = announcedSeconds(error);
    const wanted = Number.isFinite(seconds) && seconds > 0 ? seconds * 1000 : MIN_WAIT_MS * (attempt + 1);
    // Gigue : sans elle, tous les appels bloqués ensemble repartent ensemble et re-saturent le plafond.
    return Math.min(MAX_WAIT_MS, Math.max(MIN_WAIT_MS, wanted)) + Math.floor(rand() * 400);
}

export function shouldRetry(error) {
    if (Number(error?.response?.status) !== 429) return false;
    const cfg = error?.config;
    if (!cfg || cfg.__noRateLimitRetry) return false;
    if ((cfg.__rlRetries || 0) >= MAX_RETRIES) return false;
    // Chemin seul : un `?search=pin` ou `?q=reset` ne doit ni exclure ni inclure une route à tort.
    const url = String(cfg.url || '').split('?')[0];
    if (AUTH_URL.test(url) || TELEMETRY_URL.test(url)) return false;
    // [test-e2e 2026-10-29 · ADV-07] Une attente annoncée au-delà de deux essais pleins (60 s) n'est pas une fenêtre de
    // plafond mais un verrou : on ne fige pas l'écran 2 minutes, l'erreur honnête remonte tout de suite.
    const announced = announcedSeconds(error);
    if (Number.isFinite(announced) && announced * 1000 > MAX_WAIT_MS * 2) return false;
    return true;
}

export function installRateLimitRetry(axiosInstance, { sleep = (ms) => new Promise((r) => setTimeout(r, ms)) } = {}) {
    const id = axiosInstance.interceptors.response.use(
        (response) => response,
        async (error) => {
            if (!shouldRetry(error)) return Promise.reject(error);
            const cfg = error.config;
            const attempt = cfg.__rlRetries || 0;
            await sleep(retryDelayMs(error, attempt));
            // [test-e2e 2026-10-29 · ADV-01] Le compteur vit sur une COPIE de la configuration, jamais sur l'objet reçu :
            // le dédoublonnage des GET (inflight-dedupe.js) partage UNE erreur — donc UNE configuration — entre N appelants
            // identiques ; incrémenter l'original faisait épuiser le budget de rejeux par le 5ᵉ appelant (toast à tort
            // alors que le serveur n'avait refusé qu'UN appel).
            return axiosInstance.request({ ...cfg, __rlRetries: attempt + 1 });
        }
    );
    return id;
}
