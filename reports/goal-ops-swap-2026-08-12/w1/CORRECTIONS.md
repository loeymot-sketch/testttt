# Corrections livrées — GOAL-OPS-SWAP, premier lot

**2026-08-12 · branche `pos/category-first-caisse-2026-06-23` · aucun commit, aucune poussée**

Chaque correctif suit la même discipline : **test d'abord prouvé rouge** → correctif → **preuve par mutation** (je casse le correctif, le banc doit virer au rouge avec un message parlant) → balayage de régression.

---

## C1 — `EXPORT-BLOB-MUET` : 20 écrans disaient « undefined » au lieu du motif du refus

### Le défaut
Les exports admin partent en `responseType: 'blob'` — correct, la réponse nominale est un fichier. Mais sur une réponse d'**erreur**, axios ne désérialise pas : `err.response.data` reste un Blob. Les écrans affichent `err.response.data.message`, qui vaut donc `undefined`.

**Prouvé en navigateur, session admin réelle** (`/admin/sales-report`) :

| Mesure | Valeur |
|---|---|
| HTTP | `422` |
| `err.response.data` | `[object Blob]` |
| `err.response.data.message` | **`"undefined"`** |
| Contenu réel du Blob | `Trop de lignes pour un export PDF (3191 lignes). Affinez la période avec un filtre de date.` |

Le serveur donnait la marche à suivre, en français. L'écran affichait `undefined`. L'exploitant en conclut que le rapport est cassé — c'est mot pour mot la plainte owner.

### Le correctif — un point, pas vingt
`resources/js/shared/blob-error.js` (nouveau) : un intercepteur de réponse qui, sur erreur, rend son corps JSON à l'erreur si le Blob en contient un.

Ce qu'il **ne** fait pas, volontairement : aucun en-tête modifié · aucun contrat `/api` touché · le chemin de succès intact (un vrai fichier reste un Blob) · **jamais de résolution** d'une erreur (sinon l'écran construirait un PDF depuis un corps d'erreur) · aucun message inventé (un Blob non-JSON est laissé tel quel).

Installé aux **deux** entrées — `resources/js/app.js` et `resources/js/pos-app.js`. Le jumeau est traité **maintenant**, pas « plus tard » : une entrée corrigée et l'autre pas, c'est la divergence programmée.

### Les 20 écrans couverts
`salesReport` · `itemsReport` · `onlineOrder` · `creditBalanceReport` · `transaction` · `posOrder` · `item` · `itemCategory` · `customer` · `subscriber` · `coupon` · `offer` · `waiter` · `chef` · `employee` · `administrator` · `deliveryBoy` · `diningTable` · `tableOrder` · `pushNotification`

### Preuve
`tests/js/exportBlobErrorNormalizer.spec.js` — **6 bancs verts**.

| Mutation appliquée | Détectée par | Message |
|---|---|---|
| Retirer la substitution du corps parsé | 1 banc | `expected undefined to be 'Trop de lignes…'` — **le défaut d'origine, mot pour mot** |
| Résoudre au lieu de rejeter | 3 bancs | `promise resolved … instead of rejecting` |

---

## C2 — `PERMISSION-URL-DESACCORDEE` : le menu promettait ce que le serveur refusait

### Le défaut
**Vérifié en base réelle**, pas déduit des seeders :

```
name=ingredients_manage   url=[NULL]      (id 82 sanctum, id 83 web)
name=catalog.compose      url=[NULL]      (id 80)
name=catalog.publish      url=[NULL]      (id 81)
name=items_create         url=[items/create]   ← le routeur demande "items_create"
url=ingredients_manage -> 0 ligne · url=catalog.compose -> 0 · url=items_create -> 0
```

