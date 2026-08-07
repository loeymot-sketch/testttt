/**
 * [MULTI-DEVICE 2026-08-07] Identité d'appareil persistante.
 *
 * Le backend révoque à la connexion le jeton précédent DU MÊME APPAREIL
 * (voir app/Services/Auth/DeviceTokenService.php). Il lui faut donc un
 * identifiant stable par terminal, que le client fournit via l'en-tête
 * `X-Device-Id` sur chaque requête.
 *
 * Contraintes de conception :
 *
 *  - STABLE dans le temps : l'identifiant vit dans `localStorage`, pas dans
 *    `sessionStorage` — sinon chaque nouvel onglet compterait comme un
 *    appareil neuf et consommerait le plafond de terminaux.
 *  - ALÉATOIRE, jamais un fingerprint : deux tablettes identiques du même
 *    modèle sur le même réseau doivent être distinguées, ce qu'un hachage
 *    user-agent + IP ne fait pas. C'est aussi la raison pour laquelle on ne
 *    collecte rien d'identifiant sur l'utilisateur.
 *  - DÉGRADATION SILENCIEUSE : en navigation privée ou stockage bloqué,
 *    l'écriture échoue. On retombe alors sur un identifiant de session en
 *    mémoire : l'appareil reste distinct des autres pendant sa session, et
 *    le plafond côté serveur nettoie les entrées orphelines. Jamais d'erreur
 *    remontée à l'écran pour ça — la connexion prime.
 */

const STORAGE_KEY = 'foodking.device_id';
const LABEL_KEY = 'foodking.device_label';

let memoryFallbackId = null;

function randomId() {
    try {
        if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
            return crypto.randomUUID().replace(/-/g, '');
        }
        if (typeof crypto !== 'undefined' && typeof crypto.getRandomValues === 'function') {
            const bytes = new Uint8Array(16);
            crypto.getRandomValues(bytes);
            return Array.from(bytes, b => b.toString(16).padStart(2, '0')).join('');
        }
    } catch (_) { /* environnements sans crypto — repli ci-dessous */ }

    // Repli non cryptographique : cet identifiant n'est PAS un secret, il ne
    // donne aucun droit. Il ne sert qu'à distinguer deux terminaux.
    return `f${Math.random().toString(36).slice(2)}${Date.now().toString(36)}`;
}

/**
 * Identifiant stable de cet appareil. Format aligné sur la liste blanche
 * serveur : /^[A-Za-z0-9_.:-]{8,64}$/.
 */
export function getDeviceId() {
    try {
        const stored = window.localStorage.getItem(STORAGE_KEY);
        if (stored && /^[A-Za-z0-9_.:-]{8,64}$/.test(stored)) {
            return stored;
        }

        const fresh = randomId();
        window.localStorage.setItem(STORAGE_KEY, fresh);
        return fresh;
    } catch (_) {
        if (!memoryFallbackId) {
            memoryFallbackId = randomId();
        }
        return memoryFallbackId;
    }
}

/**
 * Libellé lisible affiché dans « Appareils connectés ». L'exploitant peut le
 * renommer depuis cet écran ; sinon on propose un défaut décrivant la surface
 * (Caisse / Borne / Administration) plutôt qu'un user-agent illisible.
 */
export function getDeviceLabel() {
    try {
        const custom = window.localStorage.getItem(LABEL_KEY);
        if (custom && custom.trim() !== '') {
            return custom.trim().slice(0, 120);
        }
    } catch (_) { /* stockage indisponible — on retombe sur le défaut */ }

    const path = (typeof window !== 'undefined' && window.location && window.location.pathname) || '';
    if (path.startsWith('/kiosk')) return 'Borne';
    if (path.startsWith('/admin/pos') || path.startsWith('/pos')) return 'Caisse';
    if (path.startsWith('/kds')) return 'Écran cuisine';
    return 'Administration';
}

/**
 * Renomme l'appareil courant (utilisé par l'écran « Appareils connectés »).
 * Le nom n'est appliqué au jeton qu'à la prochaine émission côté serveur ;
 * l'écran met donc aussi le libellé à jour via l'API pour un effet immédiat.
 */
export function setDeviceLabel(label) {
    try {
        window.localStorage.setItem(LABEL_KEY, String(label || '').trim().slice(0, 120));
    } catch (_) { /* non bloquant */ }
}
