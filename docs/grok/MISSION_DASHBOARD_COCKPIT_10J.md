# MISSION — Dashboard = cockpit du restaurant (10 jours, pas de plafond)

FoodKing V1 LOCAL « Le Cayenne » · voie GROK · 2026-08-30

**Ce fichier est la SSOT d’exécution.** Quand le propriétaire dit `GO`, l’agent
tourne **uniquement** cette mission jusqu’à convergence ou escalade humaine.
Pas de « je fais un peu le kiosk ». Pas de 100 clones. Pas de wipe.

---

## 0. Ce que Khadija veut (traduction fidèle)

Le Dashboard n’est pas un tableau de chiffres. C’est **le poste de commandement**
du restaurant. Depuis cet écran, un gérant doit :

1. **Voir** que tout le système est vivant (caisse, borne, cuisine, files, sauvegarde, fiscal).
2. **Contrôler** ce que la caisse a le droit de faire, ce que la borne affiche, ce
   que le client compose (viande, sauce, extra, photo, prix).
3. **Changer** une logique et que le changement soit **immédiat, vrai, sans
   duplication fantôme, sans 2xx menteur** : une sauce gratuite puis les suivantes
   payantes, une photo par option, une catégorie clonée avec une autre logique,
   un produit qui n’hérite pas bêtement du voisin.
4. **Garder l’illogique dehors** : un caissier ne pilote pas le cockpit, un
   composeur vide ne mélange pas viande et sauce, un extra sans photo n’invente
   pas un plat, un interrupteur « borne » ne ment pas.
5. **UX** : chaque geste est fluide, rapide, en français, palette Cayenne, pas
   de raw label, pas de tuile à 0,00 € quand l’API a planté.

Qualité > vitesse. Boucle jusqu’à ce que ce soit vrai. **Minimum 20 cycles
audit → test → correctif → E2E → adversaire → mini-optimisation par FONCTION.**
Pas de limite d’itération. 10 jours = enveloppe, pas un stop.

---

## 1. Ce qui est DÉJÀ vrai (ne pas re-patcher)

Preuves parent-lues, 2 rounds consécutifs propres où indiqué.

| Geste | État | Preuve |
|---|---|---|
| Caissier tape `/admin/observability/system` | redirige dashboard, pas de Vue cockpit | `CONVERGENCE_WAVE_E.md` + opt-3 |
| Studio rayons | 14 réels, plus d’AUDIT/E2E/faker | `CONVERGENCE_ADMIN_SURFACES.md` |
| KPI « articles menu » | 55, hors interne/E2E | improve-3/4 |
| Studio « Toutes les catégories » | **55**, aligné KPI ; interne reste dans le rail | `CONVERGENCE_IMPROVE_34.md` |
| Featured | pas d’item `E2E_PLAYWRIGHT` | PHPUnit + PNG |
| Menu « Tableau de bord » | plus de title-case CSS | PNG |
| Drapeau admin | `/images/language/english.png` 200 | PNG |
| Projection `source_ref` vide | 0 choix, pas viande+sauce | `ComposerMerchantLiesTest` |
| Addon id numérique | cette ligne, pas toutes les boissons | idem |
| Chef/Waiter/Stuff | pin métier, dummy ids 422 | `ProtectedRolePermissionsCannotBeWipedTest` |
| Mix admin | `admin-shell.8468e026.js` | 2026-08-29 |

**Interdit de re-ouvrir** : fail-open permission 4e fois, 3e masque catalogue,
wipe SQL des 64 junk, flip `FEATURE_WIZARD_PER_ITEM_DEMO`, contourner frozen.

P2 encore ouverts (à traiter **dans cette mission**, pas à nier) :

