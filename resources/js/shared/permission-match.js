/**
 * [GOAL-OPS-SWAP W1 2026-08-12] Définition UNIQUE de « cet utilisateur a-t-il
 * cette permission ? ».
 *
 * POURQUOI CE MODULE EXISTE : la question était posée à DEUX endroits, avec
 * deux implémentations séparées —
 *   · `resources/js/router/index.js`                        (garde de route)
 *   · `resources/js/components/layouts/backend/BackendMenuComponent.vue` (barre latérale)
 * Une définition dupliquée est une divergence programmée : corriger l'une
 * laisse l'autre mentir. Les deux appellent désormais ce module.
 *
 * LE DÉFAUT CORRIGÉ (mesuré en base réelle et par appel authentifié) :
 * les deux gardes ne cherchaient que sur la colonne `url`. Or
 *   · `ingredients_manage` (id 82, 83) et `catalog.compose` (id 80) ont
 *     `url = NULL` — créées par `firstOrCreate(name, guard)` sans `url`
 *     (`IngredientPermissionSeeder.php:19-22`, `ComposerPermissionsMinimalSeeder.php:17-20`) ;
 *   · `items_create` (id 37) porte `url = 'items/create'`, alors que le
 *     routeur interroge la chaîne `items_create`
 *     (`purchasingRoutes.js:15`, `itemRoutes.js:74`).
 * Aucune correspondance ⇒ repli permissif ⇒ l'entrée « Ingrédients » était
 * proposée à l'opérateur caisse et au chef, qui recevaient ensuite un
 * HTTP 403 sur `/api/admin/ingredients`. Le menu promettait ce que le
 * serveur refusait.
 *
 * CE QU'ON NE CHANGE PAS : liste VIDE / pas encore un tableau = démarrage
 * à froid. Masquer toute la barre V1 au premier paint viderait dashboard
 * et caisse avant que le store soit hydraté. Ça, on le garde ouvert.
 *
 * CE QU'ON FERME MAINTENANT : table DÉJÀ chargée, clé introuvable.
 * Avant, « État du système » n'avait pas de ligne Spatie `url` : le
 * caissier voyait le cockpit, cliquait, l'API 403. Le menu mentait.
 *
 * SÛRETÉ DE LA CORRESPONDANCE PAR `name` : vérifié sur les 86 permissions en
 * base — AUCUN `name` n'est égal au `url` d'une autre permission. `url` reste
 * prioritaire ; `name` n'intervient qu'en second recours.
 */

/**
 * Trouve la ligne de permission correspondant à la clé demandée par une route
 * ou une entrée de menu. `url` d'abord, `name` ensuite.
 *
 * @param {Array<{url?: string|null, name?: string|null, access?: boolean}>|null|undefined} permissions
 * @param {string|null|undefined} permissionKey
 * @returns {object|null} la ligne trouvée, ou `null`
 */
export function resolvePermissionEntry(permissions, permissionKey) {
    if (!permissionKey) return null;
    if (!Array.isArray(permissions) || permissions.length === 0) return null;

    const parUrl = permissions.find((p) => p && p.url === permissionKey);
    if (parUrl) return parUrl;

    return permissions.find((p) => p && p.name === permissionKey) || null;
}

/**
 * Verdict d'accès pour une clé de permission.
 *
 * @param {Array|null|undefined} permissions liste livrée à la connexion
 * @param {string|null|undefined} permissionKey clé exigée par la route/le menu
 * @returns {boolean}
 */
export function hasPermissionAccess(permissions, permissionKey) {
    // Aucune clé exigée : la route est publique côté SPA.
    if (!permissionKey) return true;

    // Liste pas encore hydratée (démarrage à froid) : ne pas masquer l'interface.
    if (!Array.isArray(permissions) || permissions.length === 0) return true;

    const entry = resolvePermissionEntry(permissions, permissionKey);

    // Table hydratée, clé introuvable : refuser. Le serveur 403 n'excuse
    // plus un lien que le commerçant ne peut pas utiliser.
    if (!entry) return false;

    return entry.access === true;
}

export default hasPermissionAccess;
