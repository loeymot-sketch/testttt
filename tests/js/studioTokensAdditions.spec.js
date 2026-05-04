import { describe, expect, it } from 'vitest';
import { readFileSync, readdirSync } from 'node:fs';
import { join, resolve } from 'node:path';

/**
 * Sentinel : `resources/css/foundations/cv1-tokens.css` ne doit pas redéfinir
 * arbitrairement les tokens CV1 de base documentés dans
 * `docs/design/DESIGN_SYSTEM_FOUNDATIONS_CV1.md` §2 (couches AA + au plus une
 * couche a11y / reduced-motion autorisée).
 */

const CV1_TOKENS_FILE = 'resources/css/foundations/cv1-tokens.css';
const RESOURCES_CSS_ROOT = 'resources/css';

/** Valeurs AA officielles (doc §2 + fichier aligné). */
const CV1_BASELINE = {
    '--cv1-status-info-bg': '#f1f5f9',
    '--cv1-status-warning-bg': '#fffbeb',
    '--cv1-status-blocker-bg': '#fff1f2',
    '--cv1-surface-default': '#ffffff',
    '--cv1-surface-strong': '#0f172a',
    '--cv1-border-default': '#e2e8f0',
    '--cv1-border-emphasis': '#2563eb',
    '--cv1-focus-ring-width': '3px',
    '--cv1-focus-ring-color': '#2563eb',
    '--cv1-motion-default': '200ms',
    '--cv1-motion-easing': 'cubic-bezier(0.2, 0, 0, 1)',
    '--cv1-step-active-bg': '#2563eb',
    '--cv1-step-completed-bg': '#ecfdf5',
    '--cv1-step-pending-bg': '#f1f5f9',
};

/** Valeurs secondaires admises (couches `[data-kiosk-contrast='aaa']`, `[data-kiosk-reduced-motion='true']`). */
const CV1_SECONDARY_ALLOWED = {
    '--cv1-border-default': ['#94a3b8'],
    '--cv1-motion-default': ['1ms'],
};

function stripTrailingComments(fragment) {
    return fragment.replace(/\/\*[\s\S]*?\*\//g, '').trim();
}

function collectDefinitions(cssText, varName) {
    const escaped = varName.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    const re = new RegExp(`${escaped}\\s*:\\s*([^;]+);`, 'g');
    const out = [];
    let m = re.exec(cssText);
    while (m !== null) {
        out.push(stripTrailingComments(m[1]));
        m = re.exec(cssText);
    }
    return out;
}

function listCssFilesUnder(dirAbs) {
    const out = [];
    const stack = [dirAbs];
    while (stack.length) {
        const d = stack.pop();
        let entries;
        try {
            entries = readdirSync(d, { withFileTypes: true });
        } catch {
            continue;
        }
        for (const ent of entries) {
            const p = join(d, ent.name);
            if (ent.isDirectory()) {
                if (ent.name === 'node_modules') {
                    continue;
                }
                stack.push(p);
            } else if (ent.isFile() && ent.name.endsWith('.css')) {
                out.push(p);
            }
        }
    }
    return out.sort();
}

describe('CV1 foundations tokens sentinel', () => {
    it('cv1-tokens.css does not redefine baseline CV1 tokens beyond official a11y/contrast layers', () => {
        const cwd = process.cwd();
        const absCv1 = resolve(cwd, CV1_TOKENS_FILE);
        const cv1Css = readFileSync(absCv1, 'utf8');

        const whitelist = Object.keys(CV1_BASELINE);

        for (const token of whitelist) {
            const defs = collectDefinitions(cv1Css, token);
            expect(
                defs.length,
                `${token}: trop de définitions (${defs.length}), attendu ≤ 2`,
            ).toBeLessThanOrEqual(2);
            expect(defs.length, `${token}: token documenté introuvable`).toBeGreaterThan(0);

            const expectedAa = CV1_BASELINE[token];
            expect(
                defs[0],
                `${token}: première valeur doit être la baseline AA (${expectedAa})`,
            ).toBe(expectedAa);

            if (defs.length === 2) {
                const allowed = CV1_SECONDARY_ALLOWED[token];
                expect(
                    allowed,
                    `${token}: une seconde définition est présente mais non autorisée par la sentinel`,
                ).toBeDefined();
                expect(
                    allowed.includes(defs[1]),
                    `${token}: valeur secondaire inattendue "${defs[1]}"`,
                ).toBe(true);
            }
        }

        // Studio : les `--studio-*` sont des additions possibles (ici ou ailleurs sous resources/css).
        const resourcesAbs = resolve(cwd, RESOURCES_CSS_ROOT);
        const cssFiles = listCssFilesUnder(resourcesAbs);
        const studioDefRe = /--studio-[a-z0-9-]+\s*:/gi;
        const studioHits = [];
        for (const f of cssFiles) {
            const src = readFileSync(f, 'utf8');
            let sm = studioDefRe.exec(src);
            while (sm !== null) {
                studioHits.push({ file: f, match: sm[0].trim() });
                sm = studioDefRe.exec(src);
            }
        }
        expect(Array.isArray(studioHits)).toBe(true);
    });
});
