# PROMPTS DE LANCEMENT — 14 blocs `/goal` (< 4 000 caractères chacun)

> **Une session fraîche par GOAL.** Vous ouvrez une nouvelle session Claude Code sur l'arbre principal
> `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt` et vous collez **UN bloc**. Rien d'autre : toute la
> mémoire de la session est dédiée à ce seul GOAL.
>
> Le bloc est court parce qu'il ne porte pas la discipline — il **oblige** la session à lire trois fichiers qui
> portent tout le détail, à les **réciter** avant de commencer, puis à les appliquer seule :
> 1. `reports/audit/onboarding-commercant-2026-08-26/PROTOCOLE_SESSION.md` — **les règles** (pré-vol en 12 étapes,
>    voies et collisions, base et tests, discipline, preuves web, convergence, gates, git, interdits). Identique aux 14.
> 2. `reports/audit/onboarding-commercant-2026-08-26/MISSION_ONBnn_*.md` — **la mission** (état des lieux daté, journal).
> 3. `plans/GOAL_ONBnn_*_2026-08-26.md` — **le plan** (sous-systèmes, tâches, acceptations, vagues, gates).
>
> **Pré-requis unique** : `git merge goal/onboarding-commercant-2026-08-26`.
> **Ordre** — vague A : 01, 02, 05, 06, 07, 08, 09, 10 en parallèle (+ 11 et 13 en audit) · vague B : 03 puis 04, et
> les corrections de 11/13 · vague C : 12 · vague D : 14.

---

## ONB-01 · port 8801 · vague A

```
/goal Tu suis avec précision cette mission : reports/audit/onboarding-commercant-2026-08-26/MISSION_ONB01_IDENTITE_ETABLISSEMENT.md
le plan : plans/GOAL_ONB01_IDENTITE_ETABLISSEMENT_2026-08-26.md
et les règles — toutes, sans exception — depuis : reports/audit/onboarding-commercant-2026-08-26/PROTOCOLE_SESSION.md

Lis ces TROIS fichiers EN ENTIER avant d'écrire une ligne de code. Si l'un manque : ARRÊTE, il faut d'abord
`git merge goal/onboarding-commercant-2026-08-26`. Puis récite-moi en dix lignes ce que tu as mémorisé du protocole :
port, worktree, branche, ta voie, tes cinq interdits, la règle de convergence, la règle des gates, la règle de la base
partagée, le format de compte rendu, ton premier geste. Si tu ne peux pas le citer sans relire, relis. Ensuite tu
l'appliques seul, sans rappel : le protocole prime sur le GOAL pour tout ce qui touche à l'instrument.

Pré-vol §3 du protocole (12 étapes, port 8801), puis les vagues §X du GOAL, chaque tâche par ultra-audit-profond.

TU TOURNES EN BOUCLE test-e2e : navigateur réel sur 8801, captures LUES et analysées, console + réseau joints à
chaque capture, deux moyens indépendants pour tout P0/P1, et tu reboucles jusqu'à DEUX CYCLES CONSÉCUTIFS aux
constats IDENTIQUES avec P0+P1 = 0. Un cycle vert isolé ne vaut rien.

TU DÉPLOIES DES AGENTS ADVERSAIRES à chaque tâche, lecture seule, dans UN SEUL message :
— technique : Architecte (cohérence, dépendances) · DBA (schéma, N+1, isolation) · Sécurité (authz, injection, secrets)
— interface : QA visuel (capture, mesure) puis ROUGE visuel qui CONTESTE ses captures indépendamment
— expérience : UX/A11y (WCAG, clavier, 1366/1024/768) · Psychologie commerçant (vocabulaire, charge, peur de casser)
— puis ROUGE, dont le seul but est de RÉFUTER ton correctif, après implémentation et AVANT tout « c'est fini »
— puis le Jalonneur : les 6 points de contrôle du §7 ; un seul « non » et la vague n'est pas close.
Un seul implémenteur à la fois. Trois boucles de soin maximum, puis tu STOPPES et tu me remontes quatre options.

Tu ne t'arrêtes QUE sur un gate propriétaire ou à la 3e boucle. Pas de permission demandée entre les vagues.

MISSION EN UNE PHRASE : qu'un commerçant règle nom, logo, SIRET, TVA, adresse, horaires, mention du ticket, devise et
couleurs depuis un seul écran — et que le ticket imprimé et la borne le reflètent.

PARTICULARITÉS (§14 du protocole) : ⛔ n'enregistre JAMAIS les pages Entreprise ni Site sur :8766, elles écrivent le
.env ; termine TOUTE la W1 (cartographie ticket T-1.1.1 + inventaire .env T-4.1.2) avant W2 ; G-ID ne bloque que
T-1.3.2, le reste de W2 avance ; config/dashboard.php appartient à ONB-07, pas à toi.

COMPTE RENDU : FIXÉ / VÉRIFIÉ / BLOQUÉ. Rien d'autre.
```

## ONB-02 · port 8802 · vague A

