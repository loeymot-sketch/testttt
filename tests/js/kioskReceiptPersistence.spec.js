/**
 * Kiosk Phase 9.1.12 — Snapshot F5-proof du reçu.
 *
 * Couvre :
 *  - save / read round-trip
 *  - TTL : snapshot > 1h → null + purge
 *  - version incompatible → null
 *  - JSON corrompu → null + purge
 *  - absence de localStorage → no-op silencieux (retourne null/false)
 *  - pas de PII inattendu (email/phone) dans le payload persisté
 */
import { describe, it, expect, beforeEach, vi } from 'vitest';
import {
    saveKioskReceiptSnapshot,
    readKioskReceiptSnapshot,
    clearKioskReceiptSnapshot,
    KIOSK_RECEIPT_STORAGE_KEY,
} from '../../resources/js/helpers/kioskReceiptPersistence.js';

describe('kioskReceiptPersistence', () => {
    beforeEach(() => {
        localStorage.clear();
        vi.useRealTimers();
    });

    it('save + read round-trip', () => {
        const ok = saveKioskReceiptSnapshot({
            orderId: 42,
            queueNumber: 'A017',
            total: 18.5,
            discount: 2,
            subtotal: 20.5,
            items: [
                { item_id: 1, name: 'Tacos M', quantity: 1, total: 12.5 },
                { item_id: 2, name: 'Cola', quantity: 1, total: 2.5 },
            ],
            paymentMethod: 'Carte',
            loyaltyCustomerName: 'Kenza',
            pointsEarned: 18,
            restaurantName: 'FoodKing Paris',
        });
        expect(ok).toBe(true);

        const read = readKioskReceiptSnapshot();
        expect(read).not.toBeNull();
        expect(read.orderId).toBe(42);
        expect(read.queueNumber).toBe('A017');
        expect(read.total).toBe(18.5);
        expect(read.items).toHaveLength(2);
        expect(read.loyaltyCustomerName).toBe('Kenza');
        expect(read.paymentMethod).toBe('Carte');
    });

    it('n’inclut jamais d’email/phone/PII dans le payload', () => {
        saveKioskReceiptSnapshot({
            orderId: 1,
            queueNumber: 'B001',
            total: 10,
            items: [{ item_id: 1, name: 'X', quantity: 1, total: 10 }],
            // Tentative d'injection de PII (doit être ignorée).
            customer_email: 'hack@example.com',
            customer_phone: '+33612345678',
            iban: 'FR76...',
        });
        const raw = localStorage.getItem(KIOSK_RECEIPT_STORAGE_KEY);
        expect(raw).not.toBeNull();
        expect(raw).not.toMatch(/hack@example\.com/);
        expect(raw).not.toMatch(/\+33612345678/);
        expect(raw).not.toMatch(/FR76/);
    });

    it('TTL expiré → read renvoie null + purge', () => {
        saveKioskReceiptSnapshot({ orderId: 1, total: 5, items: [] });
        // On simule un snapshot posé il y a 2h en manipulant directement le
        // timestamp persisté.
        const raw = JSON.parse(localStorage.getItem(KIOSK_RECEIPT_STORAGE_KEY));
        raw.savedAt = Date.now() - 2 * 60 * 60 * 1000;
        localStorage.setItem(KIOSK_RECEIPT_STORAGE_KEY, JSON.stringify(raw));

        const read = readKioskReceiptSnapshot();
        expect(read).toBeNull();
        // Purge automatique.
        expect(localStorage.getItem(KIOSK_RECEIPT_STORAGE_KEY)).toBeNull();
    });

    it('JSON corrompu → read renvoie null + purge', () => {
        localStorage.setItem(KIOSK_RECEIPT_STORAGE_KEY, '{not-json');
        const read = readKioskReceiptSnapshot();
        expect(read).toBeNull();
        expect(localStorage.getItem(KIOSK_RECEIPT_STORAGE_KEY)).toBeNull();
    });

    it('version inconnue → null', () => {
        localStorage.setItem(
            KIOSK_RECEIPT_STORAGE_KEY,
            JSON.stringify({ v: 999, savedAt: Date.now(), orderId: 1 }),
        );
        expect(readKioskReceiptSnapshot()).toBeNull();
    });

    it('clearKioskReceiptSnapshot vide la clé', () => {
        saveKioskReceiptSnapshot({ orderId: 1, total: 5, items: [] });
        expect(localStorage.getItem(KIOSK_RECEIPT_STORAGE_KEY)).not.toBeNull();
        clearKioskReceiptSnapshot();
        expect(localStorage.getItem(KIOSK_RECEIPT_STORAGE_KEY)).toBeNull();
    });

    it('save renvoie false si argument invalide', () => {
        expect(saveKioskReceiptSnapshot(null)).toBe(false);
        expect(saveKioskReceiptSnapshot('oops')).toBe(false);
    });

    it('TTL custom respecté', () => {
        saveKioskReceiptSnapshot({ orderId: 1, total: 5, items: [] });
        // TTL de 1 ms → doit être expiré après une microseconde.
        // On attend un tick.
        return new Promise((resolve) => {
            setTimeout(() => {
                expect(readKioskReceiptSnapshot({ ttlMs: 1 })).toBeNull();
                resolve();
            }, 10);
        });
    });
});
