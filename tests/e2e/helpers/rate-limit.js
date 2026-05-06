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
    ]));
    foreach (['api', 'admin-mutation', 'pos-quote', 'pos-order-create', 'pos-order-update', 'login-lockout'] as $name) {
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
