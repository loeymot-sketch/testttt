// FoodKing — Playwright Config (Phase 3)
// Utilisé par : post-execute.sh (CLI) et Playwright MCP (outils Cursor)
// Base URL : http://localhost:8000
//
// Serveur Laravel : par défaut Playwright lance `php artisan serve` si aucun
// processus ne répond déjà sur l’URL (reuseExistingServer: true) — pratique en
// local / agents. CI (workflow qui démarre déjà le serveur) réutilise l’existant.
// Désactiver : PLAYWRIGHT_NO_WEB_SERVER=1

const { defineConfig, devices } = require('@playwright/test');

const baseURL = process.env.PLAYWRIGHT_BASE_URL || 'http://localhost:8000';

/** URL utilisée pour la sonde « serveur prêt » (webServer.url) */
function webServerProbeUrl() {
  try {
    const u = new URL(baseURL);
    if (u.hostname === 'localhost') {
      u.hostname = '127.0.0.1';
    }
    return u.toString().replace(/\/$/, '');
  } catch (_e) {
    return 'http://127.0.0.1:8000';
  }
}

function buildWebServer() {
  if (process.env.PLAYWRIGHT_NO_WEB_SERVER === '1') {
    return undefined;
  }
  return {
    command:
      process.env.PLAYWRIGHT_WEB_SERVER_CMD || 'php artisan serve --host=127.0.0.1 --port=8000',
    url: webServerProbeUrl(),
    reuseExistingServer: true,
    timeout: 180_000,
    stdout: 'pipe',
    stderr: 'pipe',
  };
}

const webServer = buildWebServer();

module.exports = defineConfig({
  ...(webServer ? { webServer } : {}),
  testDir: './tests',
  globalSetup: require.resolve('./tests/Playwright/global-setup.js'),
  testMatch: [
    'e2e/**/*.spec.{js,ts}',
    'playwright/**/*.spec.{js,ts}',
    'Playwright/**/*.spec.{js,ts}',
  ],
  // Un seul worker : les specs déclenchent POST /api/auth/login en rafale ; en parallèle
  // (défaut ~5 workers) on déclenche throttle:login-lockout / collisions côté IP (429 → SPA reste sur /login).
  workers: 1,
  // Mega-specs (central-management, c3, …) dépassent 2 min ; les describe.configure locaux peuvent être plus bas.
  timeout: 600_000,
  retries: 1,
  reporter: [
    ['list'],
    ['json', { outputFile: 'reports/antigravity/playwright-latest.json' }],
  ],
  use: {
    baseURL,
    headless: true,
    /*
     * [AUDIT-SUPERVISEUR 2026-08-25 · AB-013] LE HARNAIS DOIT PARLER LA LANGUE DE LA CAISSE.
     *
     * Sans ces deux lignes, le navigateur de test héritait de la locale de la MACHINE. Un
     * champ `datetime-local` s'affichait alors « mm/dd/yyyy, --:-- -- » — format américain
     * avec AM/PM — sur une caisse dont la locale est immuable (ADR-007, FR).
     *
     * Le superviseur adverse a désamorcé ce piège au lieu d'ouvrir un faux P1 « date au
     * format américain en production ». Mais le vrai coût est ailleurs : tant que la locale
     * n'est pas fixée, AUCUNE conclusion de cet audit sur le rendu des dates, des heures ou
     * des nombres n'est fiable — ni dans un sens ni dans l'autre. Une capture peut inventer
     * un défaut qui n'existe pas, ou masquer un défaut réel de formatage français.
     *
     * On épingle donc ce que la production voit réellement : français, heure de Paris.
     */
    locale: 'fr-FR',
    timezoneId: 'Europe/Paris',
    /*
     * [2026-08-26] `locale` ne suffit PAS pour les champs de FORMULAIRE NATIFS.
     *
     * Vérifié après coup : avec `locale: 'fr-FR'` seul, `navigator.language` vaut bien
     * « fr-FR », `Intl` résout Europe/Paris et `toLocaleDateString()` rend « 25/08/2026 ».
     * Toutes les dates rendues par du JavaScript sont donc correctes.
     *
     * Mais un `<input type="datetime-local">` continuait d'afficher « mm/dd/yyyy, --:-- -- » :
     * Chromium dessine ses contrôles natifs d'après la langue de son INTERFACE, pas d'après
     * `navigator.language`. Il faut le lui dire au lancement.
     *
     * Sans cette ligne, chaque campagne exhibe un champ de date au format américain sur une
     * caisse française — et quiconque relit les captures est à un pas d'ouvrir un faux P1.
     */
    launchOptions: {
        args: ['--lang=fr-FR'],
    },
    ...(process.env.PLAYWRIGHT_CHANNEL ? { channel: process.env.PLAYWRIGHT_CHANNEL } : {}),
    screenshot: 'only-on-failure',
    video: 'off',
    trace: 'on-first-retry',
  },
  projects: [
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'] },
    },
  ],
});
