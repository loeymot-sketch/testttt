/**
 * [GOAL 2026-06-12 — micro-audit dashboard] Sentinel d'intégrité du routeur SPA.
 *
 * Bug attrapé à l'origine : `admin.pos-orders` redirigeait vers le nom
 * `admin.pos.orders.list` (points) alors que la route réelle s'appelle
 * `admin.pos-orders.list` (tiret) → Vue Router avale l'erreur → la page
 * « Commandes Caisse » rendait BLANC sans aucune erreur console/réseau.
 *
 * Règle : tout `redirect: { name: X }` et tout `name:` cité doivent pointer
 * vers une route déclarée. Statique (lecture source) = zéro montage, zéro flake.
 */
import { describe, it, expect } from 'vitest';
import fs from 'fs';
import path from 'path';

const MODULES_DIR = path.resolve(__dirname, '../../resources/js/router/modules');

function collect() {
    const files = fs.readdirSync(MODULES_DIR).filter((f) => f.endsWith('.js'));
    const declared = new Set();
    const redirects = []; // { file, target }
    for (const f of files) {
        const src = fs.readFileSync(path.join(MODULES_DIR, f), 'utf8');
        for (const m of src.matchAll(/redirect:\s*\{\s*name:\s*["']([^"']+)["']/g)) {
            redirects.push({ file: f, target: m[1] });
        }
        // Retire les blocs redirect AVANT de collecter les noms déclarés —
        // sinon le nom cité dans le redirect « s'auto-déclare » et un
        // redirect cassé passe vert (faux négatif du 1er run de ce spec).
        const withoutRedirects = src.replace(/redirect:\s*\{\s*name:\s*["'][^"']+["']\s*\}/g, '');
        for (const m of withoutRedirects.matchAll(/name:\s*["']([^"']+)["']/g)) declared.add(m[1]);
    }
    return { declared, redirects };
}

describe('Routeur SPA — intégrité des redirects nommés', () => {
    it('chaque redirect:{name} pointe vers une route déclarée', () => {
        const { declared, redirects } = collect();
        expect(redirects.length).toBeGreaterThan(0);
        const broken = redirects.filter((r) => !declared.has(r.target));
        expect(
            broken,
            `Redirects cassés (page blanche silencieuse): ${JSON.stringify(broken)}`,
        ).toEqual([]);
    });
});
