/**
 * [GOAL CAISSE CONTRÔLE 2026-09-02] Le canal d'une commande — identification PARTAGÉE.
 *
 * POURQUOI CE MODULE EXISTE
 * -------------------------
 * `sourceOf()` / `sourceIcon()` étaient des `methods:` de `PosOrdersTrackerComponent.vue`, et les
 * six couleurs de canal vivaient dans son `<style scoped>` (`:3193-3201`) — donc inatteignables
 * depuis tout autre composant. Le tiroir de contrôle de la caisse a besoin des MÊMES : deux jeux de
 * couleurs de canal qui cohabitent détruisent la seule chose qu'un code couleur doit garantir.
 *
 * L'heuristique elle-même a une histoire qu'il ne faut pas perdre : trois canaux réels (téléphone,
 * plateforme, livraison) tombaient tous dans « Caisse » jusqu'au 2026-08-24, et `source_surface='web'`
 * était classé « Caisse » à tort jusqu'au 2026-07-20. Ces cas sont verrouillés par
 * `tests/js/canalCommandeModule.spec.js` — ce sont des régressions déjà payées une fois.
 *
 * Les classes CSS correspondantes vivent dans `resources/css/pos-v5.css` (§ « Canaux de commande »),
 * chargé globalement par `app.css`, et non plus dans un `<style scoped>`.
 */

/** Les six canaux reconnus, dans l'ordre d'apparition en salle. */
export const CANAUX = ['pos', 'kiosk', 'online', 'phone', 'platform', 'delivery'];

const SURFACES_PLATEFORME = new Set([
    'uber_eats', 'uber', 'ubereats', 'deliveroo', 'just_eat', 'justeat', 'platform',
]);

/**
 * Canal d'une commande, d'après `source_surface` puis, à défaut, `order_type`.
 * Miroir exact de `PosOrdersTrackerComponent.sourceOf()`, qui délègue désormais ici.
 */
export function canalDe(commande) {
    if (!commande) return 'pos';
    const surface = String(commande.source_surface || commande._origin || '').toLowerCase();

    if (surface === 'kiosk') return 'kiosk';
    if (surface === 'pos') return 'pos';
    if (surface === 'online') return 'online';
    // [WEB-TRACKER-VISIBILITY 2026-07-20] `source_surface='web'` (site client) = canal en ligne.
    // Avant : non reconnu → retombait sur l'heuristique `order_type` → classé « caisse » à tort.
    if (surface === 'web') return 'online';
    // [GOAL-CAISSE-VISION 2026-08-24] Trois canaux réels tombaient tous dans « Caisse ».
    // TÉLÉPHONE : le client n'est PAS là — il faut pouvoir l'appeler, et il viendra payer au
    // comptoir. Le confondre avec une vente au comptoir, c'est confondre deux situations opposées.
    // PLATEFORME : commission 30-35 %, ticket promo dédié. LIVRAISON : la commande part.
    if (surface === 'phone') return 'phone';
    if (SURFACES_PLATEFORME.has(surface)) return 'platform';
    if (surface === 'delivery') return 'delivery';

    const type = parseInt(commande.order_type, 10);
    if (Number.isFinite(type)) {
        if (type === 17 || type === 18) return 'kiosk';
        if (type === 15 || type === 20) return 'pos';
    }
    return 'pos';
}

/**
 * Un pictogramme par canal. La couleur n'est JAMAIS seule porteuse d'information
 * (WCAG 1.4.1) : chaque canal a un fond, un liseré, une forme d'emoji distincte et un nom
 * accessible en texte.
 */
export function iconeCanal(canal) {
    switch (canal) {
        case 'kiosk': return '🖥️';
        case 'online': return '🌐';
        case 'phone': return '📞';
        case 'platform': return '🛵';
        case 'delivery': return '🚗';
        default: return '🛒';
    }
}

/** Classe CSS partagée du canal (définie dans `resources/css/pos-v5.css`). */
export function classeCanal(canal) {
    return `pos-canal pos-canal--${CANAUX.includes(canal) ? canal : 'pos'}`;
}
