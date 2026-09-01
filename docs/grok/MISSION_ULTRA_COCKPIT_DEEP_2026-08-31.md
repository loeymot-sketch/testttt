# MISSION ULTRA — Dashboard commande tout le restaurant

FoodKing V1 LOCAL Le Cayenne · voie GROK · **2026-08-31**  
**SSOT d’exécution** (plus lourd que `MISSION_DASHBOARD_COCKPIT_10J.md`).  
Plan d’architecture parallèle : `plans/GOAL_DASHBOARD_COCKPIT_MAXOPT_2026-08-31.md`.

Quand l’owner dit `GO MISSION_ULTRA` : tourner **ce fichier** jusqu’à `CONVERGENCE_FINAL` ou gate.  
Pas de plafond d’itération. Qualité. 20 cycles **par fonction**. Adversaire + reasoner + PNG lu par le parent.

---

## 0. Intention (Khadija)

Le Dashboard est le **poste de commandement**. Un gérant doit, **sans toucher la borne ni le JS caisse frozen** :

1. Voir la vérité (KPI, file, backup, Z, featured).
2. Contrôler **chaque rayon** : pages du wizard, 1re sauce gratuite, extras payants, photos, min/max, POS/kiosk.
3. Dupliquer une logique **sans coller** Tacos sur Galette / Burger / Bol.
4. Régler caisse vs borne (interrupteurs, permissions) sans illogique.
5. UX Cayenne, FR, fluide ; 2xx sans mutation = interdit.

---

## 1. Interdits (même 10 jours plus tard)

- Frozen `CLAUDE.md` §7 (kiosk Vue, `pos-wizard.js`, PaymentComponent, fiscal).
- `migrate:fresh` / wipe 64 junk / commandes NF525 live.
- Inventer un produit. SSOT `items` status=5.
- Flip `FEATURE_WIZARD_PER_ITEM_DEMO`.
- Prix en Vue. Worker 1490 jobs. `git add .` / push.
- 4e patch fail-open menu. 3e masque catalogue.

Contrôle borne/caisse = **composeur publié + interrupteurs + RBAC**.

---

## 2. Déjà VERT (ne pas re-casser)

| Preuve | Quoi |
|---|---|
| `CONVERGENCE_WAVE_E` | Caissier deep-link → dashboard |
| `CONVERGENCE_IMPROVE_34` | Studio 14 rayons, KPI **55**, flag public |
| `ComposerMerchantLiesTest` 18 | `source_ref` vide ≠ tout ; extra `step_key` ; addon id |
| `round-2/wave-D` | Tacos wizard 7 pages depuis Dashboard |
| `round-3/wave-D-galette` | Galette **5** pages, **pas** viande_2 / taille / formule |
| Audit 20 | Plus de mur Connexion |

---

## 3. Carte LIVE des wizards (tinker 2026-08-31)

| Cat | Nom | Items ACTIVE | Publié | Draft | Pages réelles (source_ref) | Mensonge à fermer |
|---|---|---|---|---|---|---|
| 1 | Sandwichs | 5 | **id34 v2** | id22 v3 | pain **vide+inactif**, viande, **viande_2**, sauce 1ère gratuite, crudite, supplement | Viande 2 sur un sandwich ? Pain mort. UI Brouillon vs v2 live |
| 2 | Galette | 3 | **id36 v9** | id24 v3 | pain **vide+inactif**, viande, sauce, **crudite**, supplement | Pain inactive. Preview Collier (Mix HS) |
| 3 | Sandwich Classique | 0 | id35 v2 | id23 v3 | même squelette que Sandwichs | 0 articles — wizard orphelin |
| 4 | Burgers | 6 | **id37 v2** | id25 v3 | pain inactif, viande, sauce, crudite, supplement — **pas** viande_2 | Pain mort |
| 5 | Tacos | 3 | **id38 v4** | id26 v3 | taille **vide**, 3 viandes, sauce 1ère gratuite, garnitures **vide**, supplements via step_key, menu addon | Taille 0 choix (M/L/XL = 3 items). Garnitures 0 extras. Même photo M/L/XL |
| 6 | Bols | 2 | **id39 v2** | id27 v3 | pain inactif, viande **vide+inactif**, sauce **Sauce bol**, garnitures vide+inactif, supplement_bol | Pages mortes. `source_ref` viande vide |
| 7 | Frites | 6 | — | — | liste simple | Ne **pas** coller template tacos |
| 8 | Suppléments | 10 | — | — | extras eux-mêmes | |
| 9 | Desserts | 3 | — | — | simple | |
| 10 | Boissons | 15 | — | — | simple | |
| 11 | Menu enfant | 2 | — | — | simple / menu | |
| 21 | Tacos Signature | 0 | — | — | | rayon vide |
| 27 | Technique interne | 3 | — | — | upsell | **hors** KPI 55, **dans** le rail |
| 66 | Uber technique | 0 | — | — | | |

Templates code (`ComposerTemplateService::TEMPLATES`) : `simple, sandwich, tacos, assiette, snacking, menu, custom`.  
Aliases extras : `garnitures→crudite`, `supplements→supplement`.

**1re sauce gratuite** : attribut `Sauce (1ère Gratuite)` prix 0 + extra « sauce supplémentaire » 0,50. **Pas** une formule Vue.

---

## 4. G1 Mix (bloque l’écran composeur)

`webpack@5.110.1` : `SizeFormatHelpers.js` **absent** → `npx mix` fail.  
Source déjà honnête (`Aucune source — page vide en caisse`, `previewBranches` id=1) **≠** PNG (Collier, « Toutes les op »).