```
/goal Tu suis avec précision cette mission : reports/audit/onboarding-commercant-2026-08-26/MISSION_ONB02_CATALOGUE_DE_ZERO.md
le plan : plans/GOAL_ONB02_CATALOGUE_DE_ZERO_2026-08-26.md
et les règles — toutes, sans exception — depuis : reports/audit/onboarding-commercant-2026-08-26/PROTOCOLE_SESSION.md

Lis ces TROIS fichiers EN ENTIER avant d'écrire une ligne de code. Si l'un manque : ARRÊTE, il faut d'abord
`git merge goal/onboarding-commercant-2026-08-26`. Puis récite-moi en dix lignes ce que tu as mémorisé du protocole :
port, worktree, branche, ta voie, tes cinq interdits, la convergence, les gates, la base partagée, le compte rendu,
ton premier geste. Si tu ne peux pas le citer sans relire, relis. Ensuite tu l'appliques seul, sans rappel.

Pré-vol §3 du protocole (12 étapes, port 8802), puis les vagues §X du GOAL, chaque tâche par ultra-audit-profond.
Ordre volontaire : les bords et la fiscalité (W2-W3) AVANT l'ergonomie (W5).

TU TOURNES EN BOUCLE test-e2e : navigateur réel sur 8802, captures LUES, console + réseau à chaque capture, deux
moyens indépendants pour tout P0/P1, et tu reboucles jusqu'à DEUX CYCLES CONSÉCUTIFS aux constats IDENTIQUES,
P0+P1 = 0. Vérifie l'effet réel d'une création sur la borne (GET /api/frontend/menu) et la caisse : un 201 ne prouve
pas la vente.

TU DÉPLOIES DES AGENTS ADVERSAIRES à chaque tâche, lecture seule, dans UN SEUL message :
— technique : Architecte · DBA (taxes polluées, index, slug unique) · Sécurité (upload, import Excel, IDOR)
— interface : QA visuel puis ROUGE visuel qui conteste ses captures indépendamment
— expérience : UX/A11y (3 gabarits) · Psychologie commerçant (Variante/Extra/Supplément, peur de casser la carte)
— puis ROUGE qui RÉFUTE ton correctif avant tout « c'est fini » ; puis le Jalonneur et ses 6 points (§7).
Un seul implémenteur. Trois boucles de soin maximum, puis STOP et quatre options.

Tu ne t'arrêtes QUE sur un gate ou à la 3e boucle. Pas de permission demandée entre les vagues.

MISSION EN UNE PHRASE : qu'un commerçant recopie sa carte en une soirée — catégories, articles, images, taxes,
options, import Excel — sans un message en anglais ni un article né à 0 % de TVA.

PARTICULARITÉS (§14) : ⛔ jamais menu:reset-le-cayenne ; ne change jamais le taux d'une taxe déjà référencée (crée-en
une nouvelle) ; atteste la chaîne NF525 avant de commencer ET après W3 ; collisions à trancher par fiche :
App\Enums\KdsStation avec ONB-10, CatalogHubComponent.vue avec ONB-08.

COMPTE RENDU : FIXÉ / VÉRIFIÉ / BLOQUÉ. Rien d'autre.
```

## ONB-03 · port 8803 · vague B (après ONB-02)

```
/goal Tu suis avec précision cette mission : reports/audit/onboarding-commercant-2026-08-26/MISSION_ONB03_WIZARD_REGLES_DE_PRIX.md
le plan : plans/GOAL_ONB03_WIZARD_REGLES_DE_PRIX_2026-08-26.md
et les règles — toutes, sans exception — depuis : reports/audit/onboarding-commercant-2026-08-26/PROTOCOLE_SESSION.md

Lis ces TROIS fichiers EN ENTIER avant d'écrire une ligne de code. Si l'un manque : ARRÊTE, il faut d'abord
`git merge goal/onboarding-commercant-2026-08-26`. Puis récite-moi en dix lignes ce que tu as mémorisé du protocole.
Si tu ne peux pas le citer sans relire, relis. Ensuite tu l'appliques seul, sans rappel.

Pré-vol §3 (12 étapes, port 8803) — et ne crée ton worktree que depuis un HEAD qui contient ONB-02. Puis les vagues
§X, chaque tâche par ultra-audit-profond.

TU TOURNES EN BOUCLE test-e2e : navigateur réel sur 8803, tu composes un produit à la borne ET à la caisse et tu
compares le total affiché au devis backend ; captures LUES, console + réseau, deux moyens pour tout P0/P1 ; tu
reboucles jusqu'à DEUX CYCLES CONSÉCUTIFS aux constats IDENTIQUES, P0+P1 = 0.

TU DÉPLOIES DES AGENTS ADVERSAIRES à chaque tâche, lecture seule, dans UN SEUL message :
— technique : Architecte (modèle de règle, ordre des inclusions) · DBA (migration, CHECKs, snapshots) · Fiscal
  (NF525 : devis signé, composition_snapshot) · Sécurité (override = manipulation de prix ?)
— interface : QA visuel puis ROUGE visuel indépendant
— expérience : UX/A11y · Psychologie commerçant (« offert / inclus / payant » en trois boutons, aperçu chiffré)
— puis ROUGE qui rejoue les 59 devis et RÉFUTE ; puis le Jalonneur et ses 6 points (§7).
Un seul implémenteur. Trois boucles maximum, puis STOP et quatre options.

MISSION EN UNE PHRASE : que « 1 sauce incluse puis 0,50 €, viande obligatoire, boisson formule +2 € » se règle en
trois boutons, sur l'article ou la catégorie, le prix restant calculé par le backend.

PARTICULARITÉS (§14) : AVANT TOUT, fige la ligne de base des prix — devis des 59 articles × 3 surfaces × 3
compositions + un test qui rougit si un total bouge d'un centime (critère C1). PricingService.php est GELÉ : pas une
ligne sans LOCK contresigné (skill lock-plan → gate G-PRIX), et borne le LOCK à ce que W5 exige aussi de
config/menu.php et config/kiosk.php. `price` reste interdit dans les requêtes composer. ⛔ Jamais de commande pour
vérifier un prix : le devis suffit. Neuf gates : tu les présentes, tu n'en tranches aucun.

COMPTE RENDU : FIXÉ / VÉRIFIÉ / BLOQUÉ. Rien d'autre.
```

## ONB-04 · port 8804 · vague B (après 02/03)

