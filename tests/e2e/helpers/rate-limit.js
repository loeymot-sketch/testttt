const { execFileSync } = require('child_process');
const path = require('path');

const repoRoot = path.resolve(__dirname, '../../..');

function clearFoodKingRateLimits() {
  execFileSync('php', ['artisan', 'tinker', '--execute', `
    $limiter = app(\\Illuminate\\Cache\\RateLimiter::class);
    $ids = \\App\\Models\\User::whereIn('email', [
      'pos@lecayenne.fr',
      'chef@lecayenne.fr',
      'admin@lecayenne.fr',
    ])->pluck('id')->map(fn($id) => (string) $id)->all();
    $keys = array_unique(array_merge($ids, [
      '127.0.0.1',
      '::1',
      'localhost',
      'pos@lecayenne.fr|127.0.0.1',
      'chef@lecayenne.fr|127.0.0.1',
      'admin@lecayenne.fr|127.0.0.1',
      // [test-e2e fix A-007 round-1 2026-05-21] macOS Chromium often
      // resolves localhost to ::1 (IPv6); without these the login-lockout
      // limiter accumulates and the 4th sequential test 429s silently.
      'pos@lecayenne.fr|::1',
      'chef@lecayenne.fr|::1',
      'admin@lecayenne.fr|::1',
      // [iter15-mega-fix D-001 2026-05-10] Kiosk login limiter is keyed by
      // 'kiosk:<lower(username)>|<ip>' (RouteServiceProvider::kiosk-login).
      // Without these keys an aborted Wave-C run leaves the kiosk-machine
      // bucket full and Wave-D inherits the 429 — defeating the suite reset.
      'kiosk:kiosk-lecayenne|127.0.0.1',
      'kiosk:kiosk-lecayenne|::1',
    ]));
    foreach (['api', 'admin-mutation', 'pos-quote', 'pos-order-create', 'pos-order-update', 'login-lockout', 'kiosk-login', 'kiosk-orders', 'kiosk-menu'] as $name) {
      foreach ($keys as $key) {
        $limiter->clear(md5($name.$key));
      }
    }
    echo 'ok';
  `], {
    cwd: repoRoot,
    encoding: 'utf8',
    stdio: ['ignore', 'pipe', 'pipe'],
  });
}

module.exports = { clearFoodKingRateLimits };