- Login page encore 1× `/storage/1/english.png` 404 (pas BackendNavbar).
- Debugbar recouvre le bas (`APP_DEBUG` — owner, ne pas flipper `.env` sans GO).
- 64 catégories junk **en base** (masque, pas DELETE).
- Borne / POS **ne passent pas** par `ItemCategoryService::list()` (voie Claude / gelé).
- 55 ≠ ~45 carte « vendable pure » (extras ACTIVE).
- Audit trail NF525 trop long sur le dashboard (lisibilité).
- Interrupteurs = `Log::info`, pas journal fiscal (ne pas toucher `AuditLogService`).

---

## 2. Frontières (non négociable)

Claude = Big Boss. Lecture seule : `CLAUDE.md`, `~/.claude/**`, `.claude/**`.

**Jamais** (même « pour tester ») :

- Frozen `CLAUDE.md` §7 : kiosk wizard/app/upsell, `PaymentComponent`, `PosV5TrancheRow`, `pos-wizard.js/css`, `admin-pos-v4.blade.php`, fiscal sequence/Z/audit.
- NF525 §8 : `PricingService`, `composition_snapshot`, chaîne HMAC.
- `migrate:fresh` / `db:wipe` / `menu:reset` / commandes de test sur la chaîne live.
- Inventer un produit. SSOT : `items` status ACTIVE.
- `git add .`, push, `--no-verify`.
- Éditer un fichier voie Claude en parallèle.

**Contrôle borne / caisse depuis le Dashboard** = régler **admin** (interrupteurs,
permissions, composeur, catalogue, pages) — **pas** muter le Vue kiosk/POS frozen.
Si un geste exige un fichier frozen → **LOCK + gate owner**, stop, pas de bricolage.

SHARED : déclarer dans `reports/grok/JOURNAL.md` avant d’écrire
(`DashboardController`, `CatalogStudioComponent`, `BackendNavbar`, `app.css`,
`InterrupteurController` déjà claimés). Un écrivain par fichier.

---

## 3. Architecture « Dashboard = cockpit »

Le Dashboard (`/admin/dashboard`) est le **hub**. Chaque tuile / accès rapide /
interrupteur est une **fonction**. Une fonction n’est verte que si :

```
geste Dashboard → écran métier → mutation réelle →
surface qui LIT (caisse OU projection POS OU API) voit la même chose →
E2E PNG lu par le parent → adversaire P0+P1=0 → 20e cycle propre
```

### 3.1 Carte des fonctions (à couvrir TOUTES)

**A. Vue cockpit (dashboard lui-même)**  
A1 KPI ventes/commandes/articles/ticket  
A2 Accès rapides (POS, commandes, kanban, encaisse, historique, cuisine, catalogue, stock, Z)  
A3 Suivi en direct  
A4 SLA cuisine  
A5 Canaux (Web / Kiosk / POS)  
A6 Audit trail (lisible, pas 80 lignes login)  
A7 Stats commandes / CA / résumé  
A8 Dernier Z  
A9 Mis en avant / populaires  
A10 Stock bas  
A11 PDF clôture  
A12 Interrupteurs (via État du système, lien admin)  
A13 Santé (DB, cache, realtime, fiscal, file, backup, scheduler)

**B. Contrôle d’accès**  
B1 Admin vs POS Operator vs Chef vs Waiter vs Stuff  
B2 Deep-link interdit  
B3 Interrupteurs GET/PUT Admin only  
B4 Pages CMS `permission:settings`  
B5 Caissier : peut-il changer un paramètre **caisse** légitime (remise F1) sans ouvrir le cockpit ?

**C. Catalogue (studio + catégories)**  
C1 14 rayons Le Cayenne, photos, prix backend  
C2 Duplication de **catégorie** (clone + nouvelle logique, pas deux IDs qui partagent le même wizard publié)  
C3 Duplication de **produit** (fork composeur, pas mutation du publié)  
C4 Photos d’option (viande, sauce, extra) : affichées, pas placeholder menteur  
C5 Inactif : plus en caisse, reste éditable  
C6 Wizard **catégorie** → tous les produits de ce rayon (et seulement eux)

