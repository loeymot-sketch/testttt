/**
 * [Root cause 2026-09-24, owner : "la caisse sur le PC de la caisse ça se met
 * pas à jour tout le temps... lorsque je l'ouvre sur un autre PC, je trouve
 * une autre interface... faudrait vraiment mettre à jour le cache"]
 *
 * Confirmé par audit de code : AUCUN mécanisme de détection de nouvelle
 * version n'existait. Un onglet caisse laissé ouvert depuis avant un
 * déploiement continue de faire tourner l'ANCIEN bundle JS indéfiniment —
 * jusqu'à un rechargement manuel. Deux PC ouverts à des moments différents
 * peuvent donc légitimement tourner deux versions différentes du code, avec
 * des comportements différents, sans qu'aucun signal ne le dise.
 *
 * Ce module compare périodiquement le VRAI mix-manifest.json servi par le
 * serveur (fichier statique, jamais mis en cache navigateur grâce au
 * cache-busting de l'URL) à celui capturé au chargement de CET onglet. En
 * cas de différence (un déploiement a eu lieu depuis), affiche un bandeau
 * discret et JAMAIS intrusif — jamais de rechargement forcé qui couperait
 * une vente en cours, seulement un bouton que le caissier clique entre deux
 * clients.
 */
const CHECK_INTERVAL_MS = 3 * 60 * 1000; // 3 min — assez réactif, jamais bavard.
let _bannerShown = false;
let _baselineManifest = null;

async function fetchManifest() {
    try {
        const res = await fetch(`/mix-manifest.json?_=${Date.now()}`, {
            cache: 'no-store',
            headers: { Accept: 'application/json' },
        });
        if (!res.ok) return null;
        return await res.text();
    } catch {
        return null;
    }
}

function showBanner() {
    if (_bannerShown || typeof document === 'undefined') return;
    _bannerShown = true;

    const banner = document.createElement('div');
    banner.setAttribute('role', 'status');
    banner.setAttribute('data-testid', 'new-version-banner');
    banner.style.cssText = [
        'position:fixed', 'left:50%', 'bottom:16px', 'transform:translateX(-50%)',
        'z-index:2147483000', 'background:#1a1a1a', 'color:#fff',
        'display:flex', 'align-items:center', 'gap:12px',
        'padding:10px 16px', 'border-radius:10px', 'box-shadow:0 6px 20px rgba(0,0,0,0.3)',
        'font-family:system-ui,-apple-system,sans-serif', 'font-size:14px', 'font-weight:600',
    ].join(';');

    const label = document.createElement('span');
    label.textContent = '🔄 Nouvelle version disponible';
    banner.appendChild(label);

    const btn = document.createElement('button');
    btn.type = 'button';
    btn.textContent = 'Rafraîchir';
    btn.style.cssText = [
        'background:#F4501E', 'color:#fff', 'border:none', 'border-radius:6px',
        'padding:6px 12px', 'font-weight:700', 'cursor:pointer',
    ].join(';');
    btn.addEventListener('click', () => window.location.reload());
    banner.appendChild(btn);

    document.body.appendChild(banner);
}

/**
 * Démarre la surveillance. Best-effort total : une erreur réseau/serveur ne
 * doit jamais interrompre la caisse — au pire, le bandeau n'apparaît pas.
 */
export async function startNewVersionWatcher() {
    if (typeof window === 'undefined' || typeof fetch === 'undefined') return;

    _baselineManifest = await fetchManifest();
    if (_baselineManifest === null) return; // pas de base fiable → ne jamais alarmer à tort.

    setInterval(async () => {
        if (_bannerShown) return;
        const current = await fetchManifest();
        if (current !== null && current !== _baselineManifest) {
            showBanner();
        }
    }, CHECK_INTERVAL_MS);
}
