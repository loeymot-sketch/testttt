# Compte-rendu de session — Revue & correctifs « Borne / Kiosk » FoodKing
**Date :** 2026-06-09 · **Intervenant :** Claude (agent) · **Pour :** Chef de projet

---

## 1. Objectif initial
Lancer une **revue de code « ultra »** de la surface Borne (kiosk), puis **planifier, tester (E2E interface + backend) et corriger en boucle jusqu'au vert** les défauts trouvés.

## 2. Environnement & contraintes rencontrées (les « causes »)
Plusieurs blocages d'environnement ont orienté la méthode :

1. **Le dossier de travail initial (`foodking-review/borne`) n'est PAS l'application** : c'est un **banc de revue** de 59 fichiers (instantané du code @ commit `ad29e7875`). Pas de `package.json`, `node_modules`, `vendor`, ni serveur → **non exécutable**.
2. **Sandbox Bash bloquée (`ENOSPC` — disque plein)** en début de session → impossible de lancer des commandes, des tests, ou le serveur. *Cause : disque saturé sur la machine.*
3. **Armée de sous-agents indisponible** (limites serveur / limite de session « reset 14:30 ») → la revue/audit a été faite **en direct par moi-même** plutôt que par des agents parallèles.
4. **La vraie application runnable était ailleurs** : après recherche, identifiée dans `~/Downloads/projet/foodking-web/web/testttt` — **même SHA `ad29e7875` que le banc**, serveur PHP up sur :8000, `node_modules`+`vendor` présents → c'est là que les correctifs doivent atterrir et être testés.
5. **Le frontend de cette app n'a jamais été buildé** → `/kiosk/login` renvoie **HTTP 500** (manifeste Vite manquant) → l'E2E navigateur est bloqué par un problème **d'installation préexistant**, indépendant des correctifs.

## 3. Déroulé & raisonnement (chronologique)

**Phase A — Revue de code (ultrareview).** Disque plein + agents indisponibles → **revue manuelle en lecture seule** de toute la surface logique Borne (6 services backend, store Vuex, file offline, paiement, panier, wizard + 9 étapes, loyalty, etc.). → **4 findings** consignés.

**Phase B — Plan directeur + specs E2E.** J'ai d'abord **vérifié l'environnement** (banc non exécutable) plutôt que produire une fiction multi-systèmes (anti-hallucination). Livré : un plan directeur ciblé Borne + **2 suites Playwright** (parcours nominal + adversarial/pixel), écrites comme code exécutable une fois l'app disponible.

**Phase C — « lance le goal » (exécution).** Le disque s'est libéré (Bash de nouveau OK). **Audit complémentaire en direct** (armée toujours bloquée), **correction de F1 et F4** + tests, puis **découverte de F5 et F6** et correction. Audit complet de la surface logique terminé.

**Phase D — test-e2e + boucle de correction (le cœur).** Localisation de la **vraie app runnable** (`testttt`). Choix retenu : « travailler sur la branche courante ». **Portage des 5 correctifs frontend + correction de F3** (backend), puis **vrais tests** : Vitest (interface) + PHPUnit (backend). **Preuve** de F1 et F6 (échec sur code non corrigé / succès après), **clôture de F2** (faux positif, preuve de code), **E2E navigateur bloqué** par un 500 préexistant (frontend non buildé) — externe aux findings.

## 4. Les 6 défauts — cause, raisonnement, correctif, validation

**F1 — Moyenne — Total de ligne incohérent.** Le stepper de quantité du récap wizard n'était pas plafonné ; `buildCartItem` envoyait `total = ligne × quantité` avec quantité > MAX (20). À l'ajout, le store **plafonnait la quantité à 20 mais gardait le total périmé** (garde `if (!newItem.total)`). Une ligne affichait p.ex. **125 €** alors que 20 articles = **100 €**, incohérent avec sous-total/total.
→ Correctif : `kioskCart.js` (ADD_ITEM + REPLACE_ITEM_AT recalculent **toujours** le total depuis la quantité plafonnée) + `KioskOrderSummaryComponent.vue` (stepper récap plafonné). → 4 tests Vitest, **prouvé** (125≠100 avant / cohérent après).

**F2 — Faible-Moy — « TVA sur base avant remise » (aperçu promo).** Soupçonné bug → en fait **FAUX POSITIF**. La formule de l'aperçu (`sous-total + TVA − remise`) est **exactement** celle du moteur `PricingService.php:353` (TVA par ligne sur le brut), cohérente avec la voie coupon et avec le montant facturé. → **Aucune édition** ; clôturé par preuve de code.

**F3 — Faible — Énumération de comptes au login.** Les messages « machine inactive » / « utilisateur inactif » étaient renvoyés **avant** la vérification du mot de passe → on pouvait deviner des identifiants valides et sonder l'état des comptes **sans mot de passe**.
→ Correctif : `KioskMachineLoginController.php` — **mot de passe vérifié AVANT** les contrôles d'état (le personnel légitime garde les messages utiles ; sinon message générique). → 4 tests PHPUnit + **12 tests login/sécurité existants re-verts** (zéro régression).

**F4 — Faible — Perte de commande hors-ligne.** Une commande enregistrée pendant une synchro en cours pouvait être **écrasée** (`_queueCache = remaining` reconstruit depuis l'instantané d'avant-synchro).
→ Correctif : `kioskOfflineQueue.js` — **capture d'instantané + re-fusion** des entrées ajoutées pendant la synchro. → 2 tests Vitest.

