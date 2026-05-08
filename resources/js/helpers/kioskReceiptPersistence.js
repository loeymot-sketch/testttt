/**
 * kioskReceiptPersistence.js — Snapshot du dernier reçu (F5-proof).
 * -----------------------------------------------------------------------------
 * FoodKing Kiosk — Phase 9.1.12.
 *
 * Problème couvert :
 *  Le `KioskConfirmationComponent` construit son ticket à partir d'un snapshot
 *  in-memory du panier pris dans `mounted()`. Si le client recharge la page
 *  (F5, redémarrage borne auto, navigation back inattendue), ce snapshot
 *  disparaît et il n'a plus son ticket → frustration + perte commerciale
 *  (client ne sait plus son n° de commande, staff doit retrouver la commande
 *  en base pour reimprimer).
 *
 * Solution :
 *  - On persiste, juste après reset du panier sur /confirmation, un snapshot
 *    minimal dans `localStorage` sous la clé `kiosk.lastReceipt`.
 *  - La navigation vers /idle (ou un mount direct sur /confirmation sans
 *    données) lit ce snapshot et l'expose au composant pour reconstruire le
 *    ticket à l'identique.
 *  - TTL court (1h par défaut) : au-delà, le snapshot est considéré obsolète
 *    — la commande est probablement déjà servie, et le client n'a plus besoin
 *    du ticket à la borne.
 *  - Aucune PII n'est persistée (pas d'email, pas de téléphone) — seulement
 *    le nom public du client fidélité (déjà affiché sur le ticket papier).
 *
 * Invariants :
 *  - Aucun prix n'est recalculé à partir de ce snapshot côté SSOT — c'est un
 *    pur cache d'affichage. Toute action métier (reprint, refund, annulation)
 *    doit interroger `GET /api/frontend/order/{id}` pour obtenir la source
 *    de vérité.
 *  - Le snapshot est vidé quand l'utilisateur revient à l'écran idle (fin de
 *    session visible) OU quand l'expiration est dépassée.
 *  - Tolérant aux contextes sans `localStorage` (SSR, incognito Safari, tests
 *    jsdom sans stub) → renvoie toujours `null` plutôt que throw.
 */

const STORAGE_KEY = 'kiosk.lastReceipt';
const DEFAULT_TTL_MS = 60 * 60 * 1000; // 1h

function safeLocalStorage() {
    try {
        if (typeof window === 'undefined') return null;
        if (!window.localStorage) return null;
        return window.localStorage;
    } catch (_) {
        return null;
    }
}

/**
 * Persiste un snapshot minimal de reçu dans localStorage.
 *
 * @param {object} receipt
 * @param {string|number} receipt.orderId
 * @param {string|number} receipt.queueNumber  - Numéro affiché au client.
 * @param {number} receipt.total               - Montant TTC payé.
 * @param {number} [receipt.discount]          - Réduction fidélité éventuelle.
 * @param {number} [receipt.subtotal]          - Sous-total HT/TTC (selon SSOT).
 * @param {Array}  [receipt.items]             - Lignes du ticket (nom + qty + total).
 * @param {string} [receipt.paymentMethod]
 * @param {string} [receipt.paymentMethodKey] - clé brute (cash|card|tr) pour reconstruire l'icône au reload F5.
 * @param {string} [receipt.loyaltyCustomerName]
 * @param {number} [receipt.loyaltyBalance]   - solde points pré-existant (avant cette commande).
 * @param {number} [receipt.pointsEarned]
 * @param {string} [receipt.restaurantName]
 * @param {string} [receipt.paidAt]            - ISO 8601, fallback now().
 * @returns {boolean} true si persisté, false sinon.
 */