```
/goal Tu suis avec précision cette mission : reports/audit/onboarding-commercant-2026-08-26/MISSION_ONB04_EXTRACTION_MENU_IA_ET_ASSISTANT.md
le plan : plans/GOAL_ONB04_EXTRACTION_MENU_IA_ET_ASSISTANT_2026-08-26.md
et les règles — toutes, sans exception — depuis : reports/audit/onboarding-commercant-2026-08-26/PROTOCOLE_SESSION.md

Lis ces TROIS fichiers EN ENTIER avant d'écrire une ligne de code. Si l'un manque : ARRÊTE, il faut d'abord
`git merge goal/onboarding-commercant-2026-08-26`. Puis récite-moi en dix lignes ce que tu as mémorisé du protocole.
Si tu ne peux pas le citer sans relire, relis. Ensuite tu l'appliques seul, sans rappel.

Pré-vol §3 (12 étapes, port 8804), puis les vagues §X, chaque tâche par ultra-audit-profond.

TU TOURNES EN BOUCLE test-e2e : navigateur réel sur 8804 — tu déposes une fixture de carte, tu corriges deux lignes,
tu appliques, tu vérifies l'effet sur la borne et la caisse, puis tu purges le lot ; captures LUES, console + réseau,
deux moyens pour tout P0/P1 ; tu reboucles jusqu'à DEUX CYCLES CONSÉCUTIFS aux constats IDENTIQUES, P0+P1 = 0.

TU DÉPLOIES DES AGENTS ADVERSAIRES à chaque tâche, lecture seule, dans UN SEUL message :
— technique : Architecte (contrat, schéma, frontière IA/API) · DBA (idempotence, journal) · Sécurité (upload,
  injection de prompt par la carte, clé, coût)
— interface : QA visuel puis ROUGE visuel indépendant sur l'écran de validation
— expérience : UX/A11y · Psychologie commerçant (« rien n'entre sans moi » ; l'IA se trompe et le dit)
— puis ROUGE, qui TENTE de faire écrire l'IA directement et d'exécuter une mission interdite ; puis le Jalonneur (§7).
Un seul implémenteur. Trois boucles maximum, puis STOP et quatre options.

MISSION EN UNE PHRASE : qu'une photo de la carte devienne une proposition structurée, que l'humain corrige et valide,
et que le système l'applique via les API existantes — puis qu'un assistant exécute des missions locales en proposant
d'abord un plan.

PARTICULARITÉS (§14) : l'IA PROPOSE, l'humain VALIDE, le système APPLIQUE — l'IA n'écrit jamais en base et ne calcule
jamais un prix. Tout converge en MOCK : le binding se fait sur assistant.enabled (ton config/assistant.php), pas
seulement sur OPENAI_VISION_ENABLED — mets les deux à faux et prouve par un test que le conteneur résout
MockMenuExtractionService. Aucun appel réel à OpenAI sans le gate G-IA. G-DATA bloque W2 ET W3.

COMPTE RENDU : FIXÉ / VÉRIFIÉ / BLOQUÉ. Rien d'autre.
```

## ONB-05 · port 8805 · vague A

```
/goal Tu suis avec précision cette mission : reports/audit/onboarding-commercant-2026-08-26/MISSION_ONB05_REGLAGES_SANS_DEVELOPPEUR.md
le plan : plans/GOAL_ONB05_REGLAGES_SANS_DEVELOPPEUR_2026-08-26.md
et les règles — toutes, sans exception — depuis : reports/audit/onboarding-commercant-2026-08-26/PROTOCOLE_SESSION.md

Lis ces TROIS fichiers EN ENTIER avant d'écrire une ligne de code. Si l'un manque : ARRÊTE, il faut d'abord
`git merge goal/onboarding-commercant-2026-08-26`. Puis récite-moi en dix lignes ce que tu as mémorisé du protocole.
Si tu ne peux pas le citer sans relire, relis. Ensuite tu l'appliques seul, sans rappel.

Pré-vol §3 (12 étapes, port 8805), puis lis INTÉGRALEMENT app/Services/Pilotage/InterrupteurService.php (166 lignes)
avant d'écrire quoi que ce soit. Puis les vagues §X, chaque tâche par ultra-audit-profond.

TU TOURNES EN BOUCLE test-e2e : navigateur réel sur 8805 — pour cinq réglages témoins : PUT → lecture par le
consommateur en moins d'une seconde → RESTAURE la valeur d'origine ; captures LUES, console + réseau, deux moyens
pour tout P0/P1 ; tu reboucles jusqu'à DEUX CYCLES CONSÉCUTIFS aux constats IDENTIQUES, P0+P1 = 0.

TU DÉPLOIES DES AGENTS ADVERSAIRES à chaque tâche, lecture seule, dans UN SEUL message :
— technique : Architecte (catalogue déclaratif vs `if`) · DBA (payload settings, volumétrie) · Sécurité (liste noire,
  lecture par le caissier, page Licence) · SRE (caches, propagation, bundle)
— interface : QA visuel puis ROUGE visuel indépendant
— expérience : UX/A11y · Psychologie commerçant (un réglage = une phrase de conséquence + un bouton « rétablir »)
— puis ROUGE qui RÉFUTE ; puis le Jalonneur et ses 6 points (§7).
Un seul implémenteur. Trois boucles maximum, puis STOP et quatre options.

MISSION EN UNE PHRASE : qu'un commerçant règle sa tolérance d'écart de caisse, ses seuils, ses horaires de service et
ses bascules métier depuis son Dashboard — et que les 22 pages cachées soient tranchées, pas héritées.

PARTICULARITÉS (§14) : tu es le SEUL propriétaire des trois fichiers de visibilité du menu (v1-hidden-modules.js,
settings/MenuComponent.vue, BackendMenuComponent.vue) : collecte les fiches des autres sessions avant W4. En revanche
config/dashboard.php appartient à ONB-07, config/printing.php et SystemHealthComponent.vue à ONB-10 : passe par des
fiches. G-CACHE bloque W4, pas le reste. Les réglages sont GLOBAUX : note avant, restaure après, vérifie en base.
⛔ idempotency.enabled et tout le fiscal restent hors catalogue.

COMPTE RENDU : FIXÉ / VÉRIFIÉ / BLOQUÉ. Rien d'autre.
```

## ONB-06 · port 8806 · vague A