Les deux gardes (routeur et barre latérale) ne cherchaient que sur `url`. Sans correspondance, elles retombent — **délibérément** — sur « laisser passer » (« le backend reste l'autorité finale via 403 »).

**Conséquence prouvée par appel authentifié** :

| Compte | `/api/admin/ingredients` |
|---|---|
| `pos@lecayenne.fr` | **HTTP 403** |
| `chef@lecayenne.fr` | **HTTP 403** |

…alors que l'entrée « Ingrédients » leur est proposée dans la barre latérale.

### Ce que je n'ai PAS corrigé, et pourquoi
Le **repli permissif n'est pas le défaut**. Il est documenté et assumé ; le durcir masquerait des écrans légitimes au moindre trou de données. Le défaut est le **désaccord** entre la clé demandée et la clé stockée.

### Le correctif
`resources/js/shared/permission-match.js` (nouveau) : **définition unique** de « cet utilisateur a-t-il cette permission ? ». `url` d'abord, `name` en second recours.

**Vérifié avant d'écrire une ligne** : sur les 86 permissions, **aucun `name` n'est égal au `url` d'une autre permission** — la correspondance par `name` est donc sans ambiguïté.

Câblé aux **deux** gardes, qui posaient la même question avec deux implémentations séparées :
- `resources/js/router/index.js`
- `resources/js/components/layouts/backend/BackendMenuComponent.vue`

### Preuve
`tests/js/permissionMatchResolver.spec.js` — **10 bancs verts**. Mutation (retrait du recours par `name`) : **4 bancs rouges**.

Balayage de régression : `sidebarV1Cleanup` · `routerPermissionRequired` · `backendMenuVirtualChildrenOverride` + les 2 nouveaux = **29/29 verts**.

---

## C3 — `CONFIG-REPORT-FANTOME` : un plafond réglable que rien ne pouvait régler

`config('report.pdf_max_rows', 2000)` est lu par 3 contrôleurs (`SalesReportController.php:82`, `OnlineOrderController.php:92`, `ItemsReportController.php:69`), mais **`config/report.php` n'existait pas**. Laravel ne chargeait donc aucun espace de noms `report` : la valeur retombait toujours sur le défaut codé en dur, et `REPORT_PDF_MAX_ROWS` n'avait **aucun effet** (`env()` n'est lu que depuis un fichier de configuration).

**Correctif** : `config/report.php`, valeur par défaut **inchangée** (2000) — le plafond devient réglable, le comportement ne bouge pas.

**Preuve** : `tests/Feature/Reports/ReportPdfMaxRowsIsConfigurableTest.php` — 3 verts ; mutation (retrait du fichier) → **3 rouges**.

---

## Fichiers touchés

| Fichier | Nature | Note |
|---|---|---|
| `resources/js/shared/blob-error.js` | **neuf** | |
| `resources/js/shared/permission-match.js` | **neuf** | |
| `config/report.php` | **neuf** | |
| `tests/js/exportBlobErrorNormalizer.spec.js` | **neuf** | |
| `tests/js/permissionMatchResolver.spec.js` | **neuf** | |
| `tests/Feature/Reports/ReportPdfMaxRowsIsConfigurableTest.php` | **neuf** | |
| `resources/js/app.js` | modifié | 2 lignes additives |
| `resources/js/pos-app.js` | modifié | 2 lignes additives |
| `resources/js/router/index.js` | modifié | ⚠️ **portait déjà des modifications non committées** d'une autre session (Uber-photo, 2026-08-10) |
| `resources/js/components/layouts/backend/BackendMenuComponent.vue` | modifié | ⚠️ **idem** |

### ⚠️ Avertissement de commit — à lire avant tout `git add`
Les deux derniers fichiers portaient **déjà** du travail non committé d'une autre session. Mes modifications s'y ajoutent. **Ne pas les committer en bloc** : c'est exactement la faute décrite dans le message du commit `590e1cc62` (4 routes parties en production sans leur contrôleur). Committer par sélection explicite, jamais `git add .`.

**Frozen zones : 0 fichier touché.** Vérifié sur les 12 chemins de `CLAUDE.md §7`.

---

---

## C4 — `I18N-VALIDATION-ANGLAISE` : 92 % des messages « français » étaient en anglais

`config/app.php:165` fixe `'locale' => 'fr'`. Laravel chargeait donc bien `lang/fr/validation.php` — qui contenait **77 des 83 clés identiques mot pour mot à l'anglais**. Tout message d'erreur de formulaire du produit (connexion, caisse, borne, admin) s'affichait en anglais. Constaté à l'écran sur `/login` : « The email must be a valid email address. »

Viole `CONSTITUTION.md §3.4` (ADR-007, immuable).

**Correctif** : `lang/fr/validation.php` réellement traduit. Les 4 clés déjà françaises (`confirmed`, `date_format`, `multi_variation`, `items_cap_exceeded`) préservées à l'identique — un banc dédié le vérifie.

**Preuve** : `tests/Feature/Settings/FrenchValidationMessagesAreTranslatedTest.php` — 3 verts ; mutation (remise d'une clé en anglais) → **2 rouges**.

**Ma propre erreur, attrapée par ma propre sentinelle** : la première exécution a signalé `custom.attribute-name.rule-name` — un gabarit Laravel, identique dans toutes les langues par conception. C'était **mon test qui était faux**, pas le fichier. Exclusion ajoutée avec sa justification.

---

## GATE FINAL

| Gate | Résultat |
|---|---|
| **Vitest complet** | **2876 verts / 3 échecs** sur 2882 (baseline W1 : 2874 verts / **5** échecs) — **2 échecs fermés**, 0 introduit |
| **Nouveaux bancs JS** | 16 verts (6 + 10), tous prouvés par mutation |
| **Nouveaux bancs PHP** | 6 verts (3 + 3), tous prouvés par mutation |
| **E2E local** (auth · POS cash · KDS) | **8 verts / 2 échecs** — **identique à la baseline sans mes correctifs** (causalité vérifiée par retrait + recompilation + rejeu) |
| **Diff frozen-zones** | **0 fichier** sur les 12 chemins de `CLAUDE.md §7` |
| **Chaîne NF525** | `audit_logs` 5613 → 5654 (**append-only**, croissance due aux commandes créées par l'e2e) · `fiscal:verify-chain --all` → **CHAIN OK sur les 4 branches** |
| **Build** | `npm run production` — Compiled Successfully |
| **Correctif actif dans le bundle servi** | **prouvé en navigateur** (voir C1) |

### Les 3 échecs Vitest restants — tous préexistants, causalité établie
Vérifiés en retirant mes modifications (`git stash`) et en rejouant : **échecs identiques**.

1. `posHeaderReorg.spec.js` — voie POS, session parallèle active (`PosComponent.vue` committé à 16:32).
2. `v1HiddenMenuModules.spec.js` — préexistant.
3. `kdsBundleFreshnessSentinel.spec.js` — le fragment `admin-kds.<hash>.js` (10/08 14:44) est plus ancien que `resources/js/helpers/kdsSymbolic.js` (10/08 19:47, **modifié non committé par la session Uber**). ⚠️ **Ma recompilation n'a PAS levé cette sentinelle** : webpack n'a réémis que `app.js` et `pos-app.js`. Soit le fragment KDS n'est pas réellement affecté par ce fichier, soit la sentinelle vise le mauvais fragment — **à trancher par la voie KDS, pas par moi**.

### Les 2 échecs E2E restants — préexistants, causalité établie
`02-pos-cash > full POS cash order cycle — adversarial` et `04-kds-status > KDS adversarial`. Protocole : retrait de mes 5 fichiers → **recompilation complète** → rejeu → **mêmes 2 échecs**. Puis restauration → recompilation → rejeu → **mêmes 2 échecs**. Mes correctifs sont neutres.

Signal positif au passage : `login chef via /login → redirection vers surface chef` et `KDS surface loads order list without crash` **passent**, donc le durcissement des permissions du chef ne coupe pas l'accès cuisine.

### ⚠️ Changement de comportement à connaître
Trois entrées de menu qui s'affichaient **pour tous les rôles** sont désormais soumises à leur permission : **Ingrédients**, **Scan facture**, et les écrans **composer**. Mesuré sur le compte chef réel : `ingredients_manage` passe de `true` à `false`, tandis que le témoin `items` (permission saine) reste `false` — donc pas de sur-restriction.

C'est le correctif voulu : le menu reflète enfin le modèle de permissions. **Si l'owner veut que les chefs accèdent aux ingrédients, il faut leur accorder la permission** — et non compter sur un garde-fou inopérant.

---

## Ce qui reste ouvert (constaté, non corrigé)

| # | Constat | Pourquoi pas maintenant |
|---|---|---|
| 1 | 4 réglages orphelins (`kiosk_admin_pin`, barème de livraison, `site_email_verification`, champs `company_*`) | Chacun a **deux issues honnêtes** — implémenter le lecteur, ou retirer le champ. C'est un arbitrage owner, pas un choix technique. |
| 2 | Mollie hors admin (`config/payment.php` → `.env`, aucune ligne en base) | Touche le paiement — voie de la session parallèle, et gate fiscal adjacent. |
| 3 | Message de validation **en anglais** sur `/login` (viole `CONSTITUTION.md §3.4`) | Correctif simple, mais à faire après le lot en cours pour ne pas mélanger les preuves. |
| 4 | `/admin/uber-photo` en barre latérale **sans aucune API** | Écart arbre-de-travail / dépôt de la session Uber-photo. **Pas mon lot.** |
| 5 | Rôle `Tenant Admin` invoqué dans 12+ contrôleurs, inexistant en base ; rôle nommé « 3 » | Nettoyage de données + revue d'autorisation : mérite son propre lot. |
| 6 | Divergences de comptes (3185 / 3186 / 3191) et commandes à 0,00 € « Payé » | **À qualifier avant tout constat** — peut être légitime. |