**Cycle 0 obligatoire** : Mix vert **avant** de déclarer l’UI wizard convergée.

---

## 5. Fonctions (20 cycles chacune)

Unité = une ligne ci-dessous. Cycle = geste Dashboard → PNG+DOM+console+network → parent lit → adversaire → reasoner → test rouge → patch → Mix si Vue → recapture. Mini-opt 23 (GOAL § / mission 10J §4.2) **seulement** si P0+P1=0.

### Bloc A — Cockpit (déjà cycle 2)

A1 KPI 55 · A2 accès rapides · A3 ticket moyen · A6 audit 20 · A8 Z · A9 featured · A12/A13 santé 1490/25j.  
**Reste** : i18n `user.device_revoked` ; login `/storage/1/english.png` ; Mix après G1.

### Bloc B — Accès

B1 deep-link caissier (reconfirm post-Mix).  
B2 PUT interrupteur POS → 403.  
B3 pins POS/Chef/Waiter/Stuff.

### Bloc C — Catalogue

C1 14/55 studio.  
C2 photos Tacos M/L/XL (owner G4).  
C3 **ne pas** cloner live sans GO.  
C4 Sandwich Classique 0 items : masquer wizard ou 422 honnête.

### Bloc D — Composeur (le gros)

Pour **chaque** cat 1,2,4,5,6,7,10 — depuis Dashboard → Catalogue → Wizard :

| ID | Geste | Vert ssi |
|---|---|---|
| D-tacos | cat 5, item 234 Tacos XL | Projection : viande 7, sauce 14, supp 10. UI : pas « Toutes les options » si Mix. Taille expliquée (3 SKU). Garnitures 0 = data, pas un mix supplement |
| D-galette | cat 2 | 5 pages, **0** viande_2. Pain : activer **ou** cacher step inactif till |
| D-sandwichs | cat 1 | ** trancher viande_2** : soit légitime 2e viande sandwich, soit retirer du **brouillon puis publish** (pas silent till lie). Pain |
| D-burgers | cat 4 | 5 pages sandwich-like, pas 3 viandes tacos |
| D-bols | cat 6 | sauce bol + supplement_bol ; pages inactives **pas** en caisse |
| D-frites | cat 7 | **simple** : 0 page viande |
| D-boissons | cat 10 | liste + photo, 0 tacos |
| D-fork | PUT publié | nouveau id, POS garde l’ancien |
| D-empty-pub | publish 0 items | pas 200 menteur |
| D-version | POST publish `version` | 409 si stale |
| D-preview | Filiale | **Le Cayenne id=1** seulement |
| D-draft-banner | Brouillon | phrase « caisse lit encore publié » visible |

Tests d’ancrage :

- `tests/Feature/Grok/ComposerMerchantLiesTest.php`
- `tests/js/composerEditorV2.spec.js`
- `(to be created)` `tests/Feature/Grok/InactiveComposerStepHiddenOnTillTest.php`
- `(to be created)` `tests/js/composerPreviewBranchLeCayenne.spec.js`
- `(to be created)` `tests/Feature/Grok/SandwichPublishedMustNotCarryTacosViande2Test.php` **si** owner tranche « viande_2 illégitime »

### Bloc E — Interrupteurs

Whitelist : split, roue, remise, fidélité, promo borne, ticket auto.  
Pas `idempotency.enabled`. Confirm + round-trip test avec restore.

### Bloc F — CRUD settings

Unique self-save, extras group, attribute destroy : tests Grok déjà là — 20 cycles = E2E depuis Dashboard → Attributs / Extras.

---

## 6. Ordre d’attaque

```
0  Mix (G1) — BLOQUE D-preview / D-empty-label écran
1  Recapture A cockpit × 2
2  B caissier interrupteur 403
3  D-galette cycle 2 (déjà cycle 1 PNG)
4  D-burgers + D-sandwichs (décision viande_2)
5  D-bols + D-frites + D-boissons
6  D-fork / version / empty-pub (PHPUnit d’abord)
7  E interrupteurs round-trip
8  Recapture globale Dashboard → chaque accès (sauf paiement POS)
9  2 rounds identiques P0+P1=0 → CONVERGENCE_FINAL
```

Heal max 3 / même cause → `STUCK_*.md` + owner. Interrupt : `INTERRUPT_W*_*.md`.

---

## 7. Comptes / BASE / preuves

- Admin `admin@lecayenne.fr` · Caissier `pos@lecayenne.fr` · `123456`
- BASE `http://127.0.0.1:8766` (proxy Host conservé) → 8000
- Preuves : `reports/test-e2e/grok-dashboard-cockpit-10j/`
- JOURNAL : `reports/grok/JOURNAL.md`
- Parent **lit** chaque PNG. Sous-agent caption ≠ preuve.

Produits autorisés seulement : Tacos XL/L/M, Galette Classique/Cayenne/Normale, burgers du `items`, Frites, Coca si présent.

---

## 8. DONE

`CONVERGENCE_FINAL.md` seulement si :

1. Mix vert (écran = source wizard).  
2. Galette / Tacos / Burgers / Sandwichs / Bols / Frites / Boissons : logiques **disjointes** prouvées PNG + projection.  
3. Caissier ≠ interrupteur / cockpit.  
4. Frozen diff 0.  
5. 2 rounds globaux P0+P1=0.  
6. Adversaire + reasoner PASS.

Sinon BLOCK (G-DATA, frozen, Mix). Partial > wrong.

```
GO MISSION_ULTRA
```