export function saveKioskReceiptSnapshot(receipt) {
    const storage = safeLocalStorage();
    if (!storage || !receipt || typeof receipt !== 'object') return false;

    // On fige un payload minimal : pas de PII (email, phone), pas de fonctions.
    const payload = {
        v: 1,
        savedAt: Date.now(),
        paidAt: receipt.paidAt || new Date().toISOString(),
        orderId: receipt.orderId ?? null,
        queueNumber: receipt.queueNumber ?? null,
        total: Number.isFinite(receipt.total) ? receipt.total : 0,
        discount: Number.isFinite(receipt.discount) ? receipt.discount : 0,
        subtotal: Number.isFinite(receipt.subtotal) ? receipt.subtotal : null,
        items: Array.isArray(receipt.items)
            ? receipt.items.map((it) => ({
                item_id: it.item_id ?? it.id ?? null,
                name: typeof it.name === 'string' ? it.name : '',
                quantity: Number.isFinite(it.quantity) ? it.quantity : 1,
                total: Number.isFinite(it.total) ? it.total : 0,
            }))
            : [],
        paymentMethod: receipt.paymentMethod || '',
        // [QW-5] Clé brute (cash|card|tr) pour reconstruire l'icône au F5.
        paymentMethodKey: typeof receipt.paymentMethodKey === 'string' && receipt.paymentMethodKey
            ? receipt.paymentMethodKey
            : null,
        loyaltyCustomerName: receipt.loyaltyCustomerName || null,
        // [QW-6] Solde de points pré-existant pour totalLoyaltyPoints.
        loyaltyBalance: Number.isFinite(receipt.loyaltyBalance) ? receipt.loyaltyBalance : 0,
        pointsEarned: Number.isFinite(receipt.pointsEarned) ? receipt.pointsEarned : 0,
        restaurantName: receipt.restaurantName || null,
    };

    try {
        storage.setItem(STORAGE_KEY, JSON.stringify(payload));
        return true;
    } catch (_) {
        // QuotaExceeded, private mode, etc. → non fatal.
        return false;
    }
}

/**
 * Lit le dernier snapshot de reçu s'il est encore valide (TTL).
 *
 * @param {object} [options]
 * @param {number} [options.ttlMs] - Durée max depuis `savedAt` (défaut 1h).
 * @returns {object|null} snapshot ou null si absent/expiré/corrompu.
 */
export function readKioskReceiptSnapshot(options = {}) {
    const storage = safeLocalStorage();
    if (!storage) return null;

    const ttlMs = Number.isFinite(options.ttlMs) && options.ttlMs > 0
        ? options.ttlMs
        : DEFAULT_TTL_MS;

    let raw;
    try {
        raw = storage.getItem(STORAGE_KEY);
    } catch (_) {
        return null;
    }
    if (!raw) return null;

    let parsed;
    try {
        parsed = JSON.parse(raw);
    } catch (_) {
        // Corrompu → on vide pour éviter de re-corrompre à chaque read.
        clearKioskReceiptSnapshot();
        return null;
    }
    if (!parsed || typeof parsed !== 'object') return null;
    if (parsed.v !== 1) return null;

    const savedAt = Number(parsed.savedAt) || 0;
    if (!savedAt || Date.now() - savedAt > ttlMs) {
        // Expiré → on vide pour ne pas gaspiller du quota.
        clearKioskReceiptSnapshot();
        return null;
    }

    return parsed;
}

/**
 * Supprime le snapshot (à appeler quand on revient à idle, ou après TTL).
 */
export function clearKioskReceiptSnapshot() {
    const storage = safeLocalStorage();
    if (!storage) return;
    try {
        storage.removeItem(STORAGE_KEY);
    } catch (_) { /* noop */ }
}

export const KIOSK_RECEIPT_STORAGE_KEY = STORAGE_KEY;
export const KIOSK_RECEIPT_DEFAULT_TTL_MS = DEFAULT_TTL_MS;

export default {
    save: saveKioskReceiptSnapshot,
    read: readKioskReceiptSnapshot,
    clear: clearKioskReceiptSnapshot,
    STORAGE_KEY,
    DEFAULT_TTL_MS,
};
