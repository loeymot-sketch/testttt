# W2 — Fermeture des constats ouverts (pipeline GStack)

**2026-08-12 · branche `pos/category-first-caisse-2026-06-23` · aucun commit, aucune poussée**

Suite de W1. Pipeline GStack : ORIENT → REVIEW parallèle (3 agents lecture seule) → STOP → BUILD TDD → TEST → GATE VISUEL → mutation.

---

## Le résultat le plus important de cette vague : **trois constats sur quatre étaient de FAUSSES pistes**

Un audit honnête resserre le périmètre au lieu de l'élargir. Trois des cinq constats ouverts de W1 ne sont **pas** des défauts, et je le dis avec la même force que s'ils l'étaient.

### ❌ ÉCARTÉ — « 3185 vs 3186 » n'existe pas
Les deux chiffres sortent de **la même méthode** (`DashboardService.php:393`), par **la même route**, **sans cache**. Le SQL est identique par construction. La grille exhaustive rejouée (alive/soft-deleted × branche × parent) donne 3185, 3189, 3191, 3195, 3531, 3536, 3537, 3542 — **aucune combinaison ne vaut 3186**. C'était un artefact de capture entre deux instantanés de base. **Aucune correction.**

### ❌ ÉCARTÉ — les commandes à 0,00 € « Payé » sont de la donnée de test, prouvée
Les 6 lignes (ids 6263-6268) viennent du canal **Uber photo** en pilote `mock` : `vision_driver='mock'`, `customer_name='Test Cuisine'`, et `photo_paths` pointe un **fichier JSON de scénario** (`storage/app/uber-tickets/*.json`) contenant `"total": 0`.

Le zéro est **délibéré**, pas un calcul raté — `UberPhotoOrderMapper.php:208-213` force `unit_price => 0.0` avec sa raison écrite : « Les montants appartiennent à Uber (déjà encaissés, canal non fiscalisé) : on n'en invente aucun à partir d'une photo ».

Preuves de non-nuisance : **0 numéro fiscal** consommé (`WHERE total=0 AND fiscal_sequence_no IS NOT NULL` → 0) · **0 ligne** `transactions` · canal exclu du CA par `Order::scopeRealizedRevenue` (`Order.php:337-339`). **Aucun euro perdu, aucune vente encaissable à 0 €.**

### ❌ ÉCARTÉ — `Tenant Admin` est une branche 100 % morte
37 occurrences, dont 15 gates exécutables. **Aucun gate où `Tenant Admin` est seul** : tous sont `hasRole('Admin') || hasRole('Tenant Admin')` ou `role:Admin|Tenant Admin` — l'arm `Admin` suffit toujours. Les 2 seeders font `Role::where(...)->first()` puis `if ($role)` : rôle absent ⇒ itération sautée, **aucun rôle fantôme créé**. Sa création est même déjà interdite (`RoleRequest.php:42`, `Rule::notIn(['Tenant Admin'])`).

**Aucun utilisateur réel n'est bloqué.** Le rôle « 3 » : `id=14`, **0 porteur**, **0 permission** — artefact d'un essai d'interface, inerte, ne pollue que la liste déroulante.

### ❌ ÉCARTÉ — `v1HiddenMenuModules` était déjà réparé
Le test échouait parce qu'il attendait 33 clés alors que la config en a 32 — l'écran **Règles de fidélité** a été volontairement démasqué (commit `81dc987b1` : « l'exploitant n'avait aucun moyen de régler son barème »). C'est **le test** qui était périmé, et la session parallèle l'a aligné en `8b894b371`. Vérifié : **6/6 vert**.

---

## ✅ LE SEUL VRAI DÉFAUT — `RAPPORT-VENTES-DEUX-COMPTES`

### Ce que voyait l'exploitant
Sur **le même écran**, à quinze centimètres l'un de l'autre :
- tuile « Total Commandes » → **3185**
- pied de tableau → « 1 à 10 sur **3191** entrées »

### La cause, prouvée
`SalesReportListComponent.vue:486` et `:492` envoient **le même objet de recherche** aux deux endpoints. Le seul écart est côté serveur :
- `OrderService::salesReportOverview()` **écarte** les miroirs de remboursement (`OrderService.php:3309`)
- `OrderService::list()` ne le faisait **pas**