**F5 — P2 — Clavier virtuel sans touche Maj.** Aucune touche Shift rendue → `shift` toujours `false` → **majuscules et couche arabe (tashkeel) inaccessibles** (saisie loyalty en minuscules). Machinerie shift = code mort.
→ Correctif : `ds/KsVirtualKeyboard.vue` — ajout du **bouton Maj** (+ colonne de grille) activant la machinerie existante. → 3 tests Vitest.

**F6 — P2 — Écran « Commande prête » coupé.** `markReady()` ne stoppait pas le minuteur de redirection « préparation » (10 s) → une commande prête en < 10 s renvoyait le client à l'accueil **avant** l'auto-reset de 20 s prévu.
→ Correctif : `KioskWaitingComponent.vue` — `markReady()` stoppe le minuteur de préparation + elapsed. → 2 tests Vitest, **prouvé** (redirection à 10 s avant / reste sur l'écran prêt après).

**Bilan :** 5 vrais bugs **corrigés**, 1 **faux positif clôturé**.

## 5. Résultats de tests (réels, dans `testttt`)
- **Frontend (Vitest, niveau source) : 13 fichiers / 69 tests VERTS** = 58 specs kiosk existants (**zéro régression**) + 11 nouveaux (F1/F4/F5/F6).
- **Backend (PHPUnit, SQLite en mémoire) : 27 tests VERTS** = 23 specs kiosk existants + 4 nouveaux (F3).
- **F1 & F6 prouvés** régressions réelles (échec avant / succès après).
- **E2E navigateur : NON vert** (1/5) — bloqué par le **500 préexistant sur `/kiosk/login`** (frontend non buildé), externe aux findings. Le bundle servi est antérieur à mes éditions → l'E2E teste l'ancien JS, **ne peut ni refléter ni régresser** les correctifs.

## 6. Liste complète des fichiers — toute la session

### A) `~/Downloads/projet/foodking-web/web/testttt` (= l'app réelle, code qui part en prod)
**Sources modifiées (correctifs) :**
- `app/Http/Controllers/Auth/KioskMachineLoginController.php` — F3
- `resources/js/store/modules/kioskCart.js` — F1
- `resources/js/components/frontend/kiosk/KioskOrderSummaryComponent.vue` — F1
- `resources/js/helpers/kioskOfflineQueue.js` — F4
- `resources/js/components/frontend/kiosk/ds/KsVirtualKeyboard.vue` — F5
- `resources/js/components/frontend/kiosk/KioskWaitingComponent.vue` — F6

**Tests créés :**
- `tests/js/kioskCartClampTotal.spec.js` — F1
- `tests/js/kioskOfflineQueueSyncRace.spec.js` — F4
- `tests/js/ksVirtualKeyboardShift.spec.js` — F5
- `tests/js/kioskWaitingMarkReadyTimer.spec.js` — F6
- `tests/Feature/Kiosk/KioskLoginEnumerationTest.php` — F3

**Rapports créés :**
- `reports/borne-ultrareview-convergence-2026-06-09.md`
- `reports/SESSION-borne-ultrareview-2026-06-09.md` (ce document)

### B) `~/foodking-review/borne` (banc de revue — correctifs « code-complete » + livrables de plan)
**Sources modifiées (mêmes correctifs) :** `resources/js/store/modules/kioskCart.js` · `resources/js/components/frontend/kiosk/KioskOrderSummaryComponent.vue` · `resources/js/helpers/kioskOfflineQueue.js` · `resources/js/components/frontend/kiosk/ds/KsVirtualKeyboard.vue` · `resources/js/components/frontend/kiosk/KioskWaitingComponent.vue`
**Fichiers créés :** `.ultrareview-findings.json` (6 findings) · `plans/GOAL_BORNE_VALIDATION_2026-06-09.md` · `tests/e2e/borne/borne-happy-path.full-journey.spec.ts` · `tests/e2e/borne/borne-adversarial.edge-pixel.spec.ts` · `resources/js/store/modules/__tests__/kioskCart.addItem.clamp.spec.js` · `resources/js/helpers/__tests__/kioskOfflineQueue.syncRace.spec.js` · `reports/borne/WAVE_STATUS_2026-06-09.md` · `reports/borne/INTERRUPT_launch_2026-06-09.md` · `reports/borne/CONVERGENCE_2026-06-09.md`

> NB : F2 = aucun fichier modifié (faux positif). Dans `testttt`, `kioskCart.js` et `KioskWaitingComponent.vue` ont été temporairement annulés puis restaurés pour **prouver** les tests — état final = corrigé.

## 7. État Git & avertissements
- **`testttt`** — branche `heal/cms-pr1-quickwins-2026-05-18`. Mes 11 fichiers (6 sources + 5 tests) + 2 rapports sont **non commités** (commit uniquement sur demande).
- **⚠️ Changements préexistants non liés** sur cette branche (KDS / sync : `Kds*`, `OssSyncService.js`, `kdsCustomization.js`…) étaient **déjà** dans l'arbre de travail — **non touchés par moi**. À dissocier au commit.

## 8. Reste à faire (sur décision)
1. **E2E navigateur au vert** (tâche séparée) : `npm run build` (compiler les correctifs dans `public/`), semer machine kiosk + menu, puis relancer `PLAYWRIGHT_NO_WEB_SERVER=1 playwright test tests/e2e/03-kiosk-wizard.spec.js`. Le 500 devrait disparaître une fois le frontend buildé.
2. **Commit** des correctifs + tests en un lot relisible (en isolant les changements KDS/sync préexistants).
3. **F2** : si une décision fiscale globale (base TVA avant/après remise) doit être tranchée, c'est moteur/owner — pas un bug Borne.

---
*Méthode : revue manuelle (anti-hallucination, chaque citation vérifiée), correctifs « scope-minimal », validation par tests réels avec preuve échec-avant/succès-après, zéro régression sur les suites existantes.*
