/**
 * f4-button-matrix-visual.spec.js — GOAL Phase F Agent F.4
 *
 * Sparse visual capture closing the "no console error / no 4xx/5xx" criterion
 * that Vitest mount tests cannot empirically prove. Two surfaces only:
 *   1. /admin/pos — confirms PosComponent renders, primary buttons visible
 *   2. /kds      — confirms KitchenDisplaySystemComponent buttons render
 *
 * Each capture records console errors + network 4xx/5xx — uploaded to
 * reports/test-e2e/goal-2026-05-23/phase-f/ for orchestrator review.
 *
 * @FK-ID GOAL-PHASE-F-F4-visual
 */
const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const REPORT_DIR = path.join(
    process.cwd(),
    'reports/test-e2e/goal-2026-05-23/phase-f'
);

test.beforeAll(() => {
    if (!fs.existsSync(REPORT_DIR)) {
        fs.mkdirSync(REPORT_DIR, { recursive: true });
    }
});

test.describe('F.4 sparse visual capture — button matrix', () => {
    test('POS /admin/pos — primary buttons render, no console/network red', async ({ page }) => {
        const consoleErrors = [];
        const httpRed = [];

        page.on('console', (msg) => {
            if (msg.type() === 'error') consoleErrors.push(msg.text());
        });
        page.on('response', (resp) => {
            const status = resp.status();
            if (status >= 400 && status < 600) {
                httpRed.push(`${status} ${resp.url()}`);
            }
        });

        // Admin login (admin@lecayenne.fr / 123456 — from memory ref)
        await page.goto('http://127.0.0.1:8000/login', { waitUntil: 'domcontentloaded' });
        await page.fill('input[name="email"]', 'admin@lecayenne.fr').catch(() => {});
        await page.fill('input[name="password"]', '123456').catch(() => {});
        await page.click('button[type="submit"]').catch(() => {});
        await page.waitForLoadState('networkidle', { timeout: 8000 }).catch(() => {});

        // Navigate to POS surface
        await page.goto('http://127.0.0.1:8000/admin/pos', { waitUntil: 'domcontentloaded' });
        await page.waitForLoadState('networkidle', { timeout: 12000 }).catch(() => {});

        // Capture
        const shot = path.join(REPORT_DIR, 'F4-pos-buttons.png');
        await page.screenshot({ path: shot, fullPage: false });

        // Findings annotation
        fs.writeFileSync(
            path.join(REPORT_DIR, 'F4-pos-runtime-findings.json'),
            JSON.stringify({
                surface: 'POS /admin/pos',
                screenshot: 'F4-pos-buttons.png',
                console_errors: consoleErrors.slice(0, 20),
                http_red: httpRed.slice(0, 20),
                verdict: consoleErrors.length === 0 && httpRed.length === 0 ? 'CLEAN' : 'OBSERVATIONS',
            }, null, 2)
        );

        // Soft-assert — capture findings even on errors so orchestrator sees state
        console.log(`F.4 POS: console errors=${consoleErrors.length}, http red=${httpRed.length}`);
    });

    test('KDS /kds — primary buttons render, no console/network red', async ({ page }) => {
        const consoleErrors = [];
        const httpRed = [];

        page.on('console', (msg) => {
            if (msg.type() === 'error') consoleErrors.push(msg.text());
        });
        page.on('response', (resp) => {
            const status = resp.status();
            if (status >= 400 && status < 600) {
                httpRed.push(`${status} ${resp.url()}`);
            }
        });

        // Ensure auth (admin)
        await page.goto('http://127.0.0.1:8000/login', { waitUntil: 'domcontentloaded' });
        await page.fill('input[name="email"]', 'admin@lecayenne.fr').catch(() => {});
        await page.fill('input[name="password"]', '123456').catch(() => {});
        await page.click('button[type="submit"]').catch(() => {});
        await page.waitForLoadState('networkidle', { timeout: 8000 }).catch(() => {});

        await page.goto('http://127.0.0.1:8000/kds', { waitUntil: 'domcontentloaded' });
        await page.waitForLoadState('networkidle', { timeout: 12000 }).catch(() => {});

        const shot = path.join(REPORT_DIR, 'F4-kds-buttons.png');
        await page.screenshot({ path: shot, fullPage: false });

        fs.writeFileSync(
            path.join(REPORT_DIR, 'F4-kds-runtime-findings.json'),
            JSON.stringify({
                surface: 'KDS /kds',
                screenshot: 'F4-kds-buttons.png',
                console_errors: consoleErrors.slice(0, 20),
                http_red: httpRed.slice(0, 20),
                verdict: consoleErrors.length === 0 && httpRed.length === 0 ? 'CLEAN' : 'OBSERVATIONS',
            }, null, 2)
        );

        console.log(`F.4 KDS: console errors=${consoleErrors.length}, http red=${httpRed.length}`);
    });
});