Les 6 lignes de l'écart sont nommées et vérifiées en base : ids **227, 4226, 4547, 4549, 4559, 4607** — serials `RTN-*`, statut RETURNED, totaux **négatifs** (-11, -8, -30, -24, -4, -12 €). Des **contre-écritures comptables**, comptées comme des ventes.

**Encore le jumeau oublié** : le heal « SELF-AUDIT R3 P2 2026-07-05 » a été appliqué à `salesReportOverview()` et pas à `list()`, alors que la doctrine écrite dans le code dit « TOUS les compteurs excluent les miroirs ».

### Le correctif — et pourquoi je n'ai PAS suivi la recommandation reçue
L'analyse suggérait « ajouter `whereNull('parent_order_id')` dans `OrderService::list()` ». **J'ai refusé** : `list()` sert **six** contrôleurs (historique, commandes caisse, commandes en ligne, commandes table, rapport, export). Filtrer globalement aurait fait **disparaître les remboursements de l'historique** — une régression pire que le défaut.

Correctif retenu : un **paramètre de méthode**, jamais un champ de requête — seul le serveur le positionne, le navigateur ne peut pas l'influencer. **Faux par défaut** : les 5 autres appelants gardent exactement leur comportement.

Appliqué aux **trois** jumeaux du rapport : écran (`SalesReportController:53`), PDF (`:82`), tableur (`SalesReportExport:35`). Un PDF ne peut pas compter autrement que son propre résumé.

### Preuve sur données de production locale

| | avant | après |
|---|---|---|
| tuile « Total Commandes » | 3185 | **3185** |
| pied de tableau du rapport | **3191** | **3185** ✅ |
| historique (contrôle) | 3191 | **3191** — inchangé ✅ |

Aucune information perdue : les remboursements restent visibles là où ils ont leur place.

### Bancs et mutations

**`tests/Feature/Reports/SalesReportListMirrorParitySentinelTest.php`** — 3 verts, dont un **banc anti-sur-correction** dédié.

| Mutation | Détectée par | Message |
|---|---|---|
| Filtre neutralisé (défaut restauré) | 2 bancs | « annonce 3 entrées quand la tuile annonce 2 » |
| Filtre appliqué à TOUS les appelants (sur-correction) | 1 banc | « le filtre du rapport des ventes a fuité sur un chemin partagé » |

**`tests/e2e/sales-report-mirror-parity.spec.js`** — 2 verts, lit l'**écran** et non l'API. Mutation → échec avec les vrais chiffres : *« L'écran annonce 3185 commandes dans sa tuile et 3191 entrées dans son tableau. »*

**Gate visuel** (CLAUDE.md §6) : capture `tests/captures/goal-ops-swap-2026-08-12/sales-report-parity.png` **lue et analysée** — mise en page intacte, tout en français, aucun libellé brut, tuile lisible.

---

## ✅ DURABLE — sentinelle à cliquet sur les réglages orphelins

`tests/Feature/Settings/OrphanSettingsRatchetSentinelTest.php`

Un réglage orphelin est une clé que l'admin fait **saisir et enregistrer**, et que plus rien ne **lit**. La sentinelle scanne les 9 formulaires de réglages, cherche un consommateur hors chemin d'écriture, et **échoue dans les deux sens** :

- un réglage **neuf** que rien ne lit apparaît → « tu ajoutes une promesse vide »
- un orphelin connu **gagne un lecteur** → « retire-le de la liste »

Elle mesure exactement les **10 clés** documentées — confirmation indépendante de l'inventaire W1. Un **témoin sain** (`site_phone_verification`) prouve que la détection fonctionne, sinon le banc validerait n'importe quoi.

Mutations : retrait d'une clé → « RÉGLAGE ORPHELIN NEUF » ✅ · ajout d'une clé déjà lue → « CLIQUET À RESSERRER » ✅

**Les 10 orphelins restent une décision owner** (implémenter le lecteur, ou retirer le champ) — je les ai documentés et verrouillés, pas tranchés à sa place.

---

## Gate W2

