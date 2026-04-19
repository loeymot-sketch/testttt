// FoodKing — Playwright Config (Phase 3)
// Utilisé par : post-execute.sh (CLI) et Playwright MCP (outils Cursor)
// Base URL : http://localhost:8000

const { defineConfig, devices } = require('@playwright/test');

module.exports = defineConfig({
  testDir: './tests/e2e',
  // Un seul worker : les specs déclenchent POST /api/auth/login en rafale ; en parallèle
  // (défaut ~5 workers) on déclenche throttle:login-lockout / collisions côté IP (429 → SPA reste sur /login).
  workers: 1,
  timeout: 30_000,
  retries: 1,
  reporter: [
    ['list'],
    ['json', { outputFile: 'reports/antigravity/playwright-latest.json' }],
  ],
  use: {
    baseURL: process.env.PLAYWRIGHT_BASE_URL || 'http://localhost:8000',
    headless: true,
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
