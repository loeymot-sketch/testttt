import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

/**
 * @FK-ID FK-RED-R1-P2 | @source RED-R1 plan P2 — UX 409 Idempotency-Key-Conflict
 * @reason
 *   IdempotencyKeyMiddleware retourne 409 + header `Idempotency-Key-Conflict: true`
 *   quand le même Idempotency-Key arrive 2× avec un payload différent. Sans handler
 *   dédié côté store posOrder/save, le caissier voit un toast d'erreur générique
 *   (ex. "Erreur réseau") et risque de relancer une commande déjà enregistrée.
 *
 * Ce sentinel ferme le contrat structurel :
 *   - posOrder/save catch détecte status 409 + header idempotency-key-conflict
 *   - reject livre une enveloppe portable (`_idempotencyConflict: true`) pour le UI
 *   - PaymentComponent.handlePaymentError reconnaît `_idempotencyConflict` et
 *     surface un message explicite (pas un toast réseau générique).
 */
describe('posOrder/save — 409 Idempotency-Key-Conflict handler (RED-R1 P2)', () => {
    const storeSource = readFileSync(
        resolve(process.cwd(), 'resources/js/store/modules/posOrder.js'),
        'utf8',
    );
    const componentSource = readFileSync(
        resolve(process.cwd(), 'resources/js/components/admin/pos/PaymentComponent.vue'),
        'utf8',
    );

    it('store catch checks `err.response.status === 409`', () => {
        expect(storeSource).toMatch(/err\?\.response\?\.status\s*===\s*409/);
    });

    it('store catch checks the `idempotency-key-conflict` response header (lower-case)', () => {
        // axios normalises response headers to lower-case — match that exact key.
        expect(storeSource).toMatch(/err\?\.response\?\.headers\?\.\['idempotency-key-conflict'\]/);
    });

    it('store reject ships an `_idempotencyConflict` flag the UI can branch on', () => {
        expect(storeSource).toMatch(/_idempotencyConflict\s*:\s*true/);
    });

    it('PaymentComponent.handlePaymentError handles the `_idempotencyConflict` envelope', () => {
        expect(componentSource).toMatch(/err\?\._idempotencyConflict/);
    });
});
