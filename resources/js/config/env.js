/**
 * Priorité : window.foodkingConfig (injecté par master.blade.php depuis config())
 * puis variables Mix au build, puis origine du navigateur.
 * Sans ça, un vieux public/js/app.js ou php artisan config:cache casse le login (x-api-key / baseURL).
 */
const originFallback =
    typeof window !== 'undefined' && window.location
        ? `${window.location.protocol}//${window.location.host}`.replace(/\/$/, '')
        : '';

const runtime =
    typeof window !== 'undefined' && window.foodkingConfig && typeof window.foodkingConfig === 'object'
        ? window.foodkingConfig
        : null;

/** APP_URL=localhost mais navigateur sur 127.0.0.1 → éviter requêtes cross-origin / mauvaise base */
function preferBrowserOriginWhenLoopbackMismatch(cfgBase) {
    if (typeof window === 'undefined' || !window.location || !cfgBase) {
        return cfgBase || originFallback;
    }
    const pageOrigin = originFallback;
    try {
        const a = new URL(cfgBase);
        const b = new URL(pageOrigin);
        const loop = (h) => ['localhost', '127.0.0.1', '[::1]'].includes(h);
        if (loop(a.hostname) && loop(b.hostname) && a.hostname !== b.hostname) {
            const pa = a.port || (a.protocol === 'https:' ? '443' : '80');
            const pb = b.port || (b.protocol === 'https:' ? '443' : '80');
            if (String(pa) === String(pb)) {
                return pageOrigin;
            }
        }
    } catch (e) {
        /* ignore */
    }
    return cfgBase;
}

let baseUrl = (runtime && runtime.baseUrl) ? runtime.baseUrl : (process.env.MIX_HOST || originFallback);
baseUrl = preferBrowserOriginWhenLoopbackMismatch(baseUrl);

const ENV = {
    API_URL: baseUrl,
    API_KEY: (runtime && typeof runtime.apiKey === 'string') ? runtime.apiKey : (process.env.MIX_API_KEY || ''),
    GOOGLE_MAP_KEY:
        (runtime && runtime.googleMapKey) ||
        process.env.MIX_GOOGLE_MAP_KEY ||
        '',
    DEMO:
        runtime && typeof runtime.demo !== 'undefined'
            ? (runtime.demo ? 'true' : 'false')
            : process.env.MIX_DEMO,
};
export default ENV;