```
/goal Tu suis avec précision cette mission : reports/audit/onboarding-commercant-2026-08-26/MISSION_ONB06_EQUIPE_ROLES_ACCES.md
le plan : plans/GOAL_ONB06_EQUIPE_ROLES_ACCES_2026-08-26.md
et les règles — toutes, sans exception — depuis : reports/audit/onboarding-commercant-2026-08-26/PROTOCOLE_SESSION.md

Lis ces TROIS fichiers EN ENTIER avant d'écrire une ligne de code. Si l'un manque : ARRÊTE, il faut d'abord
`git merge goal/onboarding-commercant-2026-08-26`. Puis récite-moi en dix lignes ce que tu as mémorisé du protocole.
Si tu ne peux pas le citer sans relire, relis. Ensuite tu l'appliques seul, sans rappel.

Pré-vol §3 (12 étapes, port 8806), puis les vagues §X — ordre volontaire : l'ENFORCEMENT avant l'ergonomie, on ne
dessine pas un écran Équipe sur une API perméable. Chaque tâche par ultra-audit-profond.

TU TOURNES EN BOUCLE test-e2e : navigateur réel sur 8806 ET appels API DIRECTS avec le jeton d'un rôle inférieur — le
menu qui cache une page ne prouve rien ; tout 2xx là où l'écran cache est un P0 avec requête + réponse. Captures LUES,
console + réseau ; tu reboucles jusqu'à DEUX CYCLES CONSÉCUTIFS aux constats IDENTIQUES, P0+P1 = 0.

TU DÉPLOIES DES AGENTS ADVERSAIRES à chaque tâche, lecture seule, dans UN SEUL message :
— technique : Sécurité EN TÊTE (matrice rôles × routes, IDOR, jetons) · Architecte · DBA (users.phone, model_has_roles)
— interface : QA visuel puis ROUGE visuel indépendant
— expérience : UX/A11y · Psychologie commerçant (une permission = une phrase de conséquence ; peur de donner trop)
— puis ROUGE, qui CHERCHE le 2xx indu ; puis le Jalonneur et ses 6 points (§7).
Un seul implémenteur. Trois boucles maximum, puis STOP et quatre options.

MISSION EN UNE PHRASE : qu'un patron embauche son caissier en huit clics, lise en français ce que chacun peut faire,
et que l'API refuse tout ce que l'écran cache.

PARTICULARITÉS (§14) : la page Rôles est à /admin/settings/role/list — SINGULIER ; un auditeur a visé « roles » et
conclu à tort qu'elle n'existait pas. ⛔ Ne modifie jamais les permissions en base d'un rôle seedé pendant tes essais,
et n'exécute JAMAIS db:seed --class=PermissionTableSeeder sur la base partagée ; en revanche renommer « Stuff » et
créer les rôles socle sont légitimes, par seeder, APRÈS G-ROLES. Ne « répare » pas la révocation par appareil de
DeviceTokenService. BranchScope est gelé.

COMPTE RENDU : FIXÉ / VÉRIFIÉ / BLOQUÉ. Rien d'autre.
```

## ONB-07 · port 8807 · vague A

```
/goal Tu suis avec précision cette mission : reports/audit/onboarding-commercant-2026-08-26/MISSION_ONB07_TABLEAU_DE_BORD_ET_RAPPORTS_VRAIS.md
le plan : plans/GOAL_ONB07_TABLEAU_DE_BORD_ET_RAPPORTS_VRAIS_2026-08-26.md
et les règles — toutes, sans exception — depuis : reports/audit/onboarding-commercant-2026-08-26/PROTOCOLE_SESSION.md

Lis ces TROIS fichiers EN ENTIER avant d'écrire une ligne de code. Si l'un manque : ARRÊTE, il faut d'abord
`git merge goal/onboarding-commercant-2026-08-26`. Puis récite-moi en dix lignes ce que tu as mémorisé du protocole.
Si tu ne peux pas le citer sans relire, relis. Ensuite tu l'appliques seul, sans rappel.

Pré-vol §3 (12 étapes, port 8807), puis W1 = exécuter le brief Z4 de
reports/audit/onboarding-commercant-2026-08-26/recon/_ZONES.md : ta zone n'a PAS été auditée en direct, la
reconnaissance c'est toi. Puis les vagues §X, chaque tâche par ultra-audit-profond.

TU TOURNES EN BOUCLE test-e2e : navigateur réel sur 8807 — tu lis les 12 widgets, tu exportes, tu compares au SQL ;
captures LUES, console + réseau ; aucun P0/P1 sans DOUBLE preuve (API + SQL) ; tu reboucles jusqu'à DEUX CYCLES
CONSÉCUTIFS aux constats IDENTIQUES, P0+P1 = 0. Un widget à 0 € peut être un faux-vide : regarde la requête réseau.

TU DÉPLOIES DES AGENTS ADVERSAIRES à chaque tâche, lecture seule, dans UN SEUL message :
— technique : DBA EN TÊTE (le SQL réel des 14 méthodes, index, business_date) · Architecte · Sécurité (exports,
  formules Excel, exposition par rôle)
— interface : QA visuel puis ROUGE visuel indépendant
— expérience : UX/A11y · Psychologie commerçant (deux chiffres différents = « le logiciel ment »)
— puis ROUGE, qui CHERCHE l'écart de 0,01 € ; puis le Jalonneur et ses 6 points (§7).
Un seul implémenteur. Trois boucles maximum, puis STOP et quatre options.

MISSION EN UNE PHRASE : que chaque chiffre du Dashboard ait une définition écrite et testée, et que widget = rapport =
base = export, au centime.

PARTICULARITÉS (§14) : ⚠️ config/dashboard.php, SlaAlertesBorneBasseTest.php et le correctif SLA de DashboardService
NE SONT DANS AUCUN COMMIT — ils n'existent que dans l'arbre principal non commité : copie-les en W0 et déclare-les,
sinon tu mesures un tableau de bord qui n'est pas celui du propriétaire. ⛔ Tu ne crées AUCUNE commande, session de
caisse ni Z pour « avoir des données » : usines de tests. ZReportService et AuditLogService sont gelés.

COMPTE RENDU : FIXÉ / VÉRIFIÉ / BLOQUÉ. Rien d'autre.
```

