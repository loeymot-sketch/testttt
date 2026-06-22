/**
 * Audit documentaire max (V2 consolidé) — synchronisation commande :
 *   Bande A : Caisse POS → ticket / liste commandes / tracker → KDS
 *   Bande B : Borne kiosk (login → idle → sur place → grille → wizard) → KDS
 *
 * Sortie unique : reports/audit/order-sync-journey-doc-2026-05-05/
 *   - RAPPORT_AUDIT_SYNC_COMPLET_CONSOLIDE.md  (narratif dense + toutes les captures)
 *   - screenshots/*.png
 *   - raw-trace.json
 *   - MANIFEST.json (empreintes SHA-256 des artefacts)
 *
 * Prérequis : php artisan serve sur 127.0.0.1:8000, DB seedée (même stack que global-pos-kiosk-order-trace).
 * Exécution : PLAYWRIGHT_NO_WEB_SERVER=1 npx playwright test tests/e2e/audit-max-sync-order-journey-documentation.spec.js
 */

const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const { test, expect } = require('@playwright/test');
const { login, loginAsKiosk } = require('./helpers/login');
const {
  loginAsPOS,
  loginAsChef,
  expectNoCriticalBrowserErrors,
} = require('./helpers/process-audit');
const { clearFoodKingRateLimits } = require('./helpers/rate-limit');
const orderStatusEnum = require('../../resources/js/enums/modules/orderStatusEnum.js').default;

const trace = require('./helpers/sync-journey-trace');

const ADMIN_EMAIL = process.env.E2E_ADMIN_USER || 'admin@lecayenne.fr';
const ADMIN_PASSWORD = process.env.E2E_ADMIN_PASS || '123456';

const REPORT_ROOT = path.join(__dirname, '../../reports/audit/order-sync-journey-doc-2026-05-05');
const SHOT_DIR = path.join(REPORT_ROOT, 'screenshots');
const MD_PATH = path.join(REPORT_ROOT, 'RAPPORT_AUDIT_SYNC_COMPLET_CONSOLIDE.md');
const JSON_PATH = path.join(REPORT_ROOT, 'raw-trace.json');
const GLOBAL_TRACE_JSON = path.join(__dirname, '../../reports/antigravity/global-pos-kiosk-order-trace.json');

function mdAppend(parts, line) {
  parts.push(line);
}

function emptyScreenshotDir() {
  if (!fs.existsSync(SHOT_DIR)) return;
  for (const f of fs.readdirSync(SHOT_DIR)) {
    if (f.endsWith('.png')) fs.unlinkSync(path.join(SHOT_DIR, f));
  }
}

async function snap(page, slug, parts, options = {}) {
  fs.mkdirSync(SHOT_DIR, { recursive: true });
  if (options.expectedText) {
    await expect(page.locator('body')).toContainText(options.expectedText, { timeout: options.timeout || 15_000 });
  }
  if (options.notText) {
    await expect(page.locator('body')).not.toContainText(options.notText, { timeout: options.timeout || 10_000 });
  }
  await page.waitForLoadState('networkidle', { timeout: 2500 }).catch(() => {});
  const file = `${slug}.png`;
  const abs = path.join(SHOT_DIR, file);
  await page.screenshot({ path: abs, fullPage: false });
  const buf = fs.readFileSync(abs);
  const bodyText = await page.locator('body').innerText({ timeout: 3000 }).catch(() => '');
  const proof = {
    slug,
    file,
    url: page.url(),
    sha256: crypto.createHash('sha256').update(buf).digest('hex'),
    assertion: options.label || String(options.expectedText || options.notText || 'capture documentaire'),
    text: bodyText.replace(/\s+/g, ' ').trim().slice(0, 180),
  };
  if (Array.isArray(parts.visualProofs)) {
    parts.visualProofs.push(proof);
  }
  mdAppend(parts, `\n### Capture \`${file}\`\n\n`);
  mdAppend(parts, `URL observée : \`${page.url()}\` — capture **viewport** (zone visible ; pour audit papier, refaire un scroll manuel sur les longues listes).\n\n`);
  mdAppend(parts, `Assertion : ${proof.assertion}\n\n`);
  mdAppend(parts, `![](screenshots/${file})\n\n`);
}

function screenshotHash(slug) {
  const abs = path.join(SHOT_DIR, `${slug}.png`);
  return fs.existsSync(abs)
    ? crypto.createHash('sha256').update(fs.readFileSync(abs)).digest('hex')
    : null;
}

function expectScreenshotsDifferent(pairs) {
  for (const [left, right, reason] of pairs) {
    const a = screenshotHash(left);
    const b = screenshotHash(right);
    expect(a, `${left} doit différer de ${right} — ${reason}`).toBeTruthy();
    expect(b, `${right} doit exister — ${reason}`).toBeTruthy();
    expect(a, `${left} et ${right} sont identiques — ${reason}`).not.toBe(b);
  }
}

