# Playwright E2E Test Suite — FoodKing V1

## 5 Critical Flows

| # | Spec | Flow |
|---|---|---|
| 01 | auth-refresh | Login → F5 → session preserved |
| 02 | pos-cash | POS login → surface loaded → order flow |
| 03 | kiosk-wizard | Kiosk page → categories → items → checkout |
| 04 | kds-status | Chef login → KDS surface → order transitions |
| 05 | pos-card | POS login → card payment flow |

## Local Setup

### Prerequisites
- PHP 8.2+, Node 18+, MySQL, Redis
- Laravel app seeded: `php artisan migrate:fresh --seed`
- Frontend built: `npm run prod`
- Playwright installed: `npx playwright install chromium`

### Run
```bash
# Start server
php artisan serve &

# Run all tests
npx playwright test

# Run specific flow
npx playwright test tests/e2e/02-pos-cash.spec.js

# Debug mode (headed browser)
npx playwright test --headed --debug
```

### Configuration
`playwright.config.js` — baseURL: `http://localhost:8000`

## Debugging a failing test

1. Run headed: `npx playwright test --headed tests/e2e/02-pos-cash.spec.js`
2. Use trace viewer: `npx playwright show-trace test-results/*/trace.zip`
3. Check screenshots in `test-results/`
4. Check `reports/antigravity/playwright-latest.json` for structured results

## CI
GitHub Actions workflow: `.github/workflows/playwright.yml`
Runs on every PR to main/develop. Blocks merge if red.

## Anti-flakiness rules
- No bare `waitForTimeout` without justification
- Use `expect().toBeVisible()` or `toHaveText()` with explicit timeouts
- Never `.skip` a test — fix the root cause
- Target 10 consecutive green runs before declaring stable
