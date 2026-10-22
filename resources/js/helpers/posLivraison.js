// [LIVRAISON 2026-10-20] Appels serveur de la livraison depuis la caisse. Module séparé pour rester
// testable sans monter PosComponent (8 800 lignes). `http` = instance axios de l'application
// (baseURL /api, en-tête x-api-key déjà posé). Aucune règle de prix ici : le serveur décide.

const errMsg = (e, fallback) => e?.response?.data?.message || e?.message || fallback;

/** Suggestions d'adresse (Base Adresse Nationale via le serveur, route authentifiée de la caisse). */
export async function suggestAddresses(http, q) {
    const res = await http.get('/admin/pos-delivery/suggest', { params: { q } });
    return (res.data?.data || []).map((s) => ({ place_id: s.label, description: s.label, city: s.city, postal_code: s.postal_code }));
}

/** Prix, palier, estimation et créneaux pour une adresse saisie. */
export async function quoteAddress(http, q) {
    const res = await http.post('/admin/pos-delivery/quote', { q });
    return res.data;
}

/** Recherche d'un client par téléphone, sans rien créer. */
export async function lookupCustomer(http, phone) {
    const res = await http.get('/admin/pos-delivery/lookup', { params: { phone } });
    return res.data?.found ? res.data.data : null;
}

/** Retrouve ou crée le client (téléphone = clé). */
export async function ensureCustomer(http, { phone, name, email }) {
    try {
        const res = await http.post('/admin/pos-delivery/customer', { phone, name: name || null, email: email || null });
        return res.data.data;
    } catch (e) {
        throw new Error(errMsg(e, 'Impossible de créer le client.'));
    }
}

/** Enregistre l'adresse (une seule fois, géocodée par le serveur) et renvoie son prix. */
export async function saveAddress(http, { customerId, address, apartment }) {
    try {
        const res = await http.post('/admin/pos-delivery/address', { customer_id: customerId, address, apartment: apartment || null });
        return res.data.data;
    } catch (e) {
        throw new Error(errMsg(e, 'Adresse introuvable : vérifiez la rue, le code postal et la ville.'));
    }
}

export async function fetchDeliveryStatus(http) {
    const res = await http.get('/admin/pos-delivery/status');
    return Boolean(res.data?.enabled);
}

export async function setDeliveryEnabled(http, enabled) {
    const res = await http.post('/admin/pos-delivery/toggle', { enabled });
    return Boolean(res.data?.enabled);
}

export async function fetchBoard(http) {
    const res = await http.get('/admin/pos-delivery/board');
    return res.data;
}

export async function assignCourier(http, waveAt, deliveryBoyId) {
    const res = await http.post('/admin/pos-delivery/waves/assign', { wave_at: waveAt, delivery_boy_id: deliveryBoyId });
    return res.data;
}

// [VG-2026-10-21 M4-002] Une livraison « tout de suite » part à la PREMIÈRE tournée non pleine
// (DeliveryWaveService::pickAsapWave, mêmes règles que `availableWaves` qui alimente `q.waves` du devis) — parfois
// des heures plus tard ou le lendemain. La caisse le dit, avec la date dès que ce n'est pas aujourd'hui.
// `wave_at` = heure murale du restaurant « Y-m-d H:i:s » : lue telle quelle, sans conversion de fuseau.
function lireHeureMurale(waveAt) {
    const m = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/.exec(String(waveAt || ''));
    return m ? { y: +m[1], mo: +m[2], d: +m[3], hhmm: `${m[4]}:${m[5]}`, jj: m[3], mm: m[2] } : null;
}

function quandDepart(h, now) {
    const jour = new Date(h.y, h.mo - 1, h.d);
    const ecart = Math.round((jour - new Date(now.getFullYear(), now.getMonth(), now.getDate())) / 86400000);
    const date = `${jour.toLocaleDateString('fr-FR', { weekday: 'short' })} ${h.jj}/${h.mm}`;
    if (ecart === 0) return { texte: `aujourd'hui à ${h.hhmm}`, court: `aujourd'hui ${h.hhmm}` };
    if (ecart === 1) return { texte: `demain (${date}) à ${h.hhmm}`, court: `demain (${date}) ${h.hhmm}` };
    return { texte: `${date} à ${h.hhmm}`, court: `${date} ${h.hhmm}` };
}

/** Heure de départ prévue d'une livraison « tout de suite », d'après les tournées du devis serveur. */
export function describeDeparture(q, now = new Date()) {
    if (!q || q.enabled === false || !q.allowed || !Array.isArray(q.waves) || q.waves.length === 0) return '';
    const libre = q.waves.find((w) => w && !w.full);
    if (libre) {
        const h = lireHeureMurale(libre.wave_at);
        return h ? `Départ prévu ${quandDepart(h, now).texte}` : '';
    }
    const h = lireHeureMurale(q.waves[q.waves.length - 1]?.wave_at);
    return h ? `Tournées complètes jusqu'à ${quandDepart(h, now).court} : départ plus tard` : '';
}

/** Texte lisible du résultat d'un devis, pour l'écran. */
export function describeQuote(q) {
    if (!q) return '';
    if (q.enabled === false) return 'La livraison est suspendue.';
    if (!q.allowed) {
        return q.suggest_uber_eats ? 'Au-delà de 10 km : livraison impossible. Proposez Uber Eats au client.' : 'Adresse non livrable.';
    }
    const fee = Number(q.fee).toFixed(2).replace('.', ',');
    const km = q.distance_km != null ? ` · ${String(q.distance_km).replace('.', ',')} km` : '';
    const min = q.travel_minutes ? ` · ~${q.travel_minutes} min` : '';
    return `${q.address?.city || ''} · ${fee} €${km}${min}`.replace(/^ · /, '');
}