**D. Composeur — le cœur métier**  
Pour **chaque type** de rayon réel (ne pas inventer) :

| Rayon | Logique attendue (à vérifier, pas à inventer si le menu dit autrement) |
|---|---|
| Tacos | N viandes selon taille, sauces, extras ; souvent 1e extra gratuit puis payant |
| Sandwichs / Cayenne | Viande + crudités + sauces + extras |
| Burgers | Variantes / extras, pas de wizard tacos collé |
| Galette | Logique propre, pas le template tacos |
| Bols | Composants bol |
| Frites / Suppléments | Souvent **simple** (pas d’étapes) ou extra only |
| Boissons / Desserts / Menu enfant | Liste + photo, pas de viande |
| Technique interne | Upsell, **pas** dans le compteur « carte » |

Chaque step composeur doit être **contrôlable** :

- `source_type` + `source_ref` **liés** (vide = 0 choix, jamais « tout »)
- `min_select` / `max_select` (0 gratuit, 1 obligatoire, N payant)
- extra : **première gratuite puis suivantes payantes** si c’est la règle du rayon — **prouver** dans PricingService (SSOT prix, lecture) + projection ; **ne pas** recalculer un prix en Vue
- addon : id **ou** rôle, jamais les deux mélangés
- photo : media de l’option, fallback image catégorie, jamais un tacos sur une boisson
- `visible_on` pos / kiosk / web
- publish = clones **nouveaux** ; unpublish = reverse snapshot ; PUT publié = **fork**
- dupliquer une catégorie = nouveau template + `source_ref` rebind, pas « le premier produit du rayon »

**E. Réglages borne (sans toucher le Vue frozen)**  
E1 Interrupteurs : codes promo borne, impression ticket, roue, paiement multi  
E2 Confirmer avant bascule  
E3 Le caissier **ne bascule pas**  
E4 Après bascule Admin : la **lecture** API kiosk/config (pas le fichier Vue) reflète la valeur  
E5 Si l’API n’existe pas / frozen → documenter BLOCK owner, ne pas patcher kiosk

**F. Réglages caisse (sans pos-wizard.js)**  
F1 Interrupteur remise manuelle, split paiement  
F2 Permission `pos` conservée  
F3 Vérifier l’effet par **API** / écran admin, pas en cliquant le wizard frozen

**G. Pages / rôles / attributs / extras / addons / variations**  
Chaque CRUD : créer, modifier, self-save unique, supprimer métier interdit si lié, i18n, 422 pas 500.

---

## 4. Protocole de boucle (abusif, déterministe)

### 4.1 Unité = FONCTION (A1, C2, D-tacos-extra, …)

Pour **chaque** fonction :

```
cycle 1..20 minimum :
  1. Reproduire le geste commerçant (vrai navigateur, compte réel)
  2. Quartette E2E : PNG + DOM + console + network
  3. Parent LIT le PNG (sous-agent caption ≠ preuve)
  4. Adversaire (P0/P1 bloquent)
  5. Reasoner (toujours la mission ? circular ? unproven ?)
  6. Si P0/P1 : test rouge d’abord → correctif scope-minimal → PHPUnit/Vitest
     → Mix si Vue → recapture
  7. Mini-optimisation (liste §6) seulement si P0+P1 = 0 sur ce cycle
  8. Confirmation : 2 rounds consécutifs identiques P0+P1=0
```

**20 cycles** = 20 passages complets, pas 20 screenshots du même écran idle.
Si au cycle 20 c’est encore rouge → continuer (pas de cap) jusqu’à 2 verts
consécutifs **ou** gate owner (frozen / G-DATA / NF525).

### 4.2 Mini-optimisations (23, massives, pas cosmétique)

À appliquer **par surface** une fois le geste vrai, puis re-E2E :