| Gate | Résultat |
|---|---|
| PHPUnit ciblé | **509 verts** — Reports 11 · Order 88 · Orders 11 · Report 5 · Dashboard 27 · Sentinels 357 (2 skip) · Settings 10 |
| Nouveaux bancs | 5 (3 PHP parité + 2 PHP cliquet) + 2 e2e — **tous prouvés par mutation** |
| E2E | rapport des ventes **2/2**, mutation détectée |
| Gate visuel | capture lue et analysée |
| Diff frozen-zones | **0 fichier** |
| Chaîne NF525 | `fiscal:verify-chain --all` → **CHAIN OK, 4 branches** |

## Fichiers touchés en W2

| Fichier | Nature |
|---|---|
| `app/Services/OrderService.php` | modifié — paramètre optionnel + filtre conditionnel (**zone partagée §6, coordination déclarée**) |
| `app/Http/Controllers/Admin/SalesReportController.php` | modifié — 2 sites d'appel |
| `app/Exports/SalesReportExport.php` | modifié — 1 site d'appel |
| `tests/Feature/Reports/SalesReportListMirrorParitySentinelTest.php` | **neuf** |
| `tests/Feature/Settings/OrphanSettingsRatchetSentinelTest.php` | **neuf** |
| `tests/e2e/sales-report-mirror-parity.spec.js` | **neuf** |

**Aucun commit, aucune poussée.**

---

## Ce qui reste ouvert — et pourquoi je m'arrête là

| # | Sujet | Pourquoi je ne tranche pas |
|---|---|---|
| 1 | **10 réglages orphelins** | Chacun a deux issues honnêtes — écrire le lecteur, ou retirer le champ. C'est un arbitrage produit. Verrouillés par le cliquet en attendant. |
| 2 | **Mollie hors admin** — l'écran PaymentGateway écrit en base, Mollie lit `.env` | Touche le paiement : voie de la session parallèle + gate fiscal adjacent. |
| 3 | **Le rapport des ventes liste les commandes Uber à 0,00 €** que son total exclut | Écart de lisibilité, pas de caisse (prouvé). Les masquer est une décision produit : faut-il voir Uber dans le rapport des ventes ? |
| 4 | **Rôle « 3 »** en base, 0 porteur, 0 permission | Suppression de donnée : jamais sans accord explicite. |
| 5 | **`kdsBundleFreshnessSentinel`** — seul rouge Vitest restant | **Instruit, pas silencié.** Voir l'encart ci-dessous : deux hypothèses, les deux exigent la voie KDS. |
| 6 | ~~`posHeaderReorg`~~ · ~~`v1HiddenMenuModules`~~ | **Passés au vert** pendant la vague — la session parallèle les a alignés. |

---

## ⚠️ Encart pour la voie KDS — `kdsBundleFreshnessSentinel`, à instruire

C'est le **seul rouge Vitest restant** (2878 verts / 1 échec). Je l'ai instruit sans le faire taire, parce qu'une alarme rouge en permanence finit par être ignorée — c'est exactement ce qui s'était produit avec l'alarme fiscale « connue et gatée » pendant six semaines, qui était un faux positif.

**Établi avec certitude :**
1. La sentinelle compare `public/js/admin-kds.cddea678.js` (**10/08 14:44**) à `resources/js/helpers/kdsSymbolic.js` (**10/08 19:47**) → rouge.
2. Une compilation `npm run production` **complète** n'a **pas** réémis ce fragment : seuls `app.js` et `pos-app.js` ont été réécrits.
3. Le fragment est pourtant **toujours listé** dans `public/mix-manifest.json`.
4. **14** fichiers `admin-kds.*.js` orphelins traînent dans `public/js/`.
5. Le marqueur `symbolic-menu` est présent à la fois dans `admin-shell.34dc616b.js` (**frais, 17:51**) et dans le fragment KDS périmé.

**Non établi :** si le fragment KDS a réellement besoin d'être réémis. Le trancher demande de lire le graphe de fragments webpack, et `kdsSymbolic.js` porte **118 lignes ajoutées non committées** par une autre session.

**Les deux hypothèses, et pourquoi aucune n'est bénigne :**
- **(a)** Le fragment n'est pas affecté par ce fichier ⇒ la sentinelle est **mal ciblée** et restera rouge à vie.
- **(b)** Il l'est ⇒ **la cuisine tourne sur du code symbolique périmé**.

⛔ Ne pas « réparer » en ajustant le seuil ou en supprimant la sentinelle. La question à répondre est : **quel artefact sert réellement l'écran cuisine aujourd'hui ?**