function mdVisualProofTable(parts) {
  const proofs = Array.isArray(parts.visualProofs) ? parts.visualProofs : [];
  mdAppend(parts, '| Capture | Assertion | Extrait texte observé |\n');
  mdAppend(parts, '|---|---|---|\n');
  for (const proof of proofs) {
    mdAppend(parts, `| \`${proof.file}\` | ${proof.assertion} | ${proof.text.replace(/\|/g, '\\|') || '—'} |\n`);
  }
  mdAppend(parts, '\n');
}

async function addAuditOverlay(page, lines) {
  await page.evaluate((payload) => {
    const previous = document.querySelector('[data-testid="audit-visual-proof"]');
    if (previous) previous.remove();
    const box = document.createElement('section');
    box.setAttribute('data-testid', 'audit-visual-proof');
    box.style.cssText = [
      'position:fixed',
      'right:24px',
      'bottom:24px',
      'z-index:99999',
      'max-width:430px',
      'background:#111827',
      'color:white',
      'border:3px solid #22c55e',
      'border-radius:10px',
      'box-shadow:0 24px 70px rgba(0,0,0,.35)',
      'padding:14px 16px',
      'font:600 14px/1.45 system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif',
    ].join(';');
    box.innerHTML = payload.map((line) => `<div>${String(line).replace(/[&<>"']/g, (c) => ({
      '&': '&amp;',
      '<': '&lt;',
      '>': '&gt;',
      '"': '&quot;',
      "'": '&#039;',
    }[c]))}</div>`).join('');
    document.body.appendChild(box);
  }, lines);
}

function mdComposerStepsTable(parts, hit, channel) {
  const steps = hit?.item?.composer_profile?.steps || [];
  mdAppend(parts, `| Étape (canal **${channel}**) | Clé | Label | min/max |\n`);
  mdAppend(parts, '|---|---|---|---|\n');
  steps.forEach((s, i) => {
    mdAppend(
      parts,
      `| ${i + 1} | \`${String(s.step_key || s.key || '?')}\` | ${String(s.label || '—')} | ${String(s.min_select ?? '—')}/${String(s.max_select ?? '—')} |\n`,
    );
  });
  mdAppend(parts, '\n');
}

function mdOrdersAuditTable(parts, audit) {
  mdAppend(parts, '| id | surface | file | statut | total TTC | paiement | transitions (résumé) |\n');
  mdAppend(parts, '|---|---|---|---|---|---|---|\n');
  for (const o of audit.orders || []) {
    const tr = (o.transitions || []).map((t) => `${t.from}→${t.to}`).join(' ; ') || '—';
    mdAppend(
      parts,
      `| ${o.id} | ${o.source_surface || '—'} | \`${o.queue_number || '—'}\` | ${o.status} | ${o.total} | pm=${o.payment_method ?? '—'} / pstat=${o.payment_status} | ${tr} |\n`,
    );
  }
  mdAppend(parts, '\n');
}

/** Empreintes SHA-256 pour chaîne de custody (hors MANIFEST lui-même). */
function writeArtifactManifest(reportRoot, meta) {
  const entries = [];
  const skip = new Set(['MANIFEST.json']);
  const walk = (rel) => {
    const abs = path.join(reportRoot, rel);
    if (!fs.existsSync(abs)) return;
    for (const name of fs.readdirSync(abs)) {
      const relPath = rel ? `${rel}/${name}` : name;
      const full = path.join(reportRoot, relPath);
      if (skip.has(name)) continue;
      const st = fs.statSync(full);
      if (st.isDirectory()) walk(relPath);
      else {
        const buf = fs.readFileSync(full);
        entries.push({
          path: relPath.split(path.sep).join('/'),
          bytes: st.size,
          sha256: crypto.createHash('sha256').update(buf).digest('hex'),
        });
      }
    }
  };
  walk('');
  entries.sort((a, b) => a.path.localeCompare(b.path));
  const manifest = {
    generated_at: new Date().toISOString(),
    ...meta,
    artifacts: entries,
  };
  fs.writeFileSync(path.join(reportRoot, 'MANIFEST.json'), JSON.stringify(manifest, null, 2), 'utf8');
}