## ONB-08 · port 8808 · vague A

```
/goal Tu suis avec précision cette mission : reports/audit/onboarding-commercant-2026-08-26/MISSION_ONB08_STOCK_INGREDIENTS_DISPONIBILITE.md
le plan : plans/GOAL_ONB08_STOCK_INGREDIENTS_DISPONIBILITE_2026-08-26.md
et les règles — toutes, sans exception — depuis : reports/audit/onboarding-commercant-2026-08-26/PROTOCOLE_SESSION.md

Lis ces TROIS fichiers EN ENTIER avant d'écrire une ligne de code. Si l'un manque : ARRÊTE, il faut d'abord
`git merge goal/onboarding-commercant-2026-08-26`. Puis récite-moi en dix lignes ce que tu as mémorisé du protocole.
Si tu ne peux pas le citer sans relire, relis. Ensuite tu l'appliques seul, sans rappel.

Pré-vol §3 (12 étapes, port 8808), puis W1 = exécuter le brief Z5 de
reports/audit/onboarding-commercant-2026-08-26/recon/_ZONES.md, chronomètre de propagation compris (ta zone n'a pas
été auditée en direct). Puis les vagues §X — les MOUVEMENTS VALIDÉS d'abord. Chaque tâche par ultra-audit-profond.

TU TOURNES EN BOUCLE test-e2e : navigateur réel sur 8808 — tu bascules un article de test en rupture et tu MESURES le
délai jusqu'à la borne, la caisse, le KDS et la projection web (quatre surfaces), puis tu le remets en disponibilité ;
captures LUES, console + réseau, deux moyens pour tout P0/P1 ; tu reboucles jusqu'à DEUX CYCLES CONSÉCUTIFS aux
constats IDENTIQUES, P0+P1 = 0.

TU DÉPLOIES DES AGENTS ADVERSAIRES à chaque tâche, lecture seule, dans UN SEUL message :
— technique : SRE et DBA EN TÊTE (propagation, cache borne, append-only, isolation) · Architecte · Sécurité (upload du
  scan, formules Excel, IDOR sur les stocks d'une autre filiale)
— interface : QA visuel puis ROUGE visuel indépendant
— expérience : UX/A11y · Psychologie commerçant (urgence de 20 h : un bouton, une confirmation, une preuve)
— puis ROUGE qui RÉFUTE ; puis le Jalonneur et ses 6 points (§7).
Un seul implémenteur. Trois boucles maximum, puis STOP et quatre options.

MISSION EN UNE PHRASE : que « est-ce vendable ? combien il m'en reste ? qu'est-ce que je rachète ? » se répondent en
deux clics chacune, et qu'une rupture atteigne la borne en moins de dix secondes.

PARTICULARITÉS (§14) : W5 (le hub à trois onglets) est bloquée par G-HUB-STOCK. ⛔ Aucun ajustement sur un stock réel :
matière et article de test, compensés exactement ; mouvements append-only (annuler = mouvement inverse). La FormRequest
du scan de facture ne doit RIEN relâcher de la garde RCE existante : test de caractérisation d'abord.

COMPTE RENDU : FIXÉ / VÉRIFIÉ / BLOQUÉ. Rien d'autre.
```

## ONB-09 · port 8809 · vague A

```
/goal Tu suis avec précision cette mission : reports/audit/onboarding-commercant-2026-08-26/MISSION_ONB09_ANIMATION_COMMERCIALE.md
le plan : plans/GOAL_ONB09_ANIMATION_COMMERCIALE_2026-08-26.md
et les règles — toutes, sans exception — depuis : reports/audit/onboarding-commercant-2026-08-26/PROTOCOLE_SESSION.md

Lis ces TROIS fichiers EN ENTIER avant d'écrire une ligne de code. Si l'un manque : ARRÊTE, il faut d'abord
`git merge goal/onboarding-commercant-2026-08-26`. Puis récite-moi en dix lignes ce que tu as mémorisé du protocole.
Si tu ne peux pas le citer sans relire, relis. Ensuite tu l'appliques seul, sans rappel.

Pré-vol §3 (12 étapes, port 8809), puis W1 = brief Z6 de
reports/audit/onboarding-commercant-2026-08-26/recon/_ZONES.md + la caractérisation « devis ≠ commit » en 12 cas.
Puis les vagues §X, chaque tâche par ultra-audit-profond.

TU TOURNES EN BOUCLE test-e2e : navigateur réel sur 8809 — tu crées un coupon, tu l'appliques au DEVIS borne et
caisse, tu vérifies l'aperçu client ; captures LUES, console + réseau, deux moyens pour tout P0/P1 ; tu reboucles
jusqu'à DEUX CYCLES CONSÉCUTIFS aux constats IDENTIQUES, P0+P1 = 0.

TU DÉPLOIES DES AGENTS ADVERSAIRES à chaque tâche, lecture seule, dans UN SEUL message :
— technique : Sécurité EN TÊTE (coupons publics, secret QR, IDOR abonnés) · Architecte (frontière devis/commit) · DBA
  (usages de coupons, grand livre fidélité) · SRE (file Redis, worker)
— interface : QA visuel puis ROUGE visuel indépendant
— expérience : UX/A11y · Psychologie commerçant (une promo = une phrase de conséquence + un aperçu client)
— puis ROUGE qui rejoue les 12 cas de devis et RÉFUTE ; puis le Jalonneur et ses 6 points (§7).
Un seul implémenteur. Trois boucles maximum, puis STOP et quatre options.

MISSION EN UNE PHRASE : qu'un commerçant crée « -10 % le mardi », un code borne et un programme de fidélité lui-même,
sans jamais sortir le prix du backend.

PARTICULARITÉS : ⚠️ ce GOAL n'a pas été relu par un auditeur adverse (limite de session) : rigueur supplémentaire, et
signale tout écart entre le GOAL et le code réel avant de t'y fier. ⛔ Aucune commande créée — un coupon se prouve au
DEVIS et par test PHP. ⛔ Aucun worker lancé sur la file notifications (1 490 messages, débranchée volontairement).
PricingService et DiscountCalculator sont gelés : « accepté au devis, refusé au commit » se caractérise d'abord, se
corrige SOUS LOCK (G-PRIX-COUPON). La file est en Redis : la table jobs est vide et ne prouve rien.

COMPTE RENDU : FIXÉ / VÉRIFIÉ / BLOQUÉ. Rien d'autre.
```

