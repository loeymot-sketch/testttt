# Ronde 3 — supervision adverse de la caisse

Branche `goal/caisse-vision-2026-08-24` · 2026-08-25 → 26

---

## Verdict : PAS de convergence

La règle est : deux cycles consécutifs, P0 + P1 = 0, jeux de constats identiques.
La ronde 3 a ouvert **1 P0 et 12 P1**. J'en ai clos 11, un douzième en partie.
Il en reste 2 entiers et 1 partiel.

On ne peut donc pas déclarer la mission validée, et je ne l'ai pas déployée en production.

---

## Ce que cette ronde a coûté à mes propres affirmations

C'est la partie qui compte le plus, et je la mets en premier.

**Quatre défauts trouvés visaient des correctifs que j'avais déjà livrés dans cette
mission**, et deux visaient mon harnais d'audit lui-même :

| Constat | Ce que j'avais affirmé | Ce qui était vrai |
|---|---|---|
| D-001 (P0) | « la bannière ne contredit plus la répartition » | Elle la contredisait encore : j'avais restreint le chiffre à la période sans toucher au libellé, qui parlait toujours de « ventes » sur des mouvements de tiroir |
| D-002 (P1) | orphelins exposés côté serveur | L'écran les jetait toujours : 10 % de la période absente des cartes |
| C-001 (P1) | « la table tient sur les deux gabarits du parc » | Vrai des données que j'avais sous les yeux. Faux dès qu'un numéro de commande fait 17 caractères |
| E-013 (P2) | correctif du mur client | J'avais laissé 117 px réservés à l'en-tête que je venais d'en retirer |
| AB-013 (P2) | — | Le harnais Playwright ne fixait ni locale ni fuseau : **aucune** conclusion de cet audit sur les dates ou les nombres n'était fiable |
| AB-014 (P2) | — | Un compteur de synthèse annonçait « 24 tables » sur une page qui en affiche une |

Et un test que j'ai committé hier pour le mur client **ne testait rien** : il visait
`127.0.0.1` alors que la session vit sur `localhost`. Autre origine, aucun cookie, page non
connectée — ses assertions « pas d'en-tête d'admin » étaient trivialement vraies.

Enfin, un constat que j'ai porté deux rondes durant, **E-004 (« deux lots en 404 face
client »), était FAUX** : artefact de mon worktree, pas défaut du produit. Vérifié et
réfuté par le superviseur.

---

## Clos et prouvés (11)

| Réf | Sévérité | Défaut | Preuve |
|---|---|---|---|
| D-001 | **P0** | Bannière : « 7,50 € encaissés » quand la page montrait 5,00 €. Deux grandeurs sous deux libellés quasi identiques | Écran : « Ventes 5,00 € · Mouvements 7,50 € · **Écart −2,50 €** ». 4 mutants tués |
| D-002 | P1 | Grand total ne bouclant pas : 25,00 € et 10 tx invisibles | Écran : 31,20 € = 26,20 + 5,00 ; 4 tx = 2 + 2 |
| D-003 | P1 | Pastille « unknown » en anglais | « Non rattachée » |
| AB-001 | P1 | Le panier **perdait un ingrédient** (Cornichon) ; deux lignes différentes s'affichaient à l'identique | DOM servi : « STO · Algérienne » vs « **STO Cornichon** · Mayonnaise ». 2 mutants tués |
| E-010 | P1 | Le `×2` d'un supplément disparaissait sur l'écran cuisine de repli — un cheddar au lieu de deux | Garde posée sur les 5 sites ; mutation d'UN site tue 2 tests |
| C-001 | P1 | Colonne collante recouvrant le STATUT | 3 essais, 3 mesures. Réserve 160 px + `min-width: max-content`. Date entière, 0 px caché |
| C-002 | P1 | 7 lignes « À encaisser » **sur des commandes annulées** | Écran : « Sans objet » en gris |
| C-003 | P1 | « Type de paiement: » suivi de rien, **y compris sur la facture imprimée** | Ligne effacée quand la valeur manque |
| D-004 | P1 | Code promo collé au statut (« ADMINNOTCAPP-236H**Échec** ») | Écran : code détaché |
| AB-005 | P1 | « 0 seats » en anglais sur le plan de salle | « 0 couvert » / « N couverts » |
| E-005 | P1 | Le mur client portait « Déconnexion » et `admin@lecayenne.fr` | Vérifié par le superviseur : 0 occurrence, page non vidée, ARIA préservé |

Plus, hors comptage : le drapeau de langue en 404 (52 requêtes par campagne), le seau de
débit de la caisse **codé en dur à 120/min** alors que l'exploitant croyait l'avoir réglé à
1000, les trois entrées du menu profil qui ne menaient nulle part, et le français sans
accents jusque **sur le ticket remis au client**.

---

## Ouverts (3 P1)

### AB-003 — format monétaire américain dans l'assistant produit · **GATE PROPRIÉTAIRE**

L'assistant affiche « €7.40 » quand toute l'application affiche « 7,40 € ». Ce n'est pas un
artefact : `public/js/pos-wizard.js` construit la chaîne en dur (`'€' + num.toFixed(2)`).

**Ce fichier est en ZONE GELÉE** (CLAUDE.md §7, « design parfait selon owner »). Je ne le
touche pas sans autorisation explicite. La mise en page et les couleurs de l'assistant ne
sont pas en cause — uniquement le format du nombre.

**Décision attendue du propriétaire.**

### AB-004 — le ticket écrasé en 1024×600