1. Palette Cayenne `#F4501E` / `#FFB800` / `#1A1A1A`  
2. Pas de `capitalize` CSS sur titres FR  
3. Empty state honnête (pas 0,00 € si failed)  
4. Loader jusqu’à **toutes** les tuiles  
5. Cible tactile ≥ 44px  
6. Focus visible clavier  
7. `aria-live` sur bascule / erreur  
8. Confirm avant PUT interrupteur  
9. i18n : zéro clé brute (`studio.*` seulement si prefix autorisé ou SHARED déclaré)  
10. Photos : ratio, alt, pas de 404  
11. Pas de duplication visuelle KPI  
12. Audit trail : 20 dernières lignes métier, pas 80 logins  
13. Requêtes : pas de N+1 nouveau  
14. Mix à jour **avant** de déclarer l’écran = source  
15. Same-origin assets (plus de `/storage/1/english.png` sur login)  
16. Badge compteur = même formule que KPI  
17. Deep-link fail-closed  
18. 409 si publish concurrent (envoyer `version`)  
19. Clone catégorie : nouveau `source_ref`, test anti-partage  
20. Extra 1 gratuit / N payant : preuve Pricing + ticket  
21. Caissier vs Admin : matrice permissions PNG  
22. Pas deux writers sur un fichier  
23. JOURNAL + findings JSON à chaque vague

### 4.3 Agents (en parallèle lectures, séquentiel écriture)

| Rôle | Quand |
|---|---|
| `explore` | cartographie file:line avant patch |
| `e2e-hunter` | 1 vague = 1 surface, Playwright CLI, Node 22, BASE 8766 |
| parent | **lit chaque PNG** |
| `adversary` | après chaque vague et après chaque fix |
| `ux-critic` | après PNG (UI → UX → opt) |
| `reasoner` | après adversaire, avant le fix suivant |
| `code-editor` | un seul, grok-owned / SHARED déclaré |

Pas 100 agents. **Assez** pour couvrir les vagues en parallèle **disjointes**.
Deux hunters ne cliquent pas le même login Mix en même temps si collision session :
contexts isolés Playwright CLI.

### 4.4 Preuves

- PHPUnit : `safe-test.sh --check` puis `--filter=` (jamais `php artisan test` nu).
- Vitest filtré.
- E2E : `reports/test-e2e/grok-dashboard-cockpit-10j/round-N/wave-X/`
- JOURNAL : `reports/grok/JOURNAL.md`
- Convergence d’une fonction : `CONVERGENCE_<FONCTION>.md` seulement si 2 rounds
  P0+P1=0 **et** parent a lu.
- `CONVERGENCE_FINAL.md` **uniquement** quand **toutes** les fonctions A–G ont
  leur fiche. Sinon c’est un mensonge.

### 4.5 Comptes / produits

- Admin : `admin@lecayenne.fr` / `123456`
- Caissier : `pos@lecayenne.fr` / `123456`
- Produits réels seulement : Tacos XL, Galette Classique, Big Burger, Sandwichs,
  Frites, Coca si présent en `items`.
- **Ne pas** créer de commande live NF525. Pour « la caisse voit le wizard » :
  projection PHPUnit + éventuellement lecture API menu, **pas** encaissement.

---

## 5. Ordre d’attaque (DAG)

Ne pas commencer D-tacos si C1 est encore rouge.

```
Vague 0  Préflight serve 8000 + proxy 8766 Host conservé + Mix hash
Vague 1  A cockpit lecture (A1–A13) — 20 cycles, opt 23
Vague 2  B accès (Admin vs Caissier vs Chef) — deep-link + interrupteurs
Vague 3  C studio (déjà 55/14 : ne pas casser ; duplication catégorie = NOUVEAU)
Vague 4  D composeur Tacos (source_ref, extra gratuit/payant, photos, publish/fork)
Vague 5  D composeur Sandwichs / Burgers / Galette (logiques DISJOINTES)
Vague 6  D Boissons / Desserts / Menu enfant / Frites (liste+photo, pas tacos collé)
Vague 7  E interrupteurs borne (API, pas Vue kiosk)
Vague 8  F interrupteurs caisse (API, pas pos-wizard)
Vague 9  G CRUD attributs extras addons variations pages rôles
Vague 10 Recapture globale Dashboard → chaque accès rapide (régression)
Vague 11 Adversaire global + reasoner : duplication ? 2xx sans mutation ?
Vague 12 Deux rounds propres GLOBAUX → CONVERGENCE_FINAL ou BLOCK owner
```