## ONB-10 · port 8810 · vague A

```
/goal Tu suis avec précision cette mission : reports/audit/onboarding-commercant-2026-08-26/MISSION_ONB10_EQUIPEMENT_ET_OPERATIONS.md
le plan : plans/GOAL_ONB10_EQUIPEMENT_ET_OPERATIONS_2026-08-26.md
et les règles — toutes, sans exception — depuis : reports/audit/onboarding-commercant-2026-08-26/PROTOCOLE_SESSION.md

Lis ces TROIS fichiers EN ENTIER avant d'écrire une ligne de code. Si l'un manque : ARRÊTE, il faut d'abord
`git merge goal/onboarding-commercant-2026-08-26`. Puis récite-moi en dix lignes ce que tu as mémorisé du protocole.
Si tu ne peux pas le citer sans relire, relis. Ensuite tu l'appliques seul, sans rappel.

Pré-vol §3 (12 étapes, port 8810) + un récepteur TCP prêt (nc -l 9100). Puis les vagues §X — la RÉVOCATION DES JETONS
d'abord, c'est de la sécurité. Chaque tâche par ultra-audit-profond.

TU TOURNES EN BOUCLE test-e2e : navigateur réel sur 8810 — tu crées une borne de test, tu la connectes, tu la
supprimes, et tu prouves que son jeton est mort (401) en moins d'une seconde ; tu testes quatre adresses
d'imprimante ; captures LUES, console + réseau, deux moyens pour tout P0/P1 ; tu reboucles jusqu'à DEUX CYCLES
CONSÉCUTIFS aux constats IDENTIQUES, P0+P1 = 0.

TU DÉPLOIES DES AGENTS ADVERSAIRES à chaque tâche, lecture seule, dans UN SEUL message :
— technique : Sécurité et SRE EN TÊTE (jetons de borne, oracle de scan de port, /api/health exposé, files) ·
  Architecte (identité par borne) · DBA (setup_secret, index device_id)
— interface : QA visuel puis ROUGE visuel indépendant
— expérience : UX/A11y · Psychologie commerçant (« ça marche ? » se prouve par le contenu ; « Archivé » = panique)
— puis ROUGE qui RÉFUTE ; puis le Jalonneur et ses 6 points (§7).
Un seul implémenteur. Trois boucles maximum, puis STOP et quatre options.

MISSION EN UNE PHRASE : qu'un commerçant branche sa borne, son imprimante et son TPE depuis le Dashboard, sache si
« ça marche », et qu'une borne retirée cesse de commander à la seconde.

PARTICULARITÉS : ⚠️ ce GOAL n'a pas été relu par un auditeur adverse : rigueur supplémentaire. Une impression ne se
prouve JAMAIS par un 200 — PRINTING_BYPASS_MODE=true répond « ok » vers un hôte inexistant : compte les octets reçus
par nc. ⛔ Ne touche jamais aux 14 bornes, 3 imprimantes et 2 TPE existants. KDS et OSS sont une AUTRE voie : lecture
et fiches. Tu possèdes config/printing.php et SystemHealthComponent.vue. Désactive le bypass d'auto-login local pour
prouver le vrai parcours d'installation.

COMPTE RENDU : FIXÉ / VÉRIFIÉ / BLOQUÉ. Rien d'autre.
```

## ONB-11 · port 8811 · vague A (audit) puis B (corrections)

```
/goal Tu suis avec précision cette mission : reports/audit/onboarding-commercant-2026-08-26/MISSION_ONB11_EXPERIENCE_COMMERCANT_TRANSVERSE.md
le plan : plans/GOAL_ONB11_EXPERIENCE_COMMERCANT_TRANSVERSE_2026-08-26.md
et les règles — toutes, sans exception — depuis : reports/audit/onboarding-commercant-2026-08-26/PROTOCOLE_SESSION.md

Lis ces TROIS fichiers EN ENTIER avant d'écrire une ligne de code. Si l'un manque : ARRÊTE, il faut d'abord
`git merge goal/onboarding-commercant-2026-08-26`. Puis récite-moi en dix lignes ce que tu as mémorisé du protocole.
Si tu ne peux pas le citer sans relire, relis. Ensuite tu l'appliques seul, sans rappel.

Pré-vol §3 (12 étapes, port 8811), puis W1 = brief Z8 de
reports/audit/onboarding-commercant-2026-08-26/recon/_ZONES.md. Puis les vagues §X, chaque tâche par
ultra-audit-profond.

TU TOURNES EN BOUCLE test-e2e : navigateur réel sur 8811 — le chronomètre de la première heure (un agent qui n'a PAS
lu les GOAL reçoit quatre consignes en langage courant, on mesure temps, écrans, hésitations, abandons), axe-core sur
25 pages × 3 gabarits, 5 parcours au clavier seul, la tablette sans défilement horizontal ; captures LUES, console +
réseau ; une friction n'est un constat que REPRODUITE PAR DEUX MOYENS ; tu reboucles jusqu'à DEUX CYCLES CONSÉCUTIFS
aux constats IDENTIQUES, P0+P1 = 0.

TU DÉPLOIES DES AGENTS ADVERSAIRES à chaque tâche, lecture seule, dans UN SEUL message :
— expérience EN TÊTE : UX/A11y (WCAG, focus, contraste, cibles tactiles) · Psychologie commerçant (chronomètre,
  hésitations, peur, vocabulaire)
— interface : QA visuel puis ROUGE visuel qui conteste ses captures indépendamment
— technique : Architecte (composants partagés sans casser la zone gelée) · Sécurité (aucune aide n'expose de secret)
— puis ROUGE qui conteste chaque friction ; puis le Jalonneur et ses 6 points (§7).
Un seul implémenteur. Trois boucles maximum, puis STOP et quatre options.

MISSION EN UNE PHRASE : qu'un commerçant qui ouvre le Dashboard pour la première fois sache par où commencer,
comprenne chaque mot en français, et n'ait peur de rien.

PARTICULARITÉS : ⚠️ ce GOAL n'a pas été relu par un auditeur adverse : rigueur supplémentaire. En vague A tu es en
LECTURE SEULE TOTALE et tu émets des fiches de renvoi ; tu n'édites JAMAIS un composant de page d'un autre GOAL. Tu
n'écris dans fr.json qu'en vague B, par blocs. Ne change jamais la signature d'un composant partagé importé par une
zone gelée (LoadingComponent l'est par PaymentComponent.vue).

COMPTE RENDU : FIXÉ / VÉRIFIÉ / BLOQUÉ. Rien d'autre.
```