Mesures produites par le harnais lui-même : `corps_panier.hauteur = 28` pour
`hauteur_contenu = 172`. L'en-tête du panier (client, mise en attente, type de commande,
nom, programmation) prend 331 px sur 482. À l'écran, un bandeau blanc vide là où le gabarit
1366 affiche « Aucun article. Sélectionnez un produit dans la grille. »

Aggravant : l'instrumentation calculait `pixels_caches` sur l'en-tête (= 0) et **jamais sur
le corps**. La vague passait au vert alors que ses propres chiffres portaient le défaut.

**Traité en partie.** La mesure manquante est posée — et elle a immédiatement dit PLUS que le
constat : 141 px cachés en 1024×600 comme annoncé, mais aussi **67 px cachés en 1366×768**
dès que le panier contient une vraie composition. Le défaut n'était pas cantonné au petit
écran.

Le bandeau blanc est corrigé : sous 700 px de haut, l'état vide se compacte (icône masquée,
marges resserrées). Contenu 172 → 75 px, cachés 141 → 44. Vérifié à l'écran, « Aucun
article. Sélectionnez un produit dans… » est LISIBLE. La seconde ligne reste coupée.

Ce que je n'ai PAS fait, et pourquoi : rendre sa place au corps du panier. Le plancher y est
retiré **volontairement** quand le panier est vide, parce que ces 108 px manquaient
exactement au champ « Nom du client » — le nom qui s'imprime sur le ticket cuisine, mandat
propriétaire déjà perdu une fois. Rouvrir cet arbitrage est un choix d'ergonomie sur la
surface la plus sensible du produit : il appartient au propriétaire, pas à moi.

### AB-011 — un correctif sans preuve de non-régression

Trois DOM de la vague A sont **identiques au bit près** (md5), et l'état nommé
`05-voir-tout-ligne-simple` n'a **jamais ouvert la fenêtre** (0 nœud
`pos-tracker-contenu-overlay`).

Conséquence : le correctif `f22594f7b` — le panneau « Voir tout » qui suivait une commande
figée pendant le rafraîchissement — n'est démontré non-régressé par **aucune** capture de
cette ronde. « Testé ailleurs » n'est pas une preuve.

À recapturer : « Voir tout » ouvert PENDANT un tick de rafraîchissement, plus un garde-fou
refusant deux états dont le DOM a le même md5.

---

## Mine à retardement (P2, à traiter avant toute bascule)

L'application fabrique ses URL absolues depuis `foodkingConfig.baseUrl` (= `APP_URL`), qui
diffère de l'origine servie. Aujourd'hui la CSP est en `report_only` : rien ne casse, mais
chaque chargement émet des violations et des rapports.

Le jour où `CSP_ENFORCE_MODE` passe à `enforce`, le navigateur **bloque**
`/api/broadcasting/auth` : la cuisine et le mur client cessent de recevoir les commandes, et
le repli par sondage est désactivé (`[PosSyncService] fallback polling disabled`). Les
panneaux continueront d'afficher « Mis à jour à l'instant » sur des données figées.

Correction de fond : émettre ces URL en relatif. À défaut : ajouter l'hôte d'`APP_URL` à
`connect-src` ET `img-src`, puis rejouer une campagne complète avec `CSP_ENFORCE_MODE=enforce`
**avant** la bascule.

---

## Vérification

### PHPUnit : 11 rouges, et une affirmation que je dois corriger

J'ai rapporté en cours de ronde que « la suite PHPUnit complète est passée (code 0) ».
**C'était faux.** Je m'étais fié au code de sortie de l'enveloppe qui l'avait lancée, sans
lire le résultat.

L'état réel : **11 échecs, 2 incomplets, 36 ignorés, 5248 verts.**

Ces 11 échecs sont **antérieurs à mon travail**, et je l'ai établi plutôt que supposé :

- **10 des 11 passent EN ISOLATION.** Ce sont des artefacts d'ordonnancement de la suite
  complète (pollution entre tests), pas des défauts : PrinterController ×3,
  PrinterHostAllowlistSentinel, RolePermissionSeeder ×3, WithoutGlobalScopesAuditSentinel ×2,
  Zone5PricingSsotConvergenceSentinel.

- **Le 11ᵉ est réel** : `IdempotencyRequiredRoutesCoverageTest` signale trois routes portant
  le middleware `idempotency` sans figurer dans `config('idempotency.required_routes')` —
  `raw-materials/{rawMaterial}/adjust`, `pos-loyalty/credit-manual`,
  `pos-loyalty/deduct-manual`. Le blame les date du **2026-08-14**, dix jours avant le début
  de cette mission, et mon diff ne touche aucune de ces lignes. Hors de mon périmètre : je le
  signale, je ne le corrige pas en douce.

Les deux campagnes complètes (avant et après mes derniers commits) annoncent le même compte
de 11.

### Le reste

- Vitest : **450 fichiers, 3685 verts**, 0 rouge
- Zones gelées : **0 ligne** touchée sur les 33 commits de la mission
- 4xx/5xx sur l'ensemble des captures : **0** (contre 67 en début de ronde — tous artefacts
  d'environnement, désormais réparés)
- Erreurs console : uniquement les ponts d'impression locaux (9100/9101), absents par nature
  du poste de test

## Prochaine ronde

1. Décision propriétaire sur AB-003 (zone gelée)
2. AB-004 — ergonomie de l'en-tête du panier en 1024×600, **et** ajouter `pixels_caches` sur
   le corps du panier, sinon le même défaut repassera au vert
3. AB-011 — recapturer avec un garde-fou anti-doublon de DOM
4. Puis deux cycles complets identiques avant de parler de convergence
