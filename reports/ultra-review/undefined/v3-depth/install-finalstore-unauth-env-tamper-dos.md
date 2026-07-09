# VERDICT: CONFIRMED (P1)

## Claim
GET `/install/final-store` executes `finalSetup()` on an already-installed app,
rewriting `.env` (`APP_ENV=production`, `APP_DEBUG=false`); on a dev box with
`POS_SIMULATION_HARDWARE=true` the AppServiceProvider boot guard then throws a
RuntimeException => total unauthenticated DoS. GET route => no CSRF token needed.

## Independent replay (refute-by-default)

### 1. Route is GET, no auth, no CSRF
`routes/web.php:33`
```
Route::get('/final-store', [InstallerController::class, 'finalStore'])->name('finalStore');
```
Group `install` uses only `middleware(['web'])` (routes/web.php:22) — no `auth`,
no `installed` guard (contrast: `payment` group uses `middleware(['installed'])`).
GET => CSRF not enforced by VerifyCsrfToken.

### 2. The "already-installed" guard does NOT halt — PROVEN LIVE
`InstallerController::__construct` (line 28):
```php
if (file_exists(storage_path('installed'))) {
    Redirect::to(env('APP_URL'))->send();
}
```
`storage/installed` EXISTS on this box (verified). `Response::send()` flushes the
response but does NOT `exit`. Live proof against the running server:

```
$ curl -s -i http://127.0.0.1:8766/install
HTTP/1.0 302 Found
Location: http://127.0.0.1:8766
...
        Redirecting to http://127.0.0.1:8766.      <-- redirect body (from ->send())
<!DOCTYPE html> ... <title> Bienvenue                <-- installer welcome view STILL rendered
```
The controller body executes AFTER the constructor's `->send()`. Non-halting
constructor mechanism = confirmed live on `/install` (index). The identical
constructor runs for `finalStore`, so `finalStore()` body executes too.

### 3. finalStore -> finalSetup writes .env
`InstallerController::finalStore` (line 131) calls `$this->installerService->finalSetup()`.
`InstallerService::finalSetup()` (app/Services/InstallerService.php:117-123):
```php
$envService->addData([
    'APP_ENV'   => 'production',
    'APP_DEBUG' => 'false'
]);
Artisan::call('optimize:clear');
```
=> unauthenticated GET rewrites `.env` and rebuilds/cleans config cache.

### 4. Boot guard then bricks the app
`.env` currently: `APP_ENV=local`, `POS_SIMULATION_HARDWARE=true` (verified).
After the write, `APP_ENV=production`. `AppServiceProvider.php:178`:
```php
if (app()->environment('production')) {
    ...
    if ((bool) config('pos.simulation_hardware', false)) {
        throw new \RuntimeException('POS_SIMULATION_HARDWARE must be false in production ...');
```
`optimize:clear` drops the config cache so the next request re-reads `.env` =>
`environment('production')` true AND `pos.simulation_hardware` true =>
RuntimeException at boot on EVERY subsequent request => total DoS until the owner
manually edits `.env` back to `APP_ENV=local`.

## Destructive step deliberately NOT executed
The final `.env` write (the actual exploit) was NOT performed — read-only
discipline. Every non-destructive link in the chain (GET/no-CSRF, non-halting
constructor, finalSetup code path, boot-guard trigger condition) was verified
directly.

## Severity assessment
P1 upheld (not P0): V1 LOCAL mono-poste — reachability requires an attacker page
loaded in a browser ON the box (Chrome runs on the caisse/borne), triggering
`<img src="http://127.0.0.1:8766/install/final-store">` cross-site (GET, no CSRF).
Concrete outcome = full POS offline requiring manual `.env` restoration + debug
toggle + config rebuild. Not cloud-only, not by-design (the constructor guard was
INTENDED to block post-install access but is ineffective because `->send()` does
not terminate the request). Fix: `die()`/`exit` after the redirect in the guard,
or add an `installed` middleware short-circuit, or make `finalStore` idempotently
refuse when `storage/installed` exists.
```
```