## ONB-12 · port 8812 · vague C (après 01, 02, 05, 06 et G0)

```
/goal Tu suis avec précision cette mission : reports/audit/onboarding-commercant-2026-08-26/MISSION_ONB12_PREMIER_DEMARRAGE_ET_PUBLICATION_VIERGE.md
le plan : plans/GOAL_ONB12_PREMIER_DEMARRAGE_ET_PUBLICATION_VIERGE_2026-08-26.md
et les règles — toutes, sans exception — depuis : reports/audit/onboarding-commercant-2026-08-26/PROTOCOLE_SESSION.md

AVANT TOUT : vérifie que CONSTITUTION.md §1 porte l'amendement G0 (« logiciel d'UN établissement, entièrement
paramétrable depuis son Dashboard »). S'il n'y est pas, ARRÊTE et demande-le : ce GOAL matérialise cet amendement.

Lis ces TROIS fichiers EN ENTIER avant d'écrire une ligne de code. Si l'un manque : ARRÊTE, il faut d'abord
`git merge goal/onboarding-commercant-2026-08-26`. Puis récite-moi en dix lignes ce que tu as mémorisé du protocole.
Si tu ne peux pas le citer sans relire, relis. Ensuite tu l'appliques seul, sans rappel.

Pré-vol §3 (12 étapes, port 8812) avec DB_DATABASE=foodking_onb12, une base DÉDIÉE créée VIDE — seule exception au
partage de base, et seul moyen de prouver une installation vierge. Puis les vagues §X, chaque tâche par
ultra-audit-profond.

TU TOURNES EN BOUCLE test-e2e : navigateur réel sur 8812, base vierge — tu installes, tu ouvres le Dashboard, tu suis
la checklist, tu crées 3 articles, une borne, une commande, un Z, et tu grep le DOM de 12 pages : zéro « Cayenne » ;
captures LUES, console + réseau ; tu reboucles jusqu'à DEUX CYCLES CONSÉCUTIFS aux constats IDENTIQUES, P0+P1 = 0.

TU DÉPLOIES DES AGENTS ADVERSAIRES à chaque tâche, lecture seule, dans UN SEUL message :
— technique : Architecte (socle vs données, ordre des seeders, idempotence) · DBA (chaîne fiscale à l'installation) ·
  Sécurité (admin par défaut, mot de passe imposé, installeur exposé) · SRE (reproductibilité)
— interface : QA visuel puis ROUGE visuel indépendant sur la checklist
— expérience : UX/A11y · Psychologie commerçant (une checklist qui compte, jamais qui culpabilise)
— puis ROUGE, qui CHERCHE un « Cayenne » à l'écran et une donnée de marque dans le socle ; puis le Jalonneur (§7).
Un seul implémenteur. Trois boucles maximum, puis STOP et quatre options.

MISSION EN UNE PHRASE : qu'une installation neuve donne un établissement générique et propre — pas le menu de Le
Cayenne — avec une checklist « Premier démarrage », la marque étant devenue une donnée.

PARTICULARITÉS : ⚠️ non relu par un auditeur adverse : rigueur supplémentaire. ⛔ JAMAIS migrate:fresh ni db:seed sur
foodking_e2e ou une base existante ; ⛔ JAMAIS menu:reset-le-cayenne hors de ta base dédiée ; ⛔ AUCUNE suppression
d'un seeder, d'une commande ou d'une donnée Le Cayenne — uniquement des déplacements sous LeCayenne/. Lis le docblock
de GrillHouseMenuSeeder : une tentative de seconde marque a déjà été bloquée, comprends pourquoi.

COMPTE RENDU : FIXÉ / VÉRIFIÉ / BLOQUÉ. Rien d'autre.
```

## ONB-13 · port 8813 · vague A (audit) puis B (corrections)

