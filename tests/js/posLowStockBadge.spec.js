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

/**
 * [test-e2e fix A-002/E-001/E-002/E-006 round-1 2026-08-16] loadLowStockCount()
 * appelait GET admin/stock/low-alerts SANS AUCUNE vérification de permission —
 * un rôle sans `items_show` (ex: POS Operator) recevait un 403 à CHAQUE tick
 * de polling, à vie, sans jamais pouvoir réussir (finding adversarial
 * A-002/E-001/E-002, 2 vagues indépendantes). RED avant fix : aucune méthode
 * canFetchLowStockAlerts() n'existait dans la source et axios.get était
 * appelé inconditionnellement. GREEN après fix : le gate existe, suit le
 * MÊME slug backend réel que le widget (items_show / url 'items/show'), et
 * loadLowStockCount() retourne tôt (lowStockCount=0, pas de requête) quand
 * il échoue.
 */
describe('POS — badge stock faible : gate permission avant le fetch (pas de 403 en boucle)', () => {
    it('canFetchLowStockAlerts() existe et vérifie le slug backend réel items/show (pas items)', () => {
        const match = source.match(/canFetchLowStockAlerts\(\) \{([\s\S]*?)\n {8}\},/);
        expect(match, 'canFetchLowStockAlerts introuvable').toBeTruthy();
        const body = match[1];
        expect(body).toMatch(/p\.url === 'items\/show'/);
        expect(body).not.toMatch(/p\.url === 'items'[^/]/);
    });

    it('loadLowStockCount() consulte canFetchLowStockAlerts() AVANT le fetch et sort tôt si refusé', () => {
        const match = source.match(/async loadLowStockCount\(\) \{([\s\S]*?)\n {8}\},/);
        expect(match, 'loadLowStockCount introuvable').toBeTruthy();
        const body = match[1];
        // Le gate doit être vérifié, ET précéder l'appel axios.get dans le
        // texte source (pas juste être présent quelque part dans le fichier).
        const gateIdx = body.indexOf('canFetchLowStockAlerts()');
        const axiosIdx = body.indexOf("axios.get('admin/stock/low-alerts')");
        expect(gateIdx, 'gate non appelé dans loadLowStockCount').toBeGreaterThan(-1);
        expect(axiosIdx).toBeGreaterThan(-1);
        expect(gateIdx).toBeLessThan(axiosIdx);
        expect(body).toMatch(/if \(!this\.canFetchLowStockAlerts\(\)\) \{\s*\n\s*this\.lowStockCount = 0;\s*\n\s*return;/);
    });
});
