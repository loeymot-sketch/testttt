# GOAL — CONSOLIDATION V1 PRODUCTION
## FoodKing / Le Cayenne — clôture supervision caisse, dette E2E, EOL Laravel, escalades

- **Slug** : `CONSOLIDATION_V1_PRODUCTION_20260825` · **Auteur** : Claude Code (superviseur), 2026-08-25
- **HEAD** : `43b120c7d` — branche `pos/category-first-caisse-2026-06-23`
- **Cycle amont** : `CAISSE-SUPERVISOR-CONTROL-20260823` (REPLAN_1..9 exécutés)

---

# §0 — PRÉAMBULE

## §0.1 — Décision arbre de travail (OBLIGATOIRE, à trancher en W0)

État constaté le 2026-08-25 par `git status --porcelain` :

| Mesure | Valeur |
|---|---|
| Fichiers modifiés (`^ M`) | **74** |
| Fichiers non suivis (`^??`) | **46** |
| Diff zone gelée (13 fichiers CLAUDE.md §7) | **0 ligne** — vérifié |

**Décision : `include-in-scope` — l'arbre sale N'EST PAS nettoyé.**

Justification : l'arbre porte le travail du cycle CAISSE **et** des modifications du
propriétaire et d'autres cycles. La mission amont interdit « toute commande de nettoyage Git
ou DB destructive » et impose « préserve le worktree sale ».

Conséquences non négociables :
- ⛔ Aucun `git checkout .`, `git reset --hard`, `git stash`, `git clean`.
- ⛔ Aucun `git add .` ni `git add -A` (CLAUDE.md §3quater) — chemins explicites uniquement.
- ✅ W0 crée `backup/pre-consolidation-2026-08-25` **sans changer de branche active**
  (`git branch <nom>` seul, pas de `checkout`).
- ✅ Chaque vague commite ses propres fichiers, nommés un par un, et laisse le reste intact.

## §0.2 — Périmètre : ce que ce GOAL ferme, ce qu'il ne ferme pas

**DANS** — les 6 systèmes de §1 (§3..§8) : exactement ce que la supervision du cycle CAISSE a
laissé ouvert, escaladé ou partiellement rouge.

**HORS**, déclaré pour qu'on ne l'oublie pas :
- `mobile/` et `/Users/1millnonstop/Downloads/web/` — standalone, **aucun câblage API en V1** (CLAUDE.md §3bis).
- `_teste2e-heal-audit-2026-07-18.spec.js` — nettoyage destructif propre. **Ni exécuté ni modifié ici** ; cycle et gate dédiés.
- Bascule cloud / multi-succursale (UNI-03, scoping réel de `User`) — V2. Ce GOAL **documente**, ne mute pas.

## §0.3 — Drapeaux d'expansion de périmètre (à lever si franchis)

Le superviseur STOPPE et remonte au propriétaire si un seuil est franchi :

| Drapeau | Seuil | Action |
|---|---|---|
| SCOPE-1 | Correctif touchant un des 13 fichiers gelés | STOP → `lock-plan` + contreseing |
| SCOPE-2 | Vague dépassant 3 boucles de soin sur le même amas | STOP → §X.9 |
| SCOPE-3 | Correctif exigeant une migration de schéma | STOP → gate propriétaire, hors périmètre |
| SCOPE-4 | Chaîne NF525 (`audit_logs`) modifiée hors ajout | STOP IMMÉDIAT → gate humain |
| SCOPE-5 | Montée Laravel cassant > 40 tests | STOP → W6 devient un cycle autonome |

## §0.4 — Pipeline par tâche (référence unique, NON redécrite ensuite)

Chaque tâche `T-x.y.z` s'exécute via **`ultra-audit-profond`** — le GOAL ne réécrit pas ce pipeline.
Dérogation de zone gelée → **`lock-plan`**. Audit de page adverse → **`test-e2e`**.

## §0.5 — Critères de convergence (repris de l'Axe 6, appliqués à la lettre)

Tâche, vague, GOAL : TERMINÉS seulement si **aucun** déclencheur n'est actif.

| Déclencheur | Action |
|---|---|
| Étiquette brute à l'écran (`kiosk.X`, `Label.foo`, `0undefined`) | REJET — soin + recapture |
| Casse de mise en page sur un viewport testé | REJET — soin |
| Erreur console navigateur | REJET — cause racine puis soin |
| Ligne de diff en zone gelée | REJET — `lock-plan` ou revert |
| P0 ROUGE non traité | REJET — soin jusqu'à 0 P0 nouveau |
| Test rouge, même connu | DOCUMENTER avec motif, OU réparer |
| Acceptation sans chemin de test nommé | REJET de la section — réécrire |
| « ça marche presque » / « c'est suffisant » | REJET — parfait-production ou blocage |
| Chaîne NF525 modifiée hors ajout | REJET + gate humain immédiat |
| Deux cycles aux constats **différents** | NON convergé — reboucler (anti-flaky) |

**Convergence atteinte = deux cycles consécutifs avec P0+P1 = 0 ET ensembles de constats identiques.**

## §0.6 — Base de référence héritée (déjà vert — à ne pas refaire, à ne pas casser)

Mesuré le 2026-08-25. Toute vague qui dégrade un de ces chiffres est en échec.

| Base | Valeur | Commande de re-mesure |
|---|---|---|
| PHPUnit | **4862 passés / 36 sautés / code sortie 0**, zéro `⨯` | `php artisan test` |
| Vitest | **440 fichiers, 3609 passés, 3 sautés** | `npx vitest run` |
| Lint POS | OK | `npm run pos:lint:status` |
| Diff zone gelée | **0 ligne** | `git diff --stat -- <13 fichiers §7>` |
| Avis composer | **7** (3 paquets) | `composer audit` |
| Specs E2E restaurées | **9 / 11** | voir §6 |

---

# §1 — CARTE DES SYSTÈMES PRINCIPAUX

Ancrages vérifiés le 2026-08-25 par `find`/`ls`/`grep` réellement exécutés.

