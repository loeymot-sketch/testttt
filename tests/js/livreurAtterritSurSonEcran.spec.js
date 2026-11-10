import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

/**
 * [GOAL LIVRAISON-TICKETS 2026-11-10 · T-1.4.2] Un livreur qui se connecte arrive sur SON écran.
 *
 * Avant : `LeCayenneRoleLandingUrlSeeder` l'envoyait sur `delivery-boys` (la liste ADMIN des livreurs), route qui exige
 * la permission du même nom — que le rôle n'a pas (aucune permission Spatie). Il finissait sur la page « exception ».
 * Après : landing `livreur` → LoginComponent pousse `/admin/livreur` → route SPA `admin.livreur` → sortie dure `/livreur`
 * (page autonome, connexion propre). Trois morceaux, un seul contrat : ce test les relit ensemble.
 */
const lire = (p) => readFileSync(resolve(process.cwd(), p), 'utf8');

describe('livreur : atterrissage sur /livreur', () => {
    it('le seeder envoie le rôle Livreur vers `livreur`, plus vers la liste admin', () => {
        const seeder = lire('database/seeders/LeCayenneRoleLandingUrlSeeder.php');
        expect(seeder).toMatch(/'Delivery Boy'\s*=>\s*'livreur'/);
        expect(seeder).not.toMatch(/'Delivery Boy'\s*=>\s*'delivery-boys'/);
    });

    it('la route SPA /admin/livreur existe et sort du SPA vers /livreur sans rien rendre', () => {
        const router = lire('resources/js/router/index.js');
        expect(router).toContain('path: "/admin/livreur"');
        expect(router).toContain('name: "admin.livreur"');
        expect(router).toContain('window.location.assign("/livreur")');
        // La garde interrompt la navigation : aucun composant admin n'est monté pour un livreur.
        expect(router).toMatch(/beforeEnter:\s*\(\)\s*=>\s*\{\s*window\.location\.assign\("\/livreur"\);\s*return false;/);
    });

    it('la connexion pousse `/admin/<landing>` tel quel (c\'est la route ci-dessus qui fait la sortie)', () => {
        const login = lire('resources/js/components/frontend/auth/LoginComponent.vue');
        expect(login).toContain("router.push('/admin/' + defaultPermission.url)");
    });
});
