/**
 * [T-D STOCK-IA 2026-08-16 · GOAL owner] Contrat : la caisse affiche le nombre
 * d'articles en stock faible (badge), pour préparer la liste d'achat du
 * lendemain — même endpoint/sémantique que StockLowAlertsWidget.vue (dashboard
 * admin), même tick de polling que le tracker commandes (fallback quand Echo
 * est mort). PosComponent.vue est trop volumineux pour un mount complet fiable
 * en test — même discipline de test "contrat source" que posEchoDebounce.spec.js.
 */
import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const source = readFileSync(
    resolve(process.cwd(), 'resources/js/components/admin/pos/PosComponent.vue'),
    'utf8',
);

describe('POS — badge stock faible (contrat source)', () => {
    it('lowStockCount est initialisé à 0 dans data()', () => {
        expect(source).toMatch(/lowStockCount:\s*0,/);
    });

    it('loadLowStockCount() interroge admin/stock/low-alerts et projette la longueur, silencieux en erreur', () => {
        const match = source.match(/async loadLowStockCount\(\) \{([\s\S]*?)\n {8}\},/);
        expect(match, 'loadLowStockCount introuvable').toBeTruthy();
        const body = match[1];
        expect(body).toMatch(/axios\.get\(['"]admin\/stock\/low-alerts['"]\)/);
        expect(body).toMatch(/this\.lowStockCount\s*=\s*\(res\.data\?\.alerts\s*\?\?\s*\[\]\)\.length/);
        expect(body).toMatch(/catch[\s\S]*this\.lowStockCount\s*=\s*0/);
    });

    it('le polling de secours (_startKioskPolling) appelle loadLowStockCount à chaque tick', () => {
        const match = source.match(/_startKioskPolling\(\) \{([\s\S]*?)\n {8}\},/);
        expect(match, '_startKioskPolling introuvable').toBeTruthy();
        expect(match[1]).toMatch(/this\.loadLowStockCount\(\)/);
    });

    it('le bouton badge est masqué à zéro alerte (v-if lowStockCount > 0)', () => {
        expect(source).toMatch(/v-if="lowStockCount > 0"[\s\S]{0,400}?:badge="lowStockCount"/);
    });

    it('le bouton pointe vers admin.stock.rupture (même page que le widget dashboard)', () => {
        const match = source.match(/v-if="lowStockCount > 0"([\s\S]{0,400}?)data-testid="pos-low-stock-open"/);
        expect(match, 'bouton pos-low-stock-open introuvable').toBeTruthy();
        expect(match[1]).toMatch(/:to="\{ name: 'admin\.stock\.rupture' \}"/);
    });
});