| # | Système | Maturité | Ancrage réel vérifié | Tests existants |
|---|---|---|---|---|
| S1 | **Caisse POS** | Fonctionnelle, ergonomie **non validée** | `app/Http/Controllers/Admin/PosController.php`, `PosSystemHealthController.php`, `PosOrderController.php` + `resources/js/components/admin/pos/*.vue` | `tests/Feature/Pos/` = **59 fichiers** |
| S2 | **Borne (Kiosk)** | Parcours vert, amorçage **fragile** | `app/Http/Controllers/Auth/KioskMachineLoginController.php`, `Frontend/KioskEventController.php`, `Admin/KioskSetupController.php` + **24** composants `resources/js/components/frontend/kiosk/` | `tests/Feature/Kiosk/` = **9 fichiers** |
| S3 | **KDS / OSS** | Backend correct, **rendu de lane non prouvé** | `app/Http/Controllers/Admin/KitchenDisplaySystemController.php`, `app/Domain/Kds/KitchenReleaseRule.php` | tests KDS = **46 fichiers** |
| S4 | **Harnais E2E** | **Filet non fiable** — dérive de fixtures | `tests/e2e/` = **308 specs**, `tests/e2e/helpers/kiosk-order.js`, `tests/Playwright/global-setup.js` | le harnais *est* le test |
| S5 | **Dépendances & sécurité** | **7 avis résiduels**, Laravel EOL | `composer.json` → `"laravel/framework": "^9.19"`, PHP **8.2.30** | `tests/Feature/Security/` |
| S6 | **Gouvernance & vérité doc** | **Dette accumulée** | `plans/` = **260** fichiers, `docs/gates/` = **50**, `PROJECT_BRAIN.md`, `CONSTITUTION.md`, `SYSTEM_MAP.md` | `tests/Feature/Sentinels/` |

### Sortie d'ancrage brute (preuve anti-fiction)
`tests/Feature/Pos`=59 · kiosk=24 composants · `tests/Feature/Kiosk`=9 · KDS=46 · `tests/e2e`=308 ·
`plans/`=260 · `docs/gates/`=50 · `AUDIT-KIOSK-WAVE-E` référencé par 62 specs, **écrit par 8** ·
`reducedMotion` dans 3 specs · `"laravel/framework": "^9.19"` · PHP 8.2.30 · `composer audit` =
3 paquets / 7 avis.

---

# §2 — SYSTÈME SÉPARÉ (standalone, hors arbre central)

**Roue de la fortune — site public Le Cayenne**
`/Users/1millnonstop/Downloads/lecayenne-web-deploy/Site lecayenne/` ·
`tests-e2e/roue-experience-2026-08-23.spec.js` **34/34 vert** ·
`tests-e2e/roue-fond-carrousel-redirection-2026-08-13.spec.js` **87/87 vert**.
**Aucun code touché.** Seul reste le gate UX propriétaire **G2** (§G) : ce GOAL le porte, ne le tranche pas.

⛔ `mobile/` et `/Users/1millnonstop/Downloads/web/` : hors périmètre (§0.2), aucun câblage API V1.

---

# §3 — SYSTÈME 1 : CAISSE POS
### Contrat
Encaisser vite, voir en un coup d'œil si le système est sain, ne jamais encaisser en aveugle.
Prix calculés backend, jamais navigateur. Piste NF525 intacte à chaque encaissement.

### Zones gelées concernées (ne pas toucher)
`public/js/pos-wizard.js`, `public/css/pos-wizard.css`, `admin-pos-v4.blade.php`,
`PaymentComponent.vue`, `v5/PosV5TrancheRow.vue`, `PricingService.php`, `OrderStateMachine.php`.
Liste canonique : `memory/reference_frozen_zones.md`.

### Ancrages (vérifiés 2026-08-25)
`app/Http/Controllers/Admin/PosController.php`, `PosSystemHealthController.php`,
`PosOrderController.php`, `PosCategoryController.php`, `AdminPosV4Controller.php`,
`PosStockOutflowController.php` · `resources/js/components/admin/pos/PosComponent.vue`
(**7 389 lignes**), `PosSystemHealthPill.vue`, `CaisseSecondaryNav.vue`, `ItemComponent.vue`.

---

## Sub 1.1 — Contrôle temps réel (pastille de santé)

**Ancrages** : `app/Http/Controllers/Admin/PosSystemHealthController.php`,
`resources/js/components/admin/pos/PosSystemHealthPill.vue`,
`resources/js/components/admin/pos/PosComponent.vue`

