/**
 * [GOAL-OPS-SWAP W1 · constat PERMISSION-URL-DESACCORDEE 2026-08-12]
 *
 * DÉFAUT MESURÉ EN BASE ET PAR APPEL RÉEL :
 *   - `ingredients_manage` et `catalog.compose` ont `url = NULL` (permissions
 *     id=80..83, créées par `firstOrCreate(name, guard)` sans `url`) ;
 *   - `items_create` porte `url = 'items/create'`, alors que le routeur
 *     interroge la chaîne `items_create`.
 *
 * Les deux gardes (routeur `router/index.js` et barre latérale
 * `BackendMenuComponent.vue`) cherchent UNIQUEMENT sur `url`. Sans
 * correspondance, elles laissaient passer. Résultat vécu : l'entrée
 * « Ingrédients » s'affiche pour l'opérateur caisse ET le chef, qui
 * reçoivent ensuite un HTTP 403. Le menu promet ce que le serveur refuse.
 *
 * 2026-08-29 : clé inconnue APRÈS hydratation = refus (plus de cockpit
 * fantôme). Liste encore vide = démarrage à froid, on laisse passer.
 *
 * Vérifié avant d'écrire ce banc : AUCUNE permission ne porte un `name` égal
 * au `url` d'une autre permission (0 collision sur les 86 lignes). La
 * correspondance par `name` en second recours est donc sans ambiguïté.
 */
import { describe, expect, it } from 'vitest';
import { resolvePermissionEntry, hasPermissionAccess } from '../../resources/js/shared/permission-match';

/** Extrait fidèle de la charge réellement livrée à la connexion (86 lignes). */
const PERMISSIONS_REELLES = [
    { id: 1, title: 'Dashboard', name: 'dashboard', url: 'pos', access: true },
    { id: 24, title: 'KDS', name: 'kitchen-display-system', url: 'kitchen-display-system', access: true },
    { id: 37, title: 'Créer article', name: 'items_create', url: 'items/create', access: false },
    { id: 80, title: '', name: 'catalog.compose', url: null, access: false },
    { id: 82, title: '', name: 'ingredients_manage', url: null, access: false },
    { id: 83, title: '', name: 'ingredients_manage', url: null, access: false },
    { id: 90, title: 'Articles', name: 'items', url: 'items', access: true },
];

describe('résolution de permission (garde routeur + barre latérale)', () => {
    it('résout par `url` quand la colonne est renseignée', () => {
        expect(resolvePermissionEntry(PERMISSIONS_REELLES, 'items')?.id).toBe(90);
    });

    it('résout `ingredients_manage` par `name` alors que son `url` est NULL', () => {
        const entry = resolvePermissionEntry(PERMISSIONS_REELLES, 'ingredients_manage');
        expect(entry).toBeTruthy();
        expect(entry.id).toBe(82);
    });

    it('résout `catalog.compose` par `name` alors que son `url` est NULL', () => {
        expect(resolvePermissionEntry(PERMISSIONS_REELLES, 'catalog.compose')?.id).toBe(80);
    });

    it('résout `items_create` par `name` alors que son `url` vaut `items/create`', () => {
        expect(resolvePermissionEntry(PERMISSIONS_REELLES, 'items_create')?.id).toBe(37);
    });

    it('REFUSE l’accès quand la permission est trouvée et non accordée', () => {
        // Le cœur du défaut : l'opérateur caisse voyait « Ingrédients » puis
        // prenait un 403. Une fois résolue, la permission doit le refuser AVANT.
        expect(hasPermissionAccess(PERMISSIONS_REELLES, 'ingredients_manage')).toBe(false);
        expect(hasPermissionAccess(PERMISSIONS_REELLES, 'items_create')).toBe(false);
    });

    it('ACCORDE l’accès quand la permission est trouvée et accordée', () => {
        expect(hasPermissionAccess(PERMISSIONS_REELLES, 'items')).toBe(true);
        expect(hasPermissionAccess(PERMISSIONS_REELLES, 'kitchen-display-system')).toBe(true);
    });

    it('REFUSE une clé inconnue une fois la table hydratée', () => {
        // Avant : le caissier voyait « État du système » (pas de ligne Spatie
        // url=observability/system) puis prenait un 403. Menu menteur.
        expect(hasPermissionAccess(PERMISSIONS_REELLES, 'clef-jamais-vue')).toBe(false);
        expect(hasPermissionAccess(PERMISSIONS_REELLES, 'observability/system')).toBe(false);
    });

    it('laisse passer quand aucune permission n’est encore chargée (démarrage à froid)', () => {
        expect(hasPermissionAccess([], 'items')).toBe(true);
        expect(hasPermissionAccess(null, 'items')).toBe(true);
        expect(hasPermissionAccess({}, 'observability/system')).toBe(true);
    });

    it('laisse passer quand aucune clé n’est exigée par la route', () => {
        expect(hasPermissionAccess(PERMISSIONS_REELLES, '')).toBe(true);
        expect(hasPermissionAccess(PERMISSIONS_REELLES, null)).toBe(true);
    });

    it('ne confond jamais `url` d’une ligne avec `name` d’une autre', () => {
        // `dashboard` porte url='pos'. Interroger 'pos' doit rendre la ligne
        // dashboard (correspondance url, prioritaire), pas autre chose.
        expect(resolvePermissionEntry(PERMISSIONS_REELLES, 'pos')?.name).toBe('dashboard');
    });
});
