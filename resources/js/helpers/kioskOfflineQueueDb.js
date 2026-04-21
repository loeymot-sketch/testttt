import { clear, createStore, del, get, keys, set } from 'idb-keyval';

const DB_NAME = 'foodking-kiosk-offline-queue';
const STORE_NAME = 'queue';
const OP_TIMEOUT_MS = 5000;
let hasWarnedFallback = false;

const idbStore = typeof createStore === 'function'
    ? createStore(DB_NAME, STORE_NAME)
    : null;

function isLocalStorageAvailable() {
    try {
        return typeof window !== 'undefined' && !!window.localStorage;
    } catch (_) {
        return false;
    }
}

function withTimeout(promise, label) {
    return Promise.race([
        promise,
        new Promise((_, reject) => {
            setTimeout(() => reject(new Error(`${label} timed out after ${OP_TIMEOUT_MS}ms`)), OP_TIMEOUT_MS);
        }),
    ]);
}

function normalizeLocalStorageKey(key) {
    return `__kiosk_idb_fallback__:${key}`;
}

function canUseIndexedDb() {
    return !!idbStore && typeof indexedDB !== 'undefined';
}

function shouldFallback(error) {
    return !canUseIndexedDb()
        || /indexeddb/i.test(String(error?.message || ''))
        || /not implemented/i.test(String(error?.message || ''))
        || /timed out/i.test(String(error?.message || ''));
}

async function fallbackGet(key) {
    if (!isLocalStorageAvailable()) return null;
    try {
        const raw = window.localStorage.getItem(normalizeLocalStorageKey(key));
        return raw ? JSON.parse(raw) : null;
    } catch (_) {
        return null;
    }
}

async function fallbackSet(key, value) {
    if (!isLocalStorageAvailable()) return value;
    window.localStorage.setItem(normalizeLocalStorageKey(key), JSON.stringify(value));
    return value;
}

async function fallbackDel(key) {
    if (!isLocalStorageAvailable()) return;
    window.localStorage.removeItem(normalizeLocalStorageKey(key));
}

async function fallbackClear() {
    if (!isLocalStorageAvailable()) return;
    Object.keys(window.localStorage)
        .filter((key) => key.startsWith('__kiosk_idb_fallback__:'))
        .forEach((key) => window.localStorage.removeItem(key));
}

async function fallbackKeys() {
    if (!isLocalStorageAvailable()) return [];
    return Object.keys(window.localStorage)
        .filter((key) => key.startsWith('__kiosk_idb_fallback__:'))
        .map((key) => key.replace('__kiosk_idb_fallback__:', ''));
}

async function run(operation, fallback) {
    if (!canUseIndexedDb()) {
        if (process.env.NODE_ENV !== 'production' && !hasWarnedFallback) {
            hasWarnedFallback = true;
            console.warn('[kioskOfflineQueueDb] IndexedDB unavailable, falling back to localStorage.');
        }
        return fallback();
    }
    try {
        return await operation();
    } catch (error) {
        if (!shouldFallback(error)) {
            throw error;
        }
        if (process.env.NODE_ENV !== 'production' && !hasWarnedFallback) {
            hasWarnedFallback = true;
            console.warn('[kioskOfflineQueueDb] IndexedDB unavailable, falling back to localStorage.', error);
        }
        return fallback();
    }
}

export async function getQueueEntry(key) {
    return run(
        () => withTimeout(get(key, idbStore), `idb:get:${key}`),
        () => fallbackGet(key),
    );
}

export async function setQueueEntry(key, value) {
    return run(
        async () => {
            await withTimeout(set(key, value, idbStore), `idb:set:${key}`);
            return value;
        },
        () => fallbackSet(key, value),
    );
}

export async function delQueueEntry(key) {
    return run(
        () => withTimeout(del(key, idbStore), `idb:del:${key}`),
        () => fallbackDel(key),
    );
}

export async function clearQueueEntries() {
    return run(
        () => withTimeout(clear(idbStore), 'idb:clear'),
        () => fallbackClear(),
    );
}

export async function listQueueKeys() {
    return run(
        () => withTimeout(keys(idbStore), 'idb:keys'),
        () => fallbackKeys(),
    );
}

export function isIndexedDbReady() {
    return canUseIndexedDb();
}