**Hérité (base, pas travail)** : branche 0 → HTTP 200 + `branch_required` (au lieu d'un 422 opaque) ;
panne socket dure non rétrogradée par un voisin inconnu ; fenêtre de vieillissement 24 h ;
bannières hors-ligne et quarantaine cumulables au lieu de s'exclure.

**Tâches**
- **T-1.1.1** — Prouver la matrice de dégradation de bout en bout (soketi coupé, worker coupé,
  succursale vide) : le caissier voit la bonne phrase à chaque fois.
  • ancrage : `PosSystemHealthController.php` (sondes socket / file / stock) · test : `tests/Feature/Pos/PosSystemHealthTest.php` (19 cas existants — étendre) · visuel : `http://127.0.0.1:8000/admin/pos`
- **T-1.1.2** — La pastille **ne ment jamais par optimisme** : tout état inconnu pèse vers l'avertissement.
  • ancrage : `PosSystemHealthPill.vue` (fonctions `tone()` et `label()`) · test : `tests/js/posSystemHealthPill.spec.js` (16 cas existants — étendre)
- **T-1.1.3** — Couvrir la panne de worker réellement survenue (436 travaux en attente) : la pastille
  le dit, puis redevient verte après redémarrage sans intervention sur le cache.
  • test : *(à créer)* `tests/Feature/Pos/PosSystemHealthQueueRecoveryTest.php`
- **T-1.1.4** — Ajouter une preuve navigateur de la pastille en conditions réelles.
  • test : *(à créer)* `tests/e2e/caisse-sante-degradation-2026-08-25.spec.js`

**Acceptation** : les 4 tests ci-dessus VERTS · capture `/admin/pos` lue et analysée · zéro
étiquette brute · zéro erreur console.

---

## Sub 1.2 — Ergonomie du caissier (le plus visible pour le propriétaire)

**Ancrages** : `resources/js/components/admin/pos/PosComponent.vue` (7 389 lignes),
`CaisseSecondaryNav.vue`, `resources/css/pos-a11y.css`

**Constats déjà mesurés** — à **trancher puis corriger**, pas à redécouvrir :
1. Grille de vente **sous la ligne de flottaison** sur écran de comptoir → défilement pour vendre. **G3.**
2. **F1–F12 fonctionnent**, mais `F1` ne vise **pas** la première tuile ; décalage non documenté.
3. **Portée de la recherche** (nom seul vs nom + catégorie + code) non tranchée. **G4.**

**Tâches**
- **T-1.2.1** — Mesurer, captures à l'appui, ce qui est visible sans défilement sur les viewports de
  comptoir ; produire la recommandation A/B pour G3.
  • visuel : `http://127.0.0.1:8000/admin/pos` · test : *(à créer)* `tests/e2e/caisse-ligne-flottaison-2026-08-25.spec.js`
- **T-1.2.2** — Aligner ou documenter le décalage F1–F12 : un comportement apprenable en une phrase.
  • ancrage : `PosComponent.vue` (gestionnaire clavier) · test : *(à créer)* `tests/js/posRaccourcisTouchesFonction.spec.js` · ⚠️ piège prouvé : `keyboard.press('F2')` est **inerte** en Playwright headless — la preuve doit passer par un test unitaire de composant, pas par un E2E navigateur.
- **T-1.2.3** — Appliquer la portée de recherche retenue en G4, sans élargir au-delà de la décision.
  • test : *(à créer)* `tests/js/posRechercheProduit.spec.js` · fait établi : la recherche est déjà insensible aux **accents**, à la **casse**, et accepte les
    **sous-chaînes**. Les signalements contraires étaient des artefacts (`poulet`/`creme` absents du menu). Ne pas « corriger » ce qui marche.
- **T-1.2.4** — Verrouiller la navigation clavier de la barre latérale (anneau de focus visible).
  • ancrage : `resources/css/pos-a11y.css` · test : *(à créer)* `tests/js/posNavigationClavierBarreLaterale.spec.js`

**Acceptation** : G3 + G4 enregistrées · les 4 tests VERTS · captures avant/après lues et
analysées · `npm run pos:lint:status` OK.

---

## Sub 1.3 — Intégrité de l'encaissement (NF525)

**Ancrages** : `app/Http/Controllers/Admin/PosOrderController.php`,
`app/Services/Pricing/PricingService.php` (GELÉ — lecture seule),
`app/Services/Fiscal/FiscalSequenceService.php` (GELÉ — lecture seule)

**Tâches**
- **T-1.3.1** — Re-prouver que le devis signé lie le total : aucun total ne vient du client.
  • test : `tests/Feature/Pos/` (59 fichiers — cibler le liage de devis)
- **T-1.3.2** — Attester la chaîne fiscale avant/après vague (`SELECT count(*), MAX(current_hash) FROM audit_logs`), ajout-seulement.
  • preuve : consignée au rapport de vague, pas seulement affichée
- **T-1.3.3** — Confirmer les 3 routes d'idempotence ajoutées au cycle CAISSE
  (`pos-loyalty/credit-manual`, `pos-loyalty/deduct-manual`, `raw-materials/*/adjust`).
  • ancrage : `config/idempotency.php` · test : suite `tests/Feature/` sur l'idempotence

**Acceptation** : `tests/Feature/Pos/` VERTE (59 fichiers) · attestation NF525 ajout-seulement
consignée · zéro diff sur les services fiscaux gelés.

---

# §4 — SYSTÈME 2 : BORNE (KIOSK)
### Contrat
Un client seul commande et paie à la borne. En V1 le paiement est routé au comptoir
(`kiosk.payment_route_all_to_counter=true`, plan B). Jamais d'écran mort.

### Zones gelées concernées
`KioskWizardComponent.vue`, `KioskAppComponent.vue`, `KioskUpsellComponent.vue` (CLAUDE.md §7).

### Ancrages (vérifiés 2026-08-25)
`app/Http/Controllers/Auth/KioskMachineLoginController.php`,
`app/Http/Controllers/Frontend/KioskEventController.php`,
`app/Http/Controllers/Admin/KioskSetupController.php`,
**24** composants dans `resources/js/components/frontend/kiosk/`.

---

## Sub 2.1 — Accessibilité et entrée clavier

**Hérité** : l'accueil accepte `Entrée`/`Espace` et **ignore la répétition** (`evenement.repeat`) —
une touche maintenue empilait les événements. Cartes produit : même garde + garde de chargement.

**Tâches**
- **T-2.1.1** — Étendre la garde de répétition à **tout** composant borne déclenchant une action clavier.
  • ancrage : `resources/js/components/frontend/kiosk/` (24 composants — balayer) · test : `tests/js/kioskIdleKeyboardStart.spec.js` (existant, à élargir)
- **T-2.1.2** — Vérifier l'ordre de tabulation et la visibilité du focus sur le parcours complet.
  • test : *(à créer)* `tests/js/kioskParcoursFocusClavier.spec.js` · visuel : `http://127.0.0.1:8000/kiosk/idle`
- **T-2.1.3** — Prouver l'absence d'étiquette brute (`kiosk.X`) sur les 4 écrans du parcours.
  • visuel : accueil → catégories → composition → récapitulatif

**Acceptation** : les 2 tests VERTS · 4 captures lues et analysées, zéro étiquette brute ·
zéro diff sur les 3 composants gelés.

---

## Sub 2.2 — Amorçage et jeton d'appareil

**Ancrages** : `app/Http/Controllers/Auth/KioskMachineLoginController.php`,
`app/Services/Auth/DeviceTokenService.php`, `resources/js/axios-setup.js`

**Constats prouvés** :
- Révocation **portée par appareil** (`device_id`) depuis 2026-08-07 — ⛔ ne jamais revenir à
  `$user->tokens()->where('name','auth_token')->delete()` : cause du défaut « chaque connexion
  déconnecte les autres écrans ».
- `axios-setup.js:97` **écrase l'en-tête `Authorization`** → origine des 401 de la vague D ;
  toute navigation borne vers une route admin vide le panier non persisté.

**Tâches**
- **T-2.2.1** — Reproduire et clore le 401 d'amorçage borne (`/api/login`) avec une trace, pas une hypothèse.
  • test : `tests/Feature/Auth/MultiDeviceLoginTest.php` (existant) + *(à créer)* `tests/Feature/Kiosk/KioskBootTokenTest.php`
- **T-2.2.2** — Faire tenir par un test — pas par un commentaire — la règle « ne pas naviguer la
  borne vers une route admin ».
  • test : *(à créer)* `tests/js/kioskPanierPersistanceNavigation.spec.js`
- **T-2.2.3** — Confirmer `auth.max_devices_per_user` (10) et l'éviction du terminal le moins récemment actif.
  • test : `tests/Feature/Auth/MultiDeviceLoginTest.php` (étendre)

**Acceptation** : les 3 tests VERTS · trace du 401 consignée avec sa cause racine · aucune
régression sur la révocation par appareil.

---

## Sub 2.3 — Parcours commande → comptoir (plan B)

**Tâches**
- **T-2.3.1** — Prouver le routage du paiement borne vers l'encaissement comptoir.
  • ancrage : `config/kiosk.php` (`payment_route_all_to_counter`) · test : `tests/Feature/Kiosk/` (9 fichiers existants — cibler le routage)
- **T-2.3.2** — Prouver qu'une commande borne allouée en séquence fiscale à la création ne laisse
  **aucun trou** si l'allocation échoue (drapeau `fiscal_alloc_error_at` + reprise cron).
  • test : suite fiscale existante sous `tests/Feature/`
- **T-2.3.3** — Vérifier l'isolation `branch_id` de bout en bout sur une commande borne.
  • test : `tests/Feature/Branch/BranchScopeCoverageSentinelTest.php` (sentinelle existante)

**Acceptation** : `tests/Feature/Kiosk/` VERT (9 fichiers) · sentinelle de succursale VERTE ·
zéro trou de séquence fiscale démontré.

---

# §5 — SYSTÈME 3 : KDS / OSS
### Contrat
Ce qui est commandé **apparaît en cuisine**, sur la bonne station, dans le bon ordre, et
disparaît quand c'est servi. Un ticket invisible est un client non servi.

### Ancrages (vérifiés 2026-08-25)
`app/Http/Controllers/Admin/KitchenDisplaySystemController.php`,
`app/Domain/Kds/KitchenReleaseRule.php`, **46** fichiers de test KDS.

---

## Sub 3.1 — Réception borne → lane cuisine

**Prouvé, non résolu** : vague D — `GET /api/admin/kds-order` **retourne bien la commande**
(`{id:6920, status:4, surface:'kiosk', type:10}`). Le backend est correct ; c'est le **rendu de
la lane** qui n'est pas prouvé. Spec laissée rouge délibérément plutôt que maquillée en vert.

**Tâches**
- **T-3.1.1** — Isoler la couche fautive : API correcte + lane vide ⇒ défaut de rendu ou de
  filtrage côté écran. Produire la preuve, pas l'intuition.
  • ancrage : `KitchenDisplaySystemController.php` + composant d'affichage KDS · test : `tests/e2e/test-e2e-kiosk-kds-sync-2026-05-11-wave-D.spec.js` (rouge — à ramener au vert) · visuel : `http://127.0.0.1:8000/kds`
- **T-3.1.2** — Couvrir le cas par un test de composant : la régression ne dépend plus d'un navigateur.
  • test : *(à créer)* `tests/js/kdsRenduLaneCommande.spec.js`
- **T-3.1.3** — Prouver le trajet caisse → KDS (jambe POS de la vague F, aujourd'hui partielle).
  • test : `tests/e2e/test-e2e-pos-kds-sync-2026-05-10-wave-F.spec.js` (partiel — à ramener au vert)

**Acceptation** : vagues D **et** F VERTES, ou documentées avec cause racine nommée + décision
propriétaire · `kdsRenduLaneCommande.spec.js` créé et VERT.

---

## Sub 3.2 — Filtrage par station

**Constat** : le KDS filtre par `kds_station` (`cuisine_chaude`, `cuisine_froide`, `bar`, `none`).
Un article en `none` est **invisible** en cuisine — piège qui a fait échouer mon premier résolveur.

**Tâches**
- **T-3.2.1** — Vérifier qu'aucun article vendable du menu V1 ne se retrouve en `none` par accident.
  • preuve : requête sur la table `items`, résultat consigné · test : *(à créer)* `tests/Feature/KDS/KdsStationCouvertureTest.php`
- **T-3.2.2** — Rendre le cas `none` **visible quelque part** plutôt que silencieusement absent.
- **T-3.2.3** — Prouver que le résolveur E2E préfère toujours un article doté d'une vraie station.
  • ancrage : `tests/e2e/helpers/kiosk-order.js` → `resolveSimpleOrderableItem()`

**Acceptation** : test de couverture de station VERT · inventaire des articles `none` consigné ·
résolveur prouvé sur 3 exécutions consécutives.

---

## Sub 3.3 — File d'attente et worker

**Vécu** : au cycle CAISSE le worker était **absent** — 436 travaux en attente. La pastille l'a
signalé honnêtement et est repassée au vert après redémarrage.

**Tâches**
- **T-3.3.1** — Documenter le redémarrage du worker pour le comptoir : une page, pas un fil de discussion.
  • livrable : `docs/RUNBOOK_WORKER_CAISSE.md` *(à créer)*
- **T-3.3.2** — Prouver la reprise : file bloquée → worker relancé → lane cuisine rattrape le retard.
  • test : *(à créer)* `tests/Feature/KDS/KdsRepriseFileTest.php`
- **T-3.3.3** — Vérifier la dégradation de la synchro temps réel (soketi coupé → repli sur scrutation).
  • ancrage : `SYNC_CONTRACT.md` · test : `tests/e2e/zone6-sync-resilience.spec.js` (existant, remis au vert au cycle CAISSE)

**Acceptation** : les 2 tests VERTS · runbook écrit · `zone6-sync-resilience.spec.js` VERT.

---

# §6 — SYSTÈME 4 : HARNAIS E2E (fiable AVANT le produit)
### Contrat
**308 specs** ne valent rien si elles échouent pour de mauvaises raisons. Ce système passe
**avant** les vagues produit : on ne juge pas la caisse avec une balance faussée.

### Ancrages (vérifiés 2026-08-25)
`tests/e2e/` = 308 specs · `tests/e2e/helpers/kiosk-order.js` · `tests/Playwright/global-setup.js` ·
**62** specs référencent `AUDIT-KIOSK-WAVE-E`, dont **8 specs réelles écrivent** avec ce préfixe ·
**3** specs utilisent `reducedMotion`.

---

## Sub 4.1 — Dérive des fixtures

**Expérience naturelle** : la seule spec verte était **la seule dont l'identifiant d'article était
encore valide**. Les autres pointaient, en dur, vers des articles **supprimés en douceur**
(`deleted_at` au 2026-05-28, `status=5`) — invisibles via Eloquent, présents via `DB::table()`.

**Tâches**
- **T-4.1.1** — Étendre le résolveur partagé à toute spec qui code encore un identifiant en dur.
  • ancrage : `tests/e2e/helpers/kiosk-order.js` → `resolveSimpleOrderableItem({branchId, preferName, excludeIds})` · preuve : `grep` des identifiants littéraux restants dans `tests/e2e/`, liste consignée
- **T-4.1.2** — Empêcher la réapparition par une sentinelle qui refuse un nouvel identifiant en dur.
  • test : *(à créer)* `tests/js/e2eFixturesSansIdentifiantCode.spec.js`
- **T-4.1.3** — Vérifier les exclusions du résolveur : supprimés en douceur, `status≠5`, variations,
  étapes d'assistant à `min_select>0`, indisponibles en succursale.
  • test : `tests/js/kioskAuditCleanupSafety.spec.js` (17 cas existants — étendre)

**Acceptation** : zéro identifiant d'article codé en dur dans `tests/e2e/` · sentinelle VERTE ·
`kioskAuditCleanupSafety.spec.js` VERT.

---

## Sub 4.2 — Isolation et concurrence

**Mesuré** : **8 specs réelles** écrivent sous le **même préfixe** `AUDIT-KIOSK-WAVE-E`. En
parallèle, elles se nettoient mutuellement les données. Bombe à retardement, pas hypothèse.

**Tâches**
- **T-4.2.1** — Donner à chaque spec un préfixe propre, dérivé de son nom de fichier.
  • ancrage : les 8 specs identifiées, dont `rush-sync-flow.spec.js`, `wave-polish-final-B.spec.js`, `test-e2e-kiosk-kds-sync-2026-05-11-wave-D.spec.js`, `test-e2e-rush-hour-50x50-2026-05-10-wave-B.spec.js`, `wave-p-cross-system-2026-05-20.spec.js`, `test-e2e-abuse-P-idempotency.spec.js`, `test-e2e-rush-hour-50x50-2026-05-10-wave-E.spec.js`
  • test : *(à créer)* `tests/js/e2ePrefixesDisjoints.spec.js`
- **T-4.2.2** — Conserver la garde de nettoyage : préfixe ≥ 8 caractères, sans `%`, `_` ni `\`,
  **en première instruction** de la fonction.
  • test : `tests/js/kioskAuditCleanupSafety.spec.js` (assertion de position de garde existante)
- **T-4.2.3** — Maintenir la double garde base : `FOODKING_E2E_DEDICATED_DB=1` **et** nom de base à
  segment de test, avant toute écriture de seeder.
  • ancrage : `tests/Playwright/global-setup.js`

**Acceptation** : `e2ePrefixesDisjoints.spec.js` VERT · les 8 specs passent en parallèle sur
2 exécutions consécutives · double garde intacte.

---

## Sub 4.3 — Pièges de mesure (ne pas corriger le produit pour l'instrument)

**Trois pièges prouvés**, consignés pour qu'aucune session future n'y retombe :

| Piège | Réalité prouvée | Règle |
|---|---|---|
| `test.use({ reducedMotion })` | **Inerte** dans ce dépôt (sonde isolée : `matches: false`) | Utiliser `page.emulateMedia()` — prouvé (`animationName: none`) |
| `keyboard.press('F2')` | **Inerte** en headless — les touches F ne sont pas mortes dans le produit | Prouver les touches F en test de composant |
| Recherche « intolérante » | Artefact : `poulet` / `creme` **n'existent pas** au menu | Vérifier la source avant de signaler |

**Tâches**
- **T-4.3.1** — Corriger les **3** specs qui utilisent encore `reducedMotion`.
  • test : *(à créer)* `tests/js/e2eEmulateMediaPlutotQueReducedMotion.spec.js`
- **T-4.3.2** — Consigner les 3 pièges dans `docs/PLAYWRIGHT_MCP_OPS.md` (existant).
- **T-4.3.3** — Inscrire « vérifier la source du menu avant de signaler un défaut de recherche »
  à la discipline anti-hallucination.

**Acceptation** : 3 specs migrées · sentinelle VERTE · `docs/PLAYWRIGHT_MCP_OPS.md` à jour.

---

## Sub 4.4 — Restauration des specs rouges

**État hérité** : **9 / 11** specs restaurées au cycle CAISSE. Restent **2** partiellement rouges.

**Tâches**
- **T-4.4.1** — Vague D : une assertion de lane KDS (voir T-3.1.1, même amas).
- **T-4.4.2** — Vague F : jambe POS de la course (voir T-3.1.3, même amas).
- **T-4.4.3** — Exécuter la suite E2E complète en boucle jusqu'à **deux passages consécutifs
  aux constats identiques** (règle de convergence §0.5).

**Acceptation** : 11 / 11 VERTES, **ou** rouge documentée (cause racine + décision propriétaire) ·
deux exécutions consécutives identiques.

---

# §7 — SYSTÈME 5 : DÉPENDANCES & SÉCURITÉ
### Contrat
Le socle ne doit pas être la faille. Il l'est partiellement : Laravel 9 est en **fin de vie**
et 7 avis restent ouverts sur 3 paquets.

### Ancrages (vérifiés 2026-08-25)
`composer.json` → `"laravel/framework": "^9.19"` · PHP **8.2.30** ·
`composer audit` → **3 paquets / 7 avis** :

| Paquet | Avis | Sévérités |
|---|---|---|
| `firebase/php-jwt` | 1 | low |
| `laravel/framework` | 4 | medium, **high**, inconnue, medium |
| `spatie/laravel-medialibrary` | 2 | **high** (SSRF, `GHSA-fggg-964j-3j7h`, versions `<11.23.0`), medium |

**Acquis** : avis passés de **56 à 7**, **0 critique** ; non-régression prouvée en restaurant
l'ancien `composer.lock`, réinstallant, et retrouvant des comptes de tests **identiques**.

---

## Sub 5.1 — Avis résiduels sans montée majeure

**Tâches**
- **T-5.1.1** — `spatie/laravel-medialibrary` : évaluer la montée `>=11.23.0` et son coût réel. Si elle
  exige Laravel 10+, la tâche devient dépendante de W6 — le déclarer, ne pas le cacher.
  • test : suite `tests/Feature/` complète (base 4862)
- **T-5.1.2** — `firebase/php-jwt` (low) : monter si le coût est nul.
- **T-5.1.3** — Réduire la surface SSRF côté application tant que la montée n'est pas faite
  (allowlist d'hôtes pour les téléversements distants).
  • test : *(à créer)* `tests/Feature/Security/MediaLibraryHoteAllowlistTest.php`

**Acceptation** : `composer audit` **0 high**, **ou** justification écrite liant chaque avis
restant à W6 · `tests/Feature/Security/MediaLibraryHoteAllowlistTest.php` *(à créer)* VERT ·
base PHPUnit 4862 tenue.

---

## Sub 5.2 — Montée Laravel 9 → 10/11 (le verrou)

**Constat** : ce chantier **bloque les derniers avis**. Lourd — budget et fenêtre propres. **Gate G5.**

**Tâches**
- **T-5.2.1** — Inventaire de rupture (dépendances incompatibles, appels dépréciés, paquets non
  maintenus), **avant** toute modification de `composer.json`.
  • livrable : `reports/audit/MONTEE_LARAVEL_INVENTAIRE_<date>.md` *(à créer)*
- **T-5.2.2** — Monter sur une branche dédiée, jamais sur l'arbre sale actuel.
- **T-5.2.3** — Faire tenir les 4862 tests, ou documenter chaque écart un par un.
- **T-5.2.4** — Re-vérifier après montée les gardes d'amorçage production
  (`AppServiceProvider.php:78-145`) : dernière ligne de défense NF525.

**Acceptation** : inventaire écrit **avant** exécution · G5 accordé · 4862 tests tenus ou écarts
documentés · gardes d'amorçage re-prouvées · SCOPE-5 respecté.

---

## Sub 5.3 — Durcissement runtime (3 escalades vérifiées)

**Tâches**
- **T-5.3.1** — `foodking:ensure-admin` **n'a aucune garde de production** (vérifié : `grep` sur
  `environment|isProduction|APP_ENV|confirmToProceed` retourne **vide**). Une commande qui crée un
  administrateur doit refuser de tourner en production sans confirmation explicite.
  • ancrage : `app/Console/Commands/EnsureAdminLoginCommand.php:22` · test : *(à créer)* `tests/Feature/Console/EnsureAdminGardeProductionTest.php`
- **T-5.3.2** — `HealthzController` : pour tout pilote de diffusion **≠ `pusher`**, la sonde
  renvoie un état sans ouvrir de connexion — le commentaire du code le reconnaît lui-même.
  Une santé qui ne teste rien n'est pas une santé.
  • ancrage : `app/Http/Controllers/HealthzController.php:148-161` · test : *(à créer)* `tests/Feature/HealthzDiffusionNonPusherTest.php`
- **T-5.3.3** — `DashboardService::slaAlerts()` filtre `->where('updated_at', '<', $timeLimit)`
  **sans borne basse** : une commande figée depuis des mois alerte indéfiniment.
  • ancrage : `app/Services/DashboardService.php:490` et suivantes · test : *(à créer)* `tests/Feature/Dashboard/SlaAlertesBorneBasseTest.php`
- **T-5.3.4** — Poursuivre le cliquet d'autorisation des FormRequest : `RETURN_TRUE_BASELINE = 64`
  (vérifié `tests/Feature/Sentinels/FormRequestAuthzDriftSentinelTest.php:67`, historique
  77 → 74 → 69 → 66 → **64**). Objectif de vague : **≤ 60**.

**Acceptation** : les 3 tests d'escalade VERTS · cliquet ≤ 60 ·
`FormRequestAuthzDriftSentinelTest.php` VERT au nouveau seuil.

---

# §8 — SYSTÈME 6 : GOUVERNANCE & VÉRITÉ DOC
### Contrat
La mémoire du projet doit être **vraie**. Une documentation qui ment coûte plus cher qu'une
documentation absente : elle fait décider faux, avec confiance.

### Ancrages (vérifiés 2026-08-25)
`plans/` = **260** fichiers · `docs/gates/` = **50** fichiers ·
`PROJECT_BRAIN.md`, `CONSTITUTION.md`, `SYSTEM_MAP.md` présents.

---

## Sub 6.1 — Clôture du cycle CAISSE-SUPERVISOR-CONTROL

⚠️ **CORRIGÉ LE 2026-08-25 — cette section affirmait le contraire.** Un verdict GPT **existe** :
`reports/audit/GPT_FINAL_AUDIT_CAISSE-SUPERVISOR-CONTROL-20260823.md` → **`VERDICT: REWORK`**,
six constats, produits par un **canal de repli** (`foodking-complex-implementer`). Le canal
`gpt-5.5-pro` a bien échoué (HTTP 400, visible dans `output_codex.json`), mais j'avais généralisé
cet échec à l'absence de toute sortie. Vérification du 2026-08-25 : **les six constats sont clos
dans le code**. Dossier complet : `reports/audit/CORRECTION_VERDICT_GPT_EXISTE_2026-08-25.md`.

**Tâches**
- **T-6.1.1** — Clore le cycle **sans** verdict GPT, en le disant explicitement. **Gate G1.**
- **T-6.1.2** — Consolider les rapports du cycle en une synthèse unique et lisible.
  • existant : `reports/audit/CLAUDE_AUDIT_CAISSE-SUPERVISOR-CONTROL-20260823_2026-08-24.md`, `CAISSE_ERGONOMIE_CAISSIER_2026-08-24.md`, `INVENTAIRE_MISSIONS_OUVERTES_2026-08-24.md`, `COMPOSER_SECURITE_2026-08-25.md`, `E2E_DERIVE_FIXTURES_2026-08-25.md`
- **T-6.1.3** — Mettre `PROJECT_BRAIN.md` §2/§3/§4 à jour avec l'état réel de clôture.

**Acceptation** : G1 tranché · synthèse écrite · BRAIN à jour · zéro affirmation d'un audit GPT
inexistant.

---

## Sub 6.2 — Cohérence de la mémoire stable

**Contradiction non résolue** : `PROJECT_BRAIN.md §9` contredit `§2`. Une contradiction dans la
mémoire stable relève de CLAUDE.md §12 : **STOP et remonter**, jamais d'arbitrage silencieux.

**Tâches**
- **T-6.2.1** — Exposer la contradiction §9 / §2 au propriétaire, avec les deux textes en regard.
- **T-6.2.2** — Vérifier que la note `User` **non isolé par succursale** reste exacte :
  `BranchScope::apply()` fait un no-op explicite sur `User`, et `BranchScopeCoverageSentinelTest`
  ne teste que la **présence textuelle** du scope. Ne pas lui faire dire ce qu'elle ne prouve pas.
- **T-6.2.3** — Aligner `CONSTITUTION.md`, `SYSTEM_MAP.md`, `SYNC_CONTRACT.md` sur l'état réel.

**Acceptation** : contradiction tranchée et consignée · note `User` vérifiée en lecture de code
contre `tests/Feature/Branch/BranchScopeCoverageSentinelTest.php` · chaîne de démarrage à froid
(CLAUDE.md §0) cohérente.

---

## Sub 6.3 — Dette de plans et de gates

**Constat** : **260** fichiers dans `plans/`, **50** dans `docs/gates/`. Certains gates en attente
portent des `DROP_TABLE` — ils ne se purgent pas à la légère.

**Tâches**
- **T-6.3.1** — Inventorier les plans périmés, proposer un archivage (déplacement, **jamais** suppression).
  • livrable : `reports/audit/INVENTAIRE_PLANS_PERIMES_<date>.md` *(à créer)*
- **T-6.3.2** — Inventorier les gates en attente, en isolant les `DROP_TABLE`. **Gate G6.**
- **T-6.3.3** — Règle : un plan clos est archivé sous `plans/archive/`, verdict de clôture en tête.

**Acceptation** : les 2 inventaires écrits · G6 tranché avant tout mouvement de gate `DROP_TABLE` ·
zéro suppression, uniquement déplacement.

---

# §A — ARMÉE D'AGENTS

> ⚠️ **Autorisation requise.** Cette session interdit la délégation sans demande explicite du
> propriétaire. Cette carte est le **plan d'exécution** : elle s'active au « lance le GOAL ».
> Sans cette parole, le superviseur exécute lui-même, en fil unique.

Consignes sous `~/.claude/skills/superpower-gstack/agents/`. Tous `general-purpose` sauf Architecte (`Plan`).

| Rôle | Outils | Consigne |
|---|---|---|
| Architecte | lecture seule | `architect-prompt.md` |
| Sécurité | lecture seule | `qa-red-team-prompt.md` (mode SÉCURITÉ) |
| UX / A11y | lecture + axe-core | WCAG 2.1 + ARIA + focus |
| DBA | lecture | schéma + FK + N+1 + BranchScope (24 modèles) |
| SRE / Synchro | lecture | Outbox + Pusher + scrutation + file |
| Implémenteur | Edit + Write + Bash | `implementer-prompt.md` (test d'abord) |
| ROUGE | lecture seule | `qa-red-team-prompt.md` (mode ROUGE) |
| QA visuel | lecture + Playwright | exécuter, capturer, **analyser** |
| ROUGE visuel | lecture | ré-analyser les captures **indépendamment**, contester |

### Matrice de déclenchement

| Type de tâche | Arch | Séc | UX | DBA | SRE | Impl | ROUGE | QA vis | ROUGE vis |
|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| Frontend visuel | x | x | x | . | . | x | x | x | x |
| Logique backend | x | x | . | x | . | x | x | . | . |
| Cascade de synchro | x | x | . | x | x | x | x | . | . |
| Adjacent NF525 | x | x | . | x | . | x | x | . | . |
| Migration / schéma | x | x | . | x | . | x | x | . | . |
| Fixtures / seeders | . | . | . | x | . | x | x | . | . |
| E2E inter-surfaces | x | x | x | x | x | x | x | x | x |

### Discipline de répartition
- Les **5 spécialistes lecture seule** partent dans **un seul message**, en parallèle.
- **Jamais deux implémenteurs en parallèle** (conflit d'écriture).
- **QA visuel + ROUGE visuel** en parallèle ; le second **conteste** le premier.
- La **contestation ROUGE** vient **après** l'implémentation, **avant** toute déclaration de succès.
- Chaque agent **écrit sur disque** (`reports/test-e2e/CONSOLIDATION_V1_PRODUCTION_20260825/<round>/wave-<W>-<rôle>.json`) : la synthèse se fait depuis le disque et survit à une coupure. Plafond ~1 200-1 500 mots.

### Contrat de constat
```
[P0|P1|P2|P3] <fichier>:<ligne> — <titre en une ligne>
  reproduction : <commande exacte ou chemin de clics>
  preuve       : <chemin de capture | erreur console | nom de test>
  recommandation : <correctif de portée minimale>
```
⛔ Un P0/P1 **sans** `file:line` vérifié par `grep`/`Read` **et** sans étape de reproduction est
**REJETÉ** et n'est pas remonté au propriétaire (CLAUDE.md §3ter).

---

# §X — VAGUES DE CONVERGENCE

**Ordre volontaire** : vérité documentaire (W1) → filet de test (W2) → produit (W3-W4).

| Vague | Portée | Parallélisme | Bloquée par |
|---|---|---|---|
| **W0** | Pré-vol | séquentiel | — |
| **W1** | S6 — vérité doc & clôture | séquentiel | G1 |
| **W2** | S4 — harnais E2E | fan-out lecture seule dans la vague | — |
| **W3** | S1 — caisse | séquentiel | G3, G4 |
| **W4** | S2 + S3 — borne & cuisine | séquentiel (flux de commande partagé) | — |
| **W5** | S5.1 + S5.3 — durcissement | séquentiel | — |
| **W6** | S5.2 — montée Laravel | **branche dédiée** | G5 |
| **W7** | Convergence finale + boucle E2E réelle | séquentiel | G7 |

### W0 — Pré-vol
`backup/pre-consolidation-2026-08-25` **sans changer de branche** · dump base · figer les bases §0.6
(4862 / 3609 / 7 avis / 0 ligne gelée) · attester NF525 (`count(*)`, `MAX(current_hash)`) ·
statuer bloquant/non sur chaque gate §G.
**Contrôle** : bases figées par écrit, filet créé, aucun fichier de l'arbre sale perdu.

### W1 — Vérité documentaire (S6)
T-6.1.1 → T-6.3.3. Aucun code produit touché.
**Contrôle** : G1 tranché, contradiction §9/§2 remontée, inventaires écrits, BRAIN à jour.

### W2 — Harnais E2E (S4) — *la vague la plus importante*
T-4.1.1 → T-4.4.3. Fan-out lecture seule autorisé en phase d'audit.
**Contrôle** : zéro identifiant codé en dur · 8 préfixes disjoints prouvés en parallèle ·
3 pièges consignés · 11/11 specs vertes ou documentées.

### W3 — Caisse (S1)
T-1.1.1 → T-1.3.3. **Ne démarre pas** avant G3 et G4 — sinon on code une ergonomie que le
propriétaire n'a pas choisie.
**Contrôle** : `tests/Feature/Pos/` vert (59 fichiers) · captures lues et analysées · NF525 ajout seul.

### W4 — Borne & cuisine (S2 + S3)
T-2.1.1 → T-3.3.3. Séquentiel : flux de commande partagé.
**Contrôle** : vagues D et F closes · `tests/Feature/Kiosk/` vert · runbook worker écrit.

### W5 — Durcissement runtime (S5.1 + S5.3)
T-5.1.1 → T-5.3.4.
**Contrôle** : 3 escalades fermées par des tests · cliquet FormRequest ≤ 60 · 0 high restant ou lié à W6.

### W6 — Montée Laravel (S5.2) — **branche dédiée, gate G5**
T-5.2.1 → T-5.2.4. Inventaire **avant** toute modification. SCOPE-5 : > 40 tests cassés = STOP.
**Contrôle** : 4862 tests tenus ou écarts documentés un par un · gardes d'amorçage re-prouvées.

### W7 — Convergence finale
Suite complète (PHPUnit + Vitest + Playwright) · parcours borne → cuisine → OSS · diff zone gelée = 0
sur toute la plage · attestation NF525 · **boucle E2E réelle jusqu'à deux passages consécutifs aux
constats identiques**.
**Contrôle** : critères §0.5 tous satisfaits · G7 pour toute étiquette ou poussée.

---

## §X.8 — Point de contrôle de vague (6 points)

- [ ] Toutes les tâches PASSENT, ou échouent avec un motif **écrit** et assumé
- [ ] Diff zone gelée sur la plage de la vague = **0 ligne**
- [ ] Chaîne NF525 inchangée ou **en ajout seul** (si la vague touche au fiscal)
- [ ] Barrière visuelle déclenchée pour toute tâche frontend — captures **lues et analysées**
- [ ] Contestation ROUGE faite ; tout P0/P1 nouveau soigné ou différé **avec motif**
- [ ] `PROJECT_BRAIN.md` §2 + §3 à jour, commits nommés

Un seul « non » ⇒ **vague non close**. On soigne ou on documente. On n'avance pas.

## §X.9 — Échec de convergence (3e boucle, même amas)

1. **STOP** — pas de 4e boucle silencieuse.
2. Analyser la cause : pourquoi 3 cycles n'ont pas suffi.
3. Écrire `reports/test-e2e/CONSOLIDATION_V1_PRODUCTION_20260825/STUCK_<vague>_<horodatage>.md`.
4. Remonter 4 options : **A)** accepter avec documentation · **B)** pivot d'architecture ·
   **C)** différer en V1.0.X · **D)** gate humain.
5. **Ne pas choisir à sa place.** Attendre.

## §X.10 — Protocole d'interruption (limite d'usage / fin de session)

1. **Commiter le partiel** — `wip(<vague>): partiel jusqu'à T-N.X.Y`, fichiers nommés un par un.
2. Manifeste `reports/test-e2e/CONSOLIDATION_V1_PRODUCTION_20260825/INTERRUPT_<vague>_<horodatage>.md`
   → dernier commit vert · dernière tâche + statut · tâche suivante · rapports d'agents sur disque.
3. `PROJECT_BRAIN.md` §2 mis à jour avec l'état d'interruption.
4. Reprise : lire le manifeste → `git status` → re-exécuter la dernière tâche en fumée → continuer.

---

# §G — GATES PROPRIÉTAIRE

| Gate | Description | QUI | QUOI | OÙ | Statut |
|---|---|---|---|---|---|
| **G1** | Clore le cycle `CAISSE-SUPERVISOR-CONTROL-20260823` **sans** verdict GPT (le canal n'a jamais produit de sortie — HTTP 400 prouvé) | Propriétaire | Décision écrite de clôture | `missions/CAISSE-SUPERVISOR-CONTROL-20260823/CLAUDE_CODE_HANDOFF.md` + `PROJECT_BRAIN.md §2` | **EN ATTENTE** |
| **G2** | Valider ou ajuster `GATE-WHEEL-EXPERIENCE-UX-SIGNOFF-2026-08-23` (Roue : 34/34 et 87/87 verts) | Propriétaire | Contreseing UX | `docs/gates/` | **EN ATTENTE** |
| **G3** | Disposition de la page caisse : la grille de vente est **sous la ligne de flottaison** sur un écran de comptoir. A) remonter la grille · B) garder et documenter | Propriétaire | Choix A ou B | `plans/GOAL_CONSOLIDATION_V1_PRODUCTION_2026-08-25.md` §G + commit | **EN ATTENTE — bloque W3** |
| **G4** | Portée de la recherche caisse : A) nom seul · B) nom + catégorie + code | Propriétaire | Choix A ou B | idem G3 | **EN ATTENTE — bloque W3** |
| **G5** | Montée Laravel 9 (EOL) → 10/11 : fenêtre, budget, branche dédiée | Propriétaire | Autorisation + fenêtre | `PROJECT_BRAIN.md §6 JOURNAL DES DÉCISIONS` | **EN ATTENTE — bloque W6** |
| **G6** | Purge de la dette de gates, dont plusieurs `DROP_TABLE` en attente | Propriétaire | Autorisation par gate, une par une | `docs/gates/GATE_LOG.md` | **EN ATTENTE — bloque T-6.3.2** |
| **G7** | Poussée distante, étiquette de version, ou création de PR publique | Propriétaire | Accord explicite | message de commit + `PROJECT_BRAIN.md §2` | **EN ATTENTE — bloque W7** |

