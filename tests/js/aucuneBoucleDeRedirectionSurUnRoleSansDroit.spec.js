import { describe, it, expect } from 'vitest';
import fs from 'fs';
import path from 'path';

/**
 * [ONB-06 2026-08-28] Un compte sans permission ne doit pas tourner en boucle.
 *
 * ═══ LE DÉFAUT ═══
 *
 * `handlePermissionDenied` se rabattait TOUJOURS sur `admin.dashboard`. Or cette
 * route porte `meta.permissionUrl = 'dashboard'` : un utilisateur qui ne l'a pas
 * voyait la garde se redéclencher sur sa PROPRE CIBLE, rappeler la fonction, et
 * repartir vers le tableau de bord. **Boucle infinie**, avec un toast d'erreur à
 * chaque tour.
 *
 * Ce n'est pas théorique. `RolePermissionTableSeeder` donne des permissions à Admin,
 * Branch Manager, POS Operator, Chef, Stuff et Waiter — **jamais à Delivery Boy ni
 * Customer** (aucun `givePermissionTo` pour eux). Et
 * `LeCayenneRoleLandingUrlSeeder:31` fait atterrir le Livreur sur `delivery-boys`,
 * une route qui exige la permission du même nom.
 *
 * Un livreur qui se connectait ne voyait jamais d'écran.
 *
 * ═══ POURQUOI LE CORRECTIF EST GÉNÉRAL ═══
 *
 * Donner des droits au Livreur traiterait le symptôme. La boucle reviendrait le jour
 * où quelqu'un crée un rôle vide depuis l'écran des rôles — ce que le produit permet.
 * On corrige donc la redirection elle-même : ne jamais envoyer quelqu'un vers une
 * route qu'il ne peut pas ouvrir.
 */
describe("aucune boucle de redirection sur un rôle sans droit", () => {
    const racine = process.cwd();
    const lire = (p) => fs.readFileSync(path.join(racine, p), 'utf8');

    const ROUTEUR = 'resources/js/router/index.js';
    const SEMOIR = 'database/seeders/RolePermissionTableSeeder.php';

    it('le relevé mord — sinon ce banc serait vert en ne lisant rien', () => {
        const s = lire(ROUTEUR);

        expect(s.length, 'Le routeur est vide ou introuvable.').toBeGreaterThan(5000);
        expect(s).toContain('handlePermissionDenied');
        expect(s).toContain('userHasPermission');
    });

    it("le repli ne vise le tableau de bord que si l'utilisateur peut l'ouvrir", () => {
        const s = lire(ROUTEUR);

        const debut = s.indexOf('const handlePermissionDenied');
        const fin = s.indexOf('const baseRoutes');
        const fonction = s.slice(debut, fin);

        expect(fonction.length, 'La fonction est introuvable.').toBeGreaterThan(300);

        expect(
            fonction,
            "Le repli vise `admin.dashboard` sans vérifier que l'utilisateur y a droit.\n"
            + 'Un rôle sans permission boucle : la garde se redéclenche sur sa propre cible.',
        ).toMatch(/userHasPermission\('dashboard'\)/);

        expect(
            fonction,
            "Il faut une destination TERMINALE quand le tableau de bord est hors de portée.\n"
            + "`route.exception` est dans la liste exemptée et ne porte aucune permission.",
        ).toContain("name: 'route.exception'");
    });

    it("`route.exception` est bien exemptée de garde, sinon le repli boucle aussi", () => {
        const s = lire(ROUTEUR);

        // Contrôle de périmètre : si quelqu'un donne un jour une `permissionUrl` à
        // cette route, le correctif ci-dessus redeviendrait une boucle — silencieuse.
        expect(s).toContain('"route.exception",');

        const debut = s.indexOf("path: \"/exception\"");
        const bloc = s.slice(debut, debut + 220);

        expect(
            bloc,
            "`route.exception` a reçu une `permissionUrl` : elle ne peut plus servir de\n"
            + 'destination terminale, et le repli boucle de nouveau.',
        ).not.toContain('permissionUrl');
    });

    it('le rôle Livreur reçoit toujours zéro permission — le constat reste vrai', () => {
        const semoir = lire(SEMOIR);

        // Ce banc ne DEMANDE pas qu'on donne des droits au Livreur : c'est une
        // décision produit. Il constate, pour que le jour où quelqu'un lui en donne,
        // on sache que la situation a changé — et pour que le correctif ci-dessus ne
        // soit pas retiré en croyant le problème disparu.
        expect(
            semoir,
            "Le rôle Livreur reçoit désormais des permissions : ce banc doit être relu,\n"
            + 'et la fiche de renvoi ONB-06 mise à jour.',
        ).not.toMatch(/deliveryBoy\w*->givePermissionTo|'Delivery Boy'\s*\)\s*->givePermissionTo/);
    });
});