**Intérieur de chaque vague** : 20 cycles min. Si une fonction bloque frozen →
la marquer BLOCK, **continuer les autres**. Partial > wrong.

---

## 6. Gestes commerçant à MORNDRE (tests)

Écrire le test **avant** le patch. Rouge qui nomme la cause.

1. Dupliquer catégorie Tacos → Galette **ne doit pas** partager `source_ref` d’un extra tacos.  
2. Extra step `min=0` première gratuite, 2e payante : PricingService (lecture) + pas de prix Vue.  
3. Publish catégorie vide → **pas** 200 « publié ».  
4. PUT wizard publié → fork, l’ancien id POS inchangé.  
5. Photo sauce Algérienne ≠ photo Tacos XL.  
6. Caissier PUT interrupteur → 403. Admin PUT + confirm → API relit `actif`.  
7. Produit nouveau dans Sandwichs **après** publish catégorie : soit clone, soit message vrai « republier », **pas** toast publié.  
8. `simple` / `custom` n’efface pas un brouillon existant sans dire.  
9. Login flag : plus de 404 `/storage/1/english.png` (page login, SHARED).  
10. Audit trail dashboard : pas de wall of logins (limite + filtre actions métier).

Si le code actuel **contredit** le menu Le Cayenne → **ne pas inventer** la
règle : lire `items` + composeur publié + JOURNAL, puis appliquer **la règle
du rayon réel**.

---

## 7. Gardes d’illogique (checklist adversaire, chaque cycle)

- [ ] 2xx sans mutation  
- [ ] 500 au lieu de 422  
- [ ] `source_ref` vide = tout le catalogue  
- [ ] Addon id traité comme rôle  
- [ ] Deux catégories, un seul wizard live  
- [ ] Prix calculé en frontend  
- [ ] Caissier voit / bascule cockpit  
- [ ] Mix stale (écran ≠ source)  
- [ ] PNG non lu par le parent  
- [ ] Même finding re-patché 3 fois sans cause (escalade)  
- [ ] Fichier frozen dans le diff  
- [ ] `git add .`

---

## 8. Definition of done (qualité, pas la date)

La mission est **finie** seulement si :

1. Chaque fonction A–G a `CONVERGENCE_<id>.md` (2 rounds propres).  
2. Un gérant Admin, depuis Dashboard, peut : lire la santé, ouvrir catalogue,
   publier un wizard rayon, dupliquer une logique **sans coller** Tacos sur
   Galette, basculer un interrupteur, et un Caissier **ne peut pas** faire
   les bascules Admin.  
3. Adversaire final P0+P1 = 0 **et** reasoner `PROCEED` / `DONE`.  
4. JOURNAL à jour. Mix rebuild si Vue.  
5. Frozen diff = 0. Pas de commande test NF525.

Sinon : **pas** `CONVERGENCE_FINAL`. Dire BLOCK + fichier + gate.

---

## 9. Lancement

```
GO MISSION_DASHBOARD_COCKPIT_10J
```

L’agent : lit ce fichier + `FRONTIERES.md` + `JOURNAL.md` § derniers verts →
Vague 0 préflight → Vague 1. Ne demande pas de clarifying questions.
Tourne. Qualité. Boucle.

---

## 10. Hors-scope assumé (owner)

- Éditer `KioskWizardComponent.vue` / `pos-wizard.js`  
- Worker `notifications` (1490 jobs)  
- `FEATURE_WIZARD_PER_ITEM_DEMO=true`  
- DELETE des 64 catégories junk  
- Multi-tenant / cloud