### Protocole d'attente
Un gate EN ATTENTE bloque **sa** vague, pas les autres. Aujourd'hui : **W1, W2, W4, W5 exécutables
immédiatement** ; W3 attend G3+G4 ; W6 attend G5 ; W7 attend G7. État reporté dans `PROJECT_BRAIN.md §2`.

⛔ **Un gate propriétaire ne peut être approuvé ni par un agent, ni par un test automatisé.**

---

# §R — RÉFÉRENCES

**Compétences** : `ultra-audit-profond` · `superpower-gstack` · `test-e2e` · `lock-plan` ·
`superpowers:writing-plans` · `superpowers:dispatching-parallel-agents`

**Mémoire projet** : `CLAUDE.md` §§0-16 · `CONSTITUTION.md` · `PROJECT_BRAIN.md` · `SYSTEM_MAP.md` ·
`SYNC_CONTRACT.md` · `PARALLEL_PROTOCOL.md` · `memory/reference_frozen_zones.md` ·
`memory/feedback_adversarial_audit_pattern.md`

**Documentation** : `docs/ARCHITECTURE.md` · `docs/BUSINESS_RULES.md` · `docs/ORDER_FLOW.md` ·
`docs/AUTHZ_MATRIX.md` · `docs/PLAYWRIGHT_MCP_OPS.md` · `docs/GATES_DOCTRINE.md`