```
/goal Tu suis avec précision cette mission : reports/audit/onboarding-commercant-2026-08-26/MISSION_ONB13_SECURITE_INTEGRITE_BACKOFFICE.md
le plan : plans/GOAL_ONB13_SECURITE_INTEGRITE_BACKOFFICE_2026-08-26.md
et les règles — toutes, sans exception — depuis : reports/audit/onboarding-commercant-2026-08-26/PROTOCOLE_SESSION.md

Lis ces TROIS fichiers EN ENTIER avant d'écrire une ligne de code. Si l'un manque : ARRÊTE, il faut d'abord
`git merge goal/onboarding-commercant-2026-08-26`. Puis récite-moi en dix lignes ce que tu as mémorisé du protocole.
Si tu ne peux pas le citer sans relire, relis. Ensuite tu l'appliques seul, sans rappel.

Pré-vol §3 (12 étapes, port 8813), invoque la compétence security-review, puis W1 = l'audit offensif en lecture seule
(matrice route × rôle × champs, 40 payloads invalides, 5 points d'entrée d'upload, IDOR sur 12 ressources, inventaire
des return true). Puis les vagues §X, chaque tâche par ultra-audit-profond.

TU TOURNES EN BOUCLE test-e2e : navigateur réel sur 8813 pour les écrans, mais l'essentiel en API directe avec des
jetons de rôles différents ; chaque constat = requête + réponse + file:line ; captures LUES, console + réseau ; un 403
est un SUCCÈS ; tu reboucles jusqu'à DEUX CYCLES CONSÉCUTIFS aux constats IDENTIQUES, P0+P1 = 0.

TU DÉPLOIES DES AGENTS ADVERSAIRES à chaque tâche, lecture seule, dans UN SEUL message :
— technique : Sécurité EN TÊTE, en mode OFFENSIF (IDOR, rejeu, upload piégé, fuite de secret) · Architecte (journal
  distinct du fiscal) · DBA (settings_audit, rétention) · SRE (rate limit, logs)
— interface : QA visuel puis ROUGE visuel indépendant (messages d'erreur, page Journal)
— expérience : UX/A11y · Psychologie commerçant (le journal = confiance ; l'erreur technique = peur)
— puis ROUGE = un SECOND attaquant indépendant du premier ; puis le Jalonneur et ses 6 points (§7).
Un seul implémenteur. Trois boucles maximum, puis STOP et quatre options.

MISSION EN UNE PHRASE : que chaque mutation admin soit validée, qu'aucun secret ni message technique ne fuie, que le
double clic n'écrive qu'une fois, et qu'un inspecteur sache qui a changé la TVA.

PARTICULARITÉS : ⚠️ non relu par un auditeur adverse : rigueur supplémentaire. ⛔ Jamais un fichier gelé
(IdempotencyKeyMiddleware, BranchScope, AuditLogService, PricingService) ; ⛔ jamais une garde existante relâchée — la
validation inline du scan de facture porte une garde RCE, test de caractérisation d'abord ; ⛔ jamais d'écriture dans
audit_logs : le journal des réglages est une table DISTINCTE. Tu CRÉES les FormRequests manquantes et tu les livres
par fiche à leur propriétaire. Annote « MySQL requis » tout test de concurrence ou de trigger.

COMPTE RENDU : FIXÉ / VÉRIFIÉ / BLOQUÉ. Rien d'autre.
```

## ONB-14 · port 8814 · vague D (dernier)

```
/goal Tu suis avec précision cette mission : reports/audit/onboarding-commercant-2026-08-26/MISSION_ONB14_CONVERGENCE_JOURNEE_NOUVEAU_COMMERCANT.md
le plan : plans/GOAL_ONB14_CONVERGENCE_JOURNEE_NOUVEAU_COMMERCANT_2026-08-26.md
et les règles — toutes, sans exception — depuis : reports/audit/onboarding-commercant-2026-08-26/PROTOCOLE_SESSION.md

AVANT TOUT : vérifie dans plans/GOAL_INDEX_ONBOARDING_COMMERCANT_2026-08-26.md et PROJECT_BRAIN.md §2 que la vague C
est close — ONB-01 à 13 fusionnés dans HEAD, ou différés par écrit (gate G-DIFF). Sinon, ARRÊTE.

Lis ces TROIS fichiers EN ENTIER avant d'écrire une ligne de code. Si l'un manque : ARRÊTE, il faut d'abord
`git merge goal/onboarding-commercant-2026-08-26`. Puis récite-moi en dix lignes ce que tu as mémorisé du protocole,
dont ce que tu n'as PAS le droit de corriger. Si tu ne peux pas le citer sans relire, relis.

Pré-vol §3 (12 étapes, port 8814) avec DB_DATABASE=foodking_onb14, base DÉDIÉE installée par
`php artisan foodking:installer --etablissement="Chez Nadia"`, puis dump de l'état zéro : chaque cycle repart de là.
La garde d'identité de tests/Playwright/global-setup.js doit accepter 8814 et refuser 8766 et 8000.

TU TOURNES EN BOUCLE test-e2e — c'est TOUT le GOAL : les 12 étapes de la journée (installation, identité, carte,
règles, équipe, réglages, équipement, stock, promo, vente borne → cuisine → encaissement → ticket, clôture Z et
rapports, lendemain) au navigateur réel ET en jumeau PHP sur MySQL ; captures LUES à chaque étape, console + réseau ;
preuves par le CONTENU (octets ESC/POS, z_reports, composition_snapshot, fiscal:verify-chain) ; tu reboucles jusqu'à
DEUX CYCLES COMPLETS CONSÉCUTIFS aux constats IDENTIQUES, P0+P1 = 0.

TU DÉPLOIES DES AGENTS ADVERSAIRES à chaque cycle, lecture seule, dans UN SEUL message :
— interface EN TÊTE : QA visuel (chaque étape capturée) puis ROUGE visuel qui conteste indépendamment
— expérience : Psychologie commerçant (Nadia « joue » la journée, hésitations consignées) · UX/A11y
— technique : SRE (worker, soketi, scrutation) · Fiscal (chaîne avant/après, snapshot) · Architecte (renvoi au bon
  propriétaire, file:line réel)
— puis le Jalonneur, qui refuse le cycle au premier « non » (6 points, §7). AUCUN implémenteur dans cette session.

MISSION EN UNE PHRASE : prouver qu'un établissement qui n'est PAS Le Cayenne se règle, vend, cuisine, encaisse,
clôture et lit ses chiffres — sans qu'un développeur ait touché un fichier.

PARTICULARITÉS : ⚠️ non relu par un auditeur adverse : rigueur supplémentaire. ⛔ AUCUN fichier produit modifié par
toi — chaque échec devient une fiche de renvoi au GOAL propriétaire, qui corrige dans SA session ; tu fusionnes et tu
rejoues. ⛔ Jamais la base partagée ni :8766. ⛔ Aucune commande supprimée. ⛔ Aucun sélecteur inventé : 23 sélecteurs
morts ont déjà pourri le harnais. La poussée et l'étiquette sont le gate G-PUSH.

COMPTE RENDU : FIXÉ (rien, par construction) / VÉRIFIÉ (12 étapes × 2 cycles) / BLOQUÉ (renvois ouverts).
```
