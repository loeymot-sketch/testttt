# BRIEF COMMUN — reconnaissance web réelle « onboarding commerçant » (2026-08-26)

> Note Z2 : ce fichier avait été rédigé par l'orchestrateur mais son écriture avait été refusée par le
> garde-fou worktree ; il est recréé ici à l'identique (contenu extrait de la commande d'origine) pour que
> les autres zones puissent le lire.

Objectif mère : rendre FoodKing livrable à un NOUVEAU commerçant (pas Le Cayenne) qui reçoit une
installation vierge et doit pouvoir TOUT régler lui-même depuis le Dashboard, sans développeur.

## Cible
- `http://127.0.0.1:8766` = arbre principal `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt`
  (HEAD `43b120c7d` + modifications non commitées). ⛔ JAMAIS `:8000` (autre worktree, 15 356 lignes d'écart).
- Base locale `foodking_e2e`, `APP_ENV=local` : tous les volumes mesurés sont LOCAUX.
- Comptes : `admin@lecayenne.fr` / `123456` (Admin) · `pos@lecayenne.fr` / `123456` (POS Operator) ·
  `chef@lecayenne.fr` / `123456` (Chef). Formulaire `/login` : `#formEmail`, `#formPassword`, submit → `/admin/dashboard`.
- API : `POST /api/auth/login` + en-tête `X-API-KEY` (= `MIX_API_KEY` du `.env` principal) → 201 `{token,…}` ;
  puis `Authorization: Bearer <token>` + `X-API-KEY` sur `/api/admin/**`.

## Outils (depuis le worktree, chemins absolus, jamais `cd` vers l'arbre principal)
- Playwright : `require('/Users/…/testttt/node_modules/playwright')`, headless, 1366×768 (+1024×768, 768×1024 si utile),
  timeouts 60 s, `waitUntil:'domcontentloaded'` puis attentes explicites, 1 page à la fois. Capturer console + réseau ≥ 400.
- DB lecture seule : `php /Users/…/testttt/artisan tinker --execute='…'` (SELECT uniquement).
- Captures : `reports/audit/onboarding-commercant-2026-08-26/recon/screens/Z<n>/NN-nom.png`, ≤ 12, chacune LUE avant d'être citée.

## Discipline de sécurité (non négociable)
- Lecture-surtout. CRUD uniquement sur des entités créées par l'agent, préfixe `AUDIT-ONB-Z<n> `, supprimées en fin (preuve DB).
- Réglages : tester la validation avec des valeurs invalides SANS enregistrer ; si enregistrement valide → restaurer la valeur exacte.
- ⛔ Interdit : commandes (créer/payer/annuler) · sessions de caisse · Z/X en écriture · supprimer/modifier une donnée existante ·
  pages Entreprise/Site (écrivent `.env`) en écriture · push/mail/SMS réels · imprimantes/TPE/bornes existants ·
  SQL en écriture · `composer dump-autoload` · `php artisan test`.

## Instrument avant produit (CLAUDE.md §3ter)
Chaque P0/P1 exige DEUX moyens indépendants (DOM + API/DB, ou capture + console/réseau). Produit absent du menu ≠ bug de recherche
(`SELECT name FROM items WHERE deleted_at IS NULL AND status=5`). Lenteur ≠ casse. 403 pour un rôle inférieur = succès.
Le 13/08 : 70/71 pages admin prouvées « ouvrables » — on ne recherche pas « la page s'ouvre », on cherche « le commerçant
peut-il vraiment tout régler, et que se passe-t-il aux bords (annulation, rechargement, effet sur borne/caisse/KDS) ».

## Contrat de constat
```
[P0|P1|P2|P3] <fichier>:<ligne> — <titre>
  reproduction : <clics exacts ou commande>
  preuve       : <capture lue | erreur console | réponse API | requête DB>
  impact commerçant : <une phrase, point de vue d'un nouveau restaurant>
  recommandation : <correctif de portée minimale>
```
P0 bloque un nouveau commerçant ou corrompt/expose des données · P1 exige un développeur pour une opération courante ou
incohérence silencieuse · P2 friction/UX · P3 cosmétique. Un P0/P1 sans `file:line` vérifié ET sans reproduction est REJETÉ.

## Livrable par zone : `recon/Z<n>_<slug>.md` (900-1500 mots)
1. Périmètre parcouru (URL réellement visitées + état) · 2. CE QUI MARCHE (preuves) · 3. CONSTATS (P0→P3) ·
4. ANGLES MORTS d'un nouveau commerçant · 5. « CAYENNE » EN DUR vu à l'écran · 6. QUESTIONS PROPRIÉTAIRE ·
7. NETTOYAGE effectué (preuve DB) · 8. Captures (fichier + une ligne d'analyse).