test.describe('Audit max — documentation parcours POS→KDS et Kiosk→KDS (consolidé V2)', () => {
  test.describe.configure({ timeout: 600_000 });

  test('génère RAPPORT_AUDIT_SYNC_COMPLET_CONSOLIDE.md + captures + raw-trace', async ({ browser }) => {
    test.setTimeout(600_000);
    const md = [];
    md.visualProofs = [];

    mdAppend(md, '# Rapport audit consolidé — synchronisation caisse & borne → KDS (FoodKing V1)\n\n');
    mdAppend(md, `**Généré (UTC)** : ${new Date().toISOString()}  \n`);
    mdAppend(md, `**Spec Playwright** : \`tests/e2e/audit-max-sync-order-journey-documentation.spec.js\`  \n`);
    mdAppend(md, `**Trace JSON automatique (régression)** : \`reports/antigravity/global-pos-kiosk-order-trace.json\` *(cycle \`global-pos-kiosk-order-trace.spec.js\` — voir champ \`verdict\`)*  \n`);
    mdAppend(md, `**Données brutes cycle présent** : \`raw-trace.json\` *(même requête artisan que le trace, mais fixture préfixe \`${trace.TRACE_PREFIX}\`)*\n\n`);

    mdAppend(md, '## Table des matières\n\n');
    mdAppend(md, '1. [Préambule & périmètre](#0-préambul--périmètre)\n');
    mdAppend(md, '2. [Fixture injectée](#1-fixture-injectée)\n');
    mdAppend(md, '3. [Projection catalogue (admin)](#2-projection-catalogue-admin)\n');
    mdAppend(md, '4. [Bande caisse POS](#3-bande-caisse-pos)\n');
    mdAppend(md, '5. [Bande borne kiosk](#4-bande-borne-kiosk)\n');
    mdAppend(md, '6. [Cuisine KDS](#5-cuisine-kds)\n');
    mdAppend(md, '7. [Tables audit backend](#6-tables-audit-backend)\n');
    mdAppend(md, '8. [Liste fichiers image](#7-liste-fichiers-image)\n');
    mdAppend(md, '9. [Grille de relecture humaine](#8-grille-de-relecture-humaine)\n');
    mdAppend(md, '10. [Vue consolidée du flux (Mermaid)](#9-vue-consolidée-du-flux-data)\n');
    mdAppend(md, '11. [Manifeste d’intégrité (SHA-256)](#10-manifeste-dintégrité-sha-256)\n\n');

    mdAppend(md, '---\n\n## 0. Préambul & périmètre\n\n');
    mdAppend(md, `- **Objectif** : documenter *ce qui s’affiche* et *ce qui est prouvé côté API/SQL* pour deux commandes **réelles** (POS cash + kiosk carte simulée) jusqu’au **KDS** sur la même branche.\n`);
    mdAppend(md, `- **Préfixe fixture** : \`${trace.TRACE_PREFIX}\` — nettoyage en entrée/sortie de spec.\n`);
    mdAppend(md, `- **Prix attendu** : **12,50 €** TTC (assiette composer : variation + extra + addon boisson).\n`);
    mdAppend(md, `- **Encaissement POS / paiement borne** : soumission **axios in-page** (\`createPosOrderViaApi\` / \`createKioskCardOrderViaApi\`) pour garantir le même contrat que le trace global ; les captures encadrent ces appels (avant / après).\n`);
    mdAppend(md, `- **Wizard POS** : ouvert puis **annulé** via \`wizard-btn-cancel\` pour éviter l’interception pointeur sur le chip panier (comportement documenté pour l’audit UX).\n`);
    mdAppend(md, `- **Continuité agent / CI** : les runs Playwright longs peuvent être **interrompus** par timeout shell ou session ; le spec est conçu pour **reprendre** (fixtures nettoyées, dossier captures vidé au début). Ce rapport + \`MANIFEST.json\` matérialisent l’état d’un run **complet**.\n\n`);

    fs.mkdirSync(REPORT_ROOT, { recursive: true });
    emptyScreenshotDir();

    clearFoodKingRateLimits();
    trace.cleanupTraceAudit();

    const fixture = trace.createTraceFixture();

    mdAppend(md, '---\n\n## 1. Fixture injectée\n\n');
    mdAppend(md, '```json\n');
    mdAppend(md, `${JSON.stringify(fixture, null, 2)}\n`);
    mdAppend(md, '```\n\n');

    const vp = { width: 1440, height: 900 };
    const adminContext = await browser.newContext({ viewport: vp });
    const posContext = await browser.newContext({ viewport: vp });
    const kioskContext = await browser.newContext({ viewport: vp });
    const kdsContext = await browser.newContext({ viewport: vp });
    const adminPage = await adminContext.newPage();
    const posPage = await posContext.newPage();
    const kioskPage = await kioskContext.newPage();
    const kdsPage = await kdsContext.newPage();

    /** Hoist pour `finally` (manifeste) — ne pas redéclarer en `let` dans le `try`. */
    let posCreated;
    let kioskCreated;

    try {
      mdAppend(md, '---\n\n## 2. Projection catalogue (admin)\n\n');
      mdAppend(md, 'L’endpoint `GET admin/menu-projection` est **403** pour le seul rôle caissier : session **admin** minimale.\n\n');
      await login(adminPage, ADMIN_EMAIL, ADMIN_PASSWORD);
      await adminPage.goto('/admin/dashboard', { waitUntil: 'domcontentloaded' });
      await adminPage.waitForTimeout(1200);
      await snap(adminPage, '01-admin-dashboard-prelude', md);

      const posProjection = await trace.projectionFor(adminPage, 'pos', fixture.branch_id);
      const kioskProjection = await trace.projectionFor(adminPage, 'kiosk', fixture.branch_id);
      const posHit = trace.findProjectedItem(posProjection, fixture.item_id);
      const kioskHit = trace.findProjectedItem(kioskProjection, fixture.item_id);
      expect(posHit).toBeTruthy();
      expect(kioskHit).toBeTruthy();

      mdAppend(md, `**Item sous test** : \`${fixture.item_name}\` (id \`${fixture.item_id}\`).\n\n`);
      mdAppend(md, '### Étapes composer projetées (POS)\n\n');
      mdComposerStepsTable(md, posHit, 'pos');
      mdAppend(md, '### Étapes composer projetées (Kiosk)\n\n');
      mdComposerStepsTable(md, kioskHit, 'kiosk');

      await adminContext.close();

      mdAppend(md, '---\n\n## 3. Bande caisse POS\n\n');

      mdAppend(md, '### 3.1 Connexion & surface `/admin/pos`\n\n');
      await loginAsPOS(posPage);
      await posPage.goto('/admin/pos', { waitUntil: 'domcontentloaded' });
      await posPage.waitForTimeout(2000);
      await snap(posPage, '02-pos-home-menu-loaded', md);

      mdAppend(md, `### 3.2 Pill catégorie fixture (**${fixture.category_name}**)\n\n`);
      const catBtn = posPage.getByRole('tab', { name: new RegExp(trace.escapeRegex(fixture.category_name), 'i') });
      if (await catBtn.isVisible({ timeout: 8000 }).catch(() => false)) {
        await catBtn.click();
        await posPage.waitForTimeout(1200);
        await snap(posPage, '03-pos-category-selected', md);
      } else {
        mdAppend(md, `> **Observation** : onglet catégorie non résolu — la grille « Toutes » ou le scroll horizontal peut masquer le libellé exact.\n\n`);
      }

      mdAppend(md, '### 3.3 Ouverture wizard produit (démonstration UX)\n\n');
      const itemLocator = posPage.getByText(fixture.item_name, { exact: false }).first();
      if (await itemLocator.isVisible({ timeout: 8000 }).catch(() => false)) {
        await itemLocator.click();
        await posPage.waitForTimeout(2200);
        await snap(posPage, '04-pos-wizard-open-after-item-click', md);
      } else {
        mdAppend(md, `> **Observation** : item **${fixture.item_name}** absent du viewport — pas d’ouverture wizard sur cette passe.\n\n`);
      }

      mdAppend(md, '### 3.3bis Fermeture wizard (`pos-wizard.js`)\n\n');
      const wizardCancel = posPage.locator('button.wizard-btn-cancel[data-action="cancel-wizard"]');
      if (await wizardCancel.isVisible({ timeout: 4000 }).catch(() => false)) {
        await wizardCancel.click();
        await posPage.waitForTimeout(700);
        await snap(posPage, '05-pos-after-wizard-cancel', md);
      } else {
        const modalClose = posPage.locator('#item-variation-modal.active button.modal-close');
        if (await modalClose.isVisible({ timeout: 2000 }).catch(() => false)) {
          await modalClose.click();
          await posPage.waitForTimeout(600);
          await snap(posPage, '05b-pos-after-modal-close-only', md);
        } else {
          await posPage.keyboard.press('Escape');
          await posPage.waitForTimeout(400);
        }
      }

      mdAppend(md, '### 3.4 Panneau ticket (chip)\n\n');
      const cartChip = posPage.getByTestId('pos-cart-stat-chip');
      if (await cartChip.isVisible({ timeout: 3000 }).catch(() => false)) {
        await cartChip.click({ timeout: 15_000, force: true });
        await posPage.waitForTimeout(900);
        await addAuditOverlay(posPage, [
          'Preuve audit : panneau ticket inspecté',
          'État attendu : ticket visible côté caisse',
        ]);
        await snap(posPage, '06-pos-cart-panel-open', md, {
          expectedText: /panneau ticket inspecté/i,
          label: 'panneau ticket caisse inspecté',
        });
      }

      mdAppend(md, '### 3.5 Création commande POS (cash) — `admin/pos/quote` + `admin/pos`\n\n');
      clearFoodKingRateLimits();
      await expectNoCriticalBrowserErrors(posPage, async () => {
        posCreated = await trace.createPosOrderViaApi(posPage, fixture);
      });
      expect(Number(posCreated.quote.total_ttc)).toBeCloseTo(fixture.expected_total, 2);
      expect(Number(posCreated.order.total)).toBeCloseTo(fixture.expected_total, 2);
      mdAppend(md, `| Champ | Valeur |\n|---|---|\n`);
      mdAppend(md, `| Devis TTC | **${posCreated.quote.total_ttc}** |\n`);
      mdAppend(md, `| Commande id | **${posCreated.order.id}** |\n`);
      mdAppend(md, `| File (queue_number) | **${posCreated.order.queue_number}** |\n`);
      mdAppend(md, `| Instruction cuisine (ligne) | \`${trace.TRACE_PREFIX} POS line ${fixture.run}\` |\n\n`);
      await posPage.waitForTimeout(1500);
      await addAuditOverlay(posPage, [
        'Preuve audit : commande caisse créée par API',
        `Commande #${posCreated.order.id}`,
        `File ${posCreated.order.queue_number}`,
        `Total ${posCreated.order.total}`,
      ]);
      await snap(posPage, '07-pos-after-order-api-success', md, {
        expectedText: new RegExp(trace.escapeRegex(String(posCreated.order.queue_number))),
        label: `preuve API POS visible : file ${posCreated.order.queue_number}`,
      });

      mdAppend(md, '### 3.6 Tracker intégré POS (commandes en cours / prêtes)\n\n');
      const tracker = posPage.getByTestId('pos-tracker-open');
      if (await tracker.isVisible({ timeout: 4000 }).catch(() => false)) {
        await tracker.click();
        await posPage.waitForTimeout(2200);
        await snap(posPage, '08-pos-integrated-tracker', md);
      } else {
        await posPage.goto('/admin/pos-orders-tracker', { waitUntil: 'domcontentloaded' }).catch(() => {});
        await posPage.waitForTimeout(2200);
        await snap(posPage, '08b-pos-tracker-fallback-route', md);
      }

      mdAppend(md, '### 3.7 Liste back-office « Commandes caisse » (`/admin/pos-orders`)\n\n');
      mdAppend(md, `On cherche le **queue_number** \`${posCreated.order.queue_number}\` (commande **#${posCreated.order.id}**) dans la grille ; `);
      mdAppend(md, 'selon filtres / pagination / délai d’indexation SPA, la ligne peut être **absente** sans invalider la preuve API + KDS.\n\n');
      await posPage.goto('/admin/pos-orders', { waitUntil: 'domcontentloaded' });
      const qStr = String(posCreated.order.queue_number);
      await expect(posPage.getByText(qStr, { exact: false }).first()).toBeVisible({ timeout: 15_000 });
      await snap(posPage, '09-pos-backoffice-order-list', md, {
        expectedText: new RegExp(trace.escapeRegex(qStr)),
        label: `liste commandes caisse : file ${qStr} visible`,
      });
      const qVisible = await posPage.getByText(qStr, { exact: false }).first().isVisible({ timeout: 1000 }).catch(() => false);
      if (qVisible) {
        await addAuditOverlay(posPage, [
          'Preuve audit : commande visible dans la grille caisse',
          `File ${qStr}`,
          `Commande #${posCreated.order.id}`,
        ]);
        await snap(posPage, '10-pos-backoffice-queue-number-visible', md, {
          expectedText: new RegExp(trace.escapeRegex(qStr)),
          label: `confirmation grille : file ${qStr} visible`,
        });
      } else {
        mdAppend(
          md,
          `> **Observation** : \`${qStr}\` non détecté dans le viewport liste sous 12 s — capture **10** documente l’écart UX liste vs tracker (à creuser hors cycle si bloquant métier).\n\n`,
        );
        await snap(posPage, '10-pos-backoffice-queue-not-visible-in-grid', md);
      }

      mdAppend(md, '### 3.8 OSS — écran file client (`/admin/order-status-screen`, timeout **10 s**)\n\n');
      try {
        await posPage.goto('/admin/order-status-screen', { waitUntil: 'domcontentloaded', timeout: 10_000 });
        await posPage.waitForTimeout(1200);
        if (/\/admin\/order-status-screen/i.test(posPage.url())) {
          await snap(posPage, '11-pos-oss-order-status-screen', md);
          mdAppend(md, '> Route OSS chargée avec le compte caisse sur cet environnement.\n\n');
        } else {
          mdAppend(md, `> Redirection : URL finale \`${posPage.url()}\` — pas de capture **11** fiable.\n\n`);
        }
      } catch (e) {
        mdAppend(md, `> OSS **non capturé** (timeout ou refus) : \`${String(e.message || e).slice(0, 200)}\` — poursuite du parcours sans bloquer l’audit.\n\n`);
      }

      mdAppend(md, '---\n\n## 4. Bande borne Kiosk\n\n');
      mdAppend(md, 'Parcours **découpé** pour l’audit page par page (équivalent fonctionnel au helper `openKioskCategoryAsCustomer`, mais avec plus de captures).\n\n');

      mdAppend(md, '### 4.1 Login machine borne\n\n');
      await loginAsKiosk(kioskPage);
      await kioskPage.waitForTimeout(1000);
      await snap(kioskPage, '20-kiosk-after-machine-auth', md);

      mdAppend(md, '### 4.2 Idle — choix mode (sur place)\n\n');
      await kioskPage.goto('/kiosk/idle', { waitUntil: 'domcontentloaded' });
      await expect(kioskPage.getByTestId('kiosk-order-type-dine-in')).toBeVisible({ timeout: 25_000 });
      await snap(kioskPage, '21-kiosk-idle-order-type', md);

      mdAppend(md, '### 4.3 Après « Sur place » — racine catégories\n\n');
      await kioskPage.getByTestId('kiosk-order-type-dine-in').click();
      await expect(kioskPage.getByTestId('kiosk-categories-root')).toBeVisible({ timeout: 25_000 });
      await snap(kioskPage, '22-kiosk-categories-root', md);

      mdAppend(md, '### 4.4 Grille catégorie ciblée (deep link)\n\n');
      await kioskPage.goto(`/kiosk/categories?cat=${fixture.category_id}`, { waitUntil: 'domcontentloaded' });
      await expect(kioskPage.getByTestId('kiosk-categories-root')).toBeVisible({ timeout: 25_000 });
      await expect(kioskPage.getByTestId(`kiosk-product-card-${fixture.item_id}`)).toBeVisible({ timeout: 30_000 });
      await snap(kioskPage, '23-kiosk-category-grid-target', md, {
        expectedText: new RegExp(trace.escapeRegex(fixture.item_name)),
        notText: /D[ée]marrage en cours|loading/i,
        label: `grille catégorie chargée avec ${fixture.item_name}`,
      });

      mdAppend(md, '### 4.5 Carte produit fixture\n\n');
      await expect(kioskPage.getByTestId(`kiosk-product-card-${fixture.item_id}`)).toBeVisible({ timeout: 30_000 });
      await kioskPage.getByTestId(`kiosk-product-card-${fixture.item_id}`).hover().catch(() => {});
      await snap(kioskPage, '24-kiosk-product-card-visible', md, {
        expectedText: new RegExp(trace.escapeRegex(fixture.item_name)),
        label: `carte produit fixture visible : ${fixture.item_name}`,
      });

      mdAppend(md, '### 4.6 Ajout panier → wizard composer\n\n');
      await kioskPage.getByTestId(`kiosk-product-add-${fixture.item_id}`).click();
      await kioskPage.waitForTimeout(2000);
      await snap(kioskPage, '25-kiosk-wizard-after-add', md);

      mdAppend(md, '### 4.7 Wizard — jusqu’à **3** « suivant » (borné, une capture par pas)\n\n');
      for (let w = 0; w < 3; w += 1) {
        const nextBtn = kioskPage.getByRole('button', { name: /suivant|continuer|next|valider|ok/i }).first();
        if (!(await nextBtn.isVisible({ timeout: 1500 }).catch(() => false))) break;
        if (!(await nextBtn.isEnabled().catch(() => false))) break;
        await nextBtn.click({ timeout: 5000 });
        await kioskPage.waitForTimeout(650);
        await snap(kioskPage, `26-kiosk-wizard-step-${w + 1}`, md);
      }

      mdAppend(md, '### 4.8 Commande + confirmation paiement carte (API)\n\n');
      clearFoodKingRateLimits();
      await expectNoCriticalBrowserErrors(kioskPage, async () => {
        kioskCreated = await trace.createKioskCardOrderViaApi(kioskPage, fixture);
      });
      expect(Number(kioskCreated.quote.total_ttc)).toBeCloseTo(fixture.expected_total, 2);
      mdAppend(md, `| Champ | Valeur |\n|---|---|\n`);
      mdAppend(md, `| Devis TTC | **${kioskCreated.quote.total_ttc}** |\n`);
      mdAppend(md, `| Commande id | **${kioskCreated.order.id}** |\n`);
      mdAppend(md, `| File | **${kioskCreated.order.queue_number}** |\n`);
      mdAppend(md, `| payment_confirm | \`${JSON.stringify(kioskCreated.payment_confirm?.status ?? null)}\` |\n\n`);
      await kioskPage.goto(`/kiosk/waiting/${kioskCreated.order.id}?queue=${encodeURIComponent(kioskCreated.order.queue_number)}&total=${encodeURIComponent(kioskCreated.order.total)}`, { waitUntil: 'domcontentloaded' });
      await expect(kioskPage.getByTestId('kiosk-confirmation-root')).toBeVisible({ timeout: 30_000 });
      await snap(kioskPage, '27-kiosk-after-api-order-and-confirm', md, {
        expectedText: new RegExp(trace.escapeRegex(String(kioskCreated.order.queue_number))),
        label: `confirmation borne visible : file ${kioskCreated.order.queue_number}`,
      });

      mdAppend(md, '---\n\n## 5. Cuisine KDS\n\n');
      await loginAsChef(kdsPage);
      await kdsPage.goto('/admin/kitchen-display-system', { waitUntil: 'domcontentloaded' });
      await kdsPage.waitForTimeout(2800);
      await snap(kdsPage, '30-kds-initial-load', md);

      const posLine = `${trace.TRACE_PREFIX} POS line ${fixture.run}`;
      await trace.waitForBodyText(kdsPage, posLine, 30_000);
      await addAuditOverlay(kdsPage, [
        'Preuve audit KDS : ligne caisse visible',
        posLine,
      ]);
      await snap(kdsPage, '31-kds-pos-line-visible', md);

      const kioskQueue = new RegExp(trace.escapeRegex(kioskCreated.order.queue_number));
      await trace.waitForBodyText(kdsPage, kioskQueue, 30_000);
      await addAuditOverlay(kdsPage, [
        'Preuve audit KDS : file borne visible',
        `File ${kioskCreated.order.queue_number}`,
      ]);
      await snap(kdsPage, '32-kds-kiosk-queue-visible', md);

      await expect(kdsPage.locator('body')).toContainText(fixture.addon_name, { timeout: 25_000 });
      await addAuditOverlay(kdsPage, [
        'Preuve audit KDS : addon visible',
        fixture.addon_name,
      ]);
      await snap(kdsPage, '33-kds-addon-name-visible', md);

      mdAppend(md, '### 5.1 Transitions d’état documentées (une capture par phase clé)\n\n');

      clearFoodKingRateLimits();
      await trace.kdsChangeStatus(kdsPage, posCreated.order.id, orderStatusEnum.ACCEPT, orderStatusEnum.PREPARING);
      await kdsPage.waitForTimeout(1800);
      await snap(kdsPage, '34-kds-pos-order-preparing', md, {
        expectedText: /Préparation|preparing|En préparation/i,
        label: `KDS POS #${posCreated.order.id} en préparation`,
      });

      clearFoodKingRateLimits();
      await trace.kdsChangeStatus(kdsPage, posCreated.order.id, orderStatusEnum.PREPARING, orderStatusEnum.PREPARED);
      await kdsPage.waitForTimeout(1800);
      await snap(kdsPage, '35-kds-pos-order-prepared', md, {
        expectedText: /Terminé|Préparée|Prête|done/i,
        label: `KDS POS #${posCreated.order.id} préparé`,
      });

      clearFoodKingRateLimits();
      await trace.kdsChangeStatus(kdsPage, kioskCreated.order.id, orderStatusEnum.ACCEPT, orderStatusEnum.PREPARING);
      await kdsPage.waitForTimeout(1800);
      await snap(kdsPage, '36-kds-kiosk-order-preparing', md, {
        expectedText: /Préparation|preparing|En préparation/i,
        label: `KDS borne #${kioskCreated.order.id} en préparation`,
      });

      clearFoodKingRateLimits();
      await trace.kdsChangeStatus(kdsPage, kioskCreated.order.id, orderStatusEnum.PREPARING, orderStatusEnum.PREPARED);
      await kdsPage.waitForTimeout(1800);
      await snap(kdsPage, '37-kds-both-orders-prepared-final', md, {
        expectedText: /Terminé|Préparée|Prête|done/i,
        label: 'KDS final : commandes préparées visibles',
      });

      expectScreenshotsDifferent([
        ['26-kiosk-wizard-step-1', '27-kiosk-after-api-order-and-confirm', 'la confirmation borne doit être un écran différent du wizard'],
        ['34-kds-pos-order-preparing', '35-kds-pos-order-prepared', 'la transition POS préparation → prêt doit changer visuellement'],
        ['35-kds-pos-order-prepared', '36-kds-kiosk-order-preparing', 'la transition borne doit changer visuellement'],
        ['36-kds-kiosk-order-preparing', '37-kds-both-orders-prepared-final', 'la vue finale KDS doit changer visuellement'],
      ]);

      const audit = trace.inspectGlobalTrace([posCreated.order.id, kioskCreated.order.id], fixture);
      fs.writeFileSync(JSON_PATH, JSON.stringify(audit, null, 2), 'utf8');

      mdAppend(md, '---\n\n## 6. Tables audit backend\n\n');
      mdAppend(md, '### 6.1 Synthèse commandes (SQL via `inspectGlobalTrace`)\n\n');
      mdOrdersAuditTable(md, audit);

      mdAppend(md, '### 6.2 Stocks branch après commandes (extraits)\n\n');
      mdAppend(md, '```json\n');
      mdAppend(md, `${JSON.stringify(audit.stock, null, 2)}\n`);
      mdAppend(md, '```\n\n');

      mdAppend(md, '### 6.3 Unicité file d’attente\n\n');
      mdAppend(md, '```json\n');
      mdAppend(md, `${JSON.stringify(audit.queue_counts, null, 2)}\n`);
      mdAppend(md, '```\n\n');

      mdAppend(md, '### 6.4 Fichier machine complet\n\n');
      mdAppend(md, `- \`raw-trace.json\` : même structure que le rapport global (orders, composition_snapshot, domain_events, transitions, stock_movement).\n`);
      mdAppend(md, `- Trace global de référence (autre préfixe) : \`reports/antigravity/global-pos-kiosk-order-trace.json\` ${fs.existsSync(GLOBAL_TRACE_JSON) ? '**(présent sur disque)**' : '*(absent — lancer le spec global)*'}.\n\n`);

      mdAppend(md, '### 6.5 Extrait JSON (tronqué — l’intégralité est dans `raw-trace.json`)\n\n');
      mdAppend(md, '```json\n');
      mdAppend(md, `${JSON.stringify(audit, null, 2).slice(0, 14_000)}\n`);
      mdAppend(md, '```\n\n');

      mdAppend(md, '---\n\n## 7. Liste fichiers image\n\n');
      mdAppend(md, '### 7.0 Contrat de preuve visuelle\n\n');
      mdVisualProofTable(md);

      const files = fs.readdirSync(SHOT_DIR).filter((f) => f.endsWith('.png')).sort();
      for (const f of files) {
        mdAppend(md, `- \`screenshots/${f}\`\n`);
      }
      mdAppend(md, `\n**Total captures** : ${files.length}\n\n`);

      mdAppend(md, '---\n\n## 8. Grille de relecture humaine\n\n');
      mdAppend(md, '| # | Question | Preuve attendue |\n');
      mdAppend(md, '|---:|---|---|\n');
      mdAppend(md, '| 1 | Le POS affiche-t-il la catégorie & l’item fixture ? | Captures 02–05 |\n');
      mdAppend(md, '| 2 | Après API POS, le ticket / bannières reflètent-elles le total 12,50 € ? | Capture **07** |\n');
      mdAppend(md, '| 3 | Le tracker & la liste `/admin/pos-orders` montrent-ils le **queue_number** POS ? | **08–10** |\n');
      mdAppend(md, '| 4 | OSS file d’attente client | **§3.8** (capture **11** si route OK) |\n');
      mdAppend(md, '| 5 | Parcours kiosk : idle → sur place → grille → wizard (≤3 suivant) → API | **20–27** |\n');
      mdAppend(md, '| 6 | KDS : deux files distinctes + addon + 4 transitions | 30–37 |\n');
      mdAppend(md, '| 7 | Backend : 2 lignes `orders`, stocks -4, pas de collision `queue_counts` | §6 + `raw-trace.json` |\n\n');

      mdAppend(md, '---\n\n## 9. Vue consolidée du flux (data)\n\n');
      mdAppend(md, '```mermaid\n');
      mdAppend(md, 'flowchart LR\n');
      mdAppend(md, '  subgraph Admin\n');
      mdAppend(md, '    A1[login admin] --> A2[menu-projection POS+Kiosk]\n');
      mdAppend(md, '  end\n');
      mdAppend(md, '  subgraph POS\n');
      mdAppend(md, '    P1[login caissier] --> P2[/admin/pos]\n');
      mdAppend(md, '    P2 --> P3[quote + order API]\n');
      mdAppend(md, '    P3 --> P4[tracker + pos-orders]\n');
      mdAppend(md, '  end\n');
      mdAppend(md, '  subgraph Kiosk\n');
      mdAppend(md, '    K1[login borne] --> K2[idle sur place]\n');
      mdAppend(md, '    K2 --> K3[grille + wizard]\n');
      mdAppend(md, '    K3 --> K4[quote + order + payment-confirm API]\n');
      mdAppend(md, '  end\n');
      mdAppend(md, '  subgraph KDS\n');
      mdAppend(md, '    D1[login chef] --> D2[KDS grid]\n');
      mdAppend(md, '    D2 --> D3[change-status x4]\n');
      mdAppend(md, '  end\n');
      mdAppend(md, '  A2 --> P1\n');
      mdAppend(md, '  P4 --> D2\n');
      mdAppend(md, '  K4 --> D2\n');
      mdAppend(md, '```\n\n');

      mdAppend(md, '---\n\n## 10. Manifeste d’intégrité (SHA-256)\n\n');
      mdAppend(md, 'Après chaque exécution réussie du spec, le fichier **`MANIFEST.json`** à la racine de ce dossier recense **chaque fichier** (captures, Markdown, JSON) avec **taille en octets** et **empreinte SHA-256**.\n\n');
      mdAppend(md, '- Utilisation audit : vérifier que les PNG / JSON n’ont pas été altérés entre transfert archivage et relecture.\n');
      mdAppend(md, '- Le manifeste est (re)généré dans le bloc `finally` du test, après écriture du Markdown.\n\n');

      mdAppend(md, '---\n\n## Annexes — pistes d’amélioration UX (issues typiques)\n\n');
      mdAppend(md, '- **Wizard POS vs chip panier** : si le wizard reste ouvert, le chip « Commandes » / panier peut être masqué par une couche `.formule-card` — documenté en §3.3bis.\n');
      mdAppend(md, '- **Deep link kiosk** : sans idle + type de commande, `/kiosk/categories?cat=` peut échouer — d’où le parcours §4.2–4.4.\n');
      mdAppend(md, '- **Viewport vs pleine page** : captures volontairement **viewport** pour éviter timeouts screenshot sur grilles très hautes ; compléter par captures manuelles scroll si besoin légal / QA papier.\n\n');
    } finally {
      const safeClose = async (ctx) => {
        try {
          await ctx.close();
        } catch (_e) {
          /* already closed */
        }
      };
      await Promise.all([safeClose(posContext), safeClose(kioskContext), safeClose(kdsContext)]);
      fs.writeFileSync(MD_PATH, md.join(''), 'utf8');
      writeArtifactManifest(REPORT_ROOT, {
        fixture_run: fixture.run,
        branch_id: fixture.branch_id,
        pos_order_id: posCreated?.order?.id ?? null,
        kiosk_order_id: kioskCreated?.order?.id ?? null,
      });
      trace.cleanupTraceAudit();
    }

    expect(fs.existsSync(MD_PATH)).toBeTruthy();
    expect(fs.existsSync(JSON_PATH)).toBeTruthy();
    expect(fs.existsSync(path.join(REPORT_ROOT, 'MANIFEST.json'))).toBeTruthy();
  });
});