**Rapports amont (2026-08-24/25)**, sous `reports/audit/` :
`CLAUDE_AUDIT_CAISSE-SUPERVISOR-CONTROL-20260823_2026-08-24.md` · `CAISSE_ERGONOMIE_CAISSIER_2026-08-24.md` ·
`INVENTAIRE_MISSIONS_OUVERTES_2026-08-24.md` · `COMPOSER_SECURITE_2026-08-25.md` ·
`E2E_DERIVE_FIXTURES_2026-08-25.md` · `reports/execution/RUN_CAISSE-...-2026-08-23.md` (359 lignes) ·
`plans/PLAN_CAISSE-...-2026-08-23.md` (REPLAN_1..9)

---

# §F — RÈGLE FINALE

Ce GOAL est **TERMINÉ** quand, et seulement quand :

1. Les **7 vagues** sont closes selon les 6 points de §X.8 — aucune exception tolérée.
2. **PHPUnit ≥ 4862 passés, code de sortie 0, zéro `⨯`.**
3. **Vitest ≥ 3609 passés**, plus les tests créés par ce GOAL.
4. **Playwright : 11/11 specs restaurées vertes**, ou rouge documentée avec cause racine nommée
   **et** décision propriétaire consignée.
5. **Diff zone gelée = 0 ligne** sur la plage complète du GOAL.
6. **Chaîne NF525 en ajout seul**, attestée en début et en fin.
7. **`composer audit` : 0 avis high**, ou lien écrit vers W6 pour chacun.
8. Les **7 gates propriétaire** sont tranchés — approuvés ou explicitement différés.
9. `PROJECT_BRAIN.md` §2/§3/§4/§6/§7 reflètent la **réalité**, pas l'intention.
10. **Deux cycles de convergence consécutifs aux constats identiques.**

**Interdit de bout en bout** :
- Déclarer vert ce qui n'a pas été mesuré.
- Remonter un P0 sans `file:line` vérifié et sans étape de reproduction.
- Toucher une zone gelée sans `lock-plan` et contreseing.
- Nettoyer l'arbre de travail, la base de données, ou une trace fiscale.
- Approuver un gate propriétaire à la place du propriétaire.
- Écrire « ça marche presque ». **Parfait-production, ou bloqué.**

> Le but n'est pas d'avoir tout tenté. Le but : que le comptoir encaisse sans surprise, que la
> cuisine voie tout ce qui est commandé, et que la trace fiscale tienne devant un contrôle.
> Tout le reste est du bruit.

---
*Rédigé le 2026-08-25 · ancrages vérifiés par exécution réelle · zéro ligne gelée touchée · HEAD `43b120c7d`.*
