/**
 * posOfflineQueueDb — IndexedDB wrapper for POS offline queue.
 * Mirrors kioskOfflineQueueDb pattern with a distinct DB name so kiosk + POS
 * queues never collide on a shared device. Falls back to localStorage if
 * IndexedDB is unavailable (private browsing, Safari ITP). Pure helper, no
 * Vue/Vuex dependency — frozen-zone-safe.
 */
import { clear, createStore, del, get, set } from 'idb-keyval';

const DB_NAME = 'foodking-pos-offline-queue';
const STORE_NAME = 'queue';
const OP_TIMEOUT_MS = 5000;
const LS_PREFIX = '__pos_idb_fallback__:';

const idbStore = typeof createStore === 'function' ? createStore(DB_NAME, STORE_NAME) : null;

function canUseIndexedDb() {
    return !!idbStore && typeof indexedDB !== 'undefined';
}

function hasLocalStorage() {
    try { return typeof window !== 'undefined' && !!window.localStorage; } catch (_) { return false; }
}

function withTimeout(promise, label) {
    return Promise.race([
        promise,
        new Promise((_, reject) => {
            setTimeout(() => reject(new Error(`${label} timed out`)), OP_TIMEOUT_MS);
        }),
    ]);
}

function shouldFallback(error) {
    const msg = String(error?.message || '');
    return !canUseIndexedDb() || /indexeddb|not implemented|timed out/i.test(msg);
}

async function fbGet(key) {
    if (!hasLocalStorage()) return null;
    try {
        const raw = window.localStorage.getItem(`${LS_PREFIX}${key}`);
        return raw ? JSON.parse(raw) : null;
    } catch (_) { return null; }
}

async function fbSet(key, value) {
    if (!hasLocalStorage()) return value;
    window.localStorage.setItem(`${LS_PREFIX}${key}`, JSON.stringify(value));
    return value;
}

async function fbDel(key) {
    if (!hasLocalStorage()) return;
    window.localStorage.removeItem(`${LS_PREFIX}${key}`);
}

async function fbClear() {
    if (!hasLocalStorage()) return;
    Object.keys(window.localStorage)
        .filter((k) => k.startsWith(LS_PREFIX))
        .forEach((k) => window.localStorage.removeItem(k));
}

async function run(op, fallback) {
    if (!canUseIndexedDb()) return fallback();
    try { return await op(); } catch (error) {
        if (!shouldFallback(error)) throw error;
        return fallback();
    }
}

export async function getQueueEntry(key) {
    return run(() => withTimeout(get(key, idbStore), `pos-idb:get:${key}`), () => fbGet(key));
}

export async function setQueueEntry(key, value) {
    return run(async () => {
        await withTimeout(set(key, value, idbStore), `pos-idb:set:${key}`);
        return value;
    }, () => fbSet(key, value));
}

export async function delQueueEntry(key) {
    return run(() => withTimeout(del(key, idbStore), `pos-idb:del:${key}`), () => fbDel(key));
}

export async function clearQueueEntries() {
    return run(() => withTimeout(clear(idbStore), 'pos-idb:clear'), () => fbClear());
}

export function isIndexedDbReady() {
    return canUseIndexedDb();
}
