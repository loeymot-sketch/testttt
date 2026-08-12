# W1 — Cartographie runtime du back-office : constats

**GOAL** : `GOAL-OPS-RELIABILITY-SWAP-MULTIMARQUE-2026-08-12`
**Date** : 2026-08-12 · **HEAD au démarrage** : `39ee3eb76` · **Branche** : `pos/category-first-caisse-2026-06-23`
**Nature** : lecture seule. Aucune édition produit dans cette vague.

---

## 0. Contexte d'exécution (W0)

| Élément | Constat |
|---|---|
| `npm run verify:boucle` | `CONDITIONAL` — binaire `claude` OK, smokes API non lancés (défaut) |
| Réservations d'agents | Dernière entrée `2026-05-05` → **aucune réservation vivante** |
| Fichiers sales | 122 à l'ouverture → **111** après les commits de la session parallèle |
| **Frozen sales** | **0** ✅ (12 fichiers de la liste `CLAUDE.md §7` vérifiés un par un) |
| Chaîne NF525 | `audit_logs` = **5613**, dernier hash `cc34d1c829c2abc3` |
| Base | MySQL `foodking_e2e` |
| Serveur dev | `http://127.0.0.1:8000` → HTTP 200 |
| Sauvegarde | branche `backup/pre-goal-ops-swap-2026-08-12` sur `39ee3eb76` |

### ⚠️ Session parallèle active — attribution de la collision (porte G1)

L'instantané de session annonçait `HEAD=590e1cc62`. Le HEAD réel était `39ee3eb76` : **4 commits ont atterri aujourd'hui entre 16:32 et 16:57**, pendant/juste avant cette session, en style narratif du projet.

| Commit | Heure | Sujet |
|---|---|---|
| `1bbd1cd50` | 16:32 | fidélité W5 — écran de comptoir |
| `744bf89fe` | 16:35 | cuisine — réclamation d'impression par destination |
| `3b4a4510c` | 16:42 | doc brain §2 |
| `39ee3eb76` | 16:57 | roue — produits à gagner |

**23 fichiers, 0 frozen.** Voies occupées : `Admin/Pos/**`, `Services/Kitchen/**`, `Services/Loyalty/**`, `Services/Wheel/**`, `components/admin/pos/**`, `components/admin/kitchen/**`.

**Décision** : collision **attribuée**, donc pas une condition d'arrêt. Voie réservée au registre (`GOAL-OPS-SWAP-W1-CARTO`) et **voies ci-dessus exclues de tout lot de ce GOAL** tant que la session parallèle tourne.

**Conséquence sur l'ordre des vagues** : la vague W2 du GOAL (confinements P0 du chantier A) touche **impression et tiroir** — exactement la voie de la session parallèle. Réordonner : **les vagues back-office (B) passent avant W2**, qui attendra la fin de la session parallèle. Règle appliquée : « jamais de fenêtre d'édition concurrente sur paiement, impression ou stock ».

---

## 1. Ce qui INFIRME l'hypothèse « des pages ne fonctionnent pas »

Preuves négatives, à énoncer aussi franchement que les défauts :

1. **154 imports dynamiques de vues admin, 0 non résolu.** Aucun écran ne manque de fichier.
2. **166 / 175 endpoints admin `GET` sans paramètre répondent 200** avec un jeton admin réel.
3. Les 9 non-200 sont, sauf un cas, des **refus corrects** : paramètre requis manquant (`kds-order/sync`, `menu-projection`), route publique appelée sans corps (`loyalty/balance`, `refresh-token`), ou hors zone de service (`branch/lat-long`).
4. **Le tableau de bord fonctionne** et il est dense : accès rapides, total ventes 39 945,13 €, total commandes, alertes SLA, répartition par canal.
5. **Les 32 écrans de réglages ont tous** un composant, une route SPA et un endpoint vérifiés — 0 introuvable.

La plainte owner est donc réelle mais **mal localisée** : ce n'est pas « la page n'existe pas », c'est « l'action échoue sans le dire ».

---

## 2. CONSTAT P1 — `EXPORT-BLOB-MUET` : 20 écrans annoncent l'échec par le mot « undefined »

**Reproduction (faite dans Chrome, session admin réelle, sur `/admin/sales-report`)** :

```js
await axios.get('admin/sales-report/pdf', { responseType: 'blob' })
// → 422
// err.response.data          : [object Blob]        (PAS un objet JSON)
// err.response.data.message  : "undefined"          ← ce que l'écran affiche
// contenu réel du Blob       : {"status":false,"message":"Trop de lignes pour un
//                               export PDF (3191 lignes). Affinez la période
//                               avec un filtre de date."}
```

**Chaîne de causes, chaque maillon vérifié :**

| # | Maillon | Preuve |
|---|---|---|
| 1 | L'écran n'a **aucune période par défaut** | `SalesReportListComponent.vue:389-390` → `from_date: ""`, `to_date: ""` |
| 2 | Sans période, l'export porte sur **toute la base** (3191 lignes) | sonde runtime ci-dessus |
| 3 | Le serveur refuse **à raison** au-delà de 2000 lignes | `SalesReportController.php:82-88` (garde anti-OOM, intentionnelle) |
| 4 | La requête est faite en `responseType: 'blob'` | `store/modules/salesReport.js:81` |
| 5 | Donc le corps d'erreur est un **Blob**, jamais désérialisé | prouvé en navigateur |
| 6 | L'écran lit `err.response.data.message` sur ce Blob → `undefined` | `SalesReportListComponent.vue:526` |

**Ce que vit l'exploitant** : il clique « PDF », rien ne se télécharge, et l'alerte lui dit `undefined`. Il en conclut — exactement comme l'owner — que « le rapport ne fonctionne pas ». Le serveur lui avait pourtant donné la solution en français.

**Le jumeau oublié — ce n'est pas un écran, c'est vingt.** Même motif (`responseType: 'blob'` côté store + `err.response.data.message` côté écran) :

`salesReport` · `itemsReport` · `onlineOrder` · `creditBalanceReport` · `transaction` · `posOrder` · `item` · `itemCategory` · `customer` · `subscriber` · `coupon` · `offer` · `waiter` · `chef` · `employee` · `administrator` · `deliveryBoy` · `diningTable` · `tableOrder` · `pushNotification`

**Verdict** : une seule cause racine, 20 surfaces. À corriger en un point commun, pas 20 fois.

---

## 3. CONSTAT P2 — `CONFIG-REPORT-FANTOME` : un plafond réglable que rien ne peut régler

`config('report.pdf_max_rows', 2000)` est lu par **3** contrôleurs :
- `SalesReportController.php:82` · `OnlineOrderController.php:92` · `ItemsReportController.php:69`

**`config/report.php` n'existe pas** (`ls config/` — 46 fichiers, aucun `report.php`). La valeur retombe donc **toujours** sur le défaut codé en dur `2000`, et aucune variable d'environnement ne peut l'atteindre (sans fichier de config, `env()` n'est jamais consulté).

Même motif que les orphelins du §4 : une clé qui a l'air pilotable et ne l'est pas.

---

## 4. CONSTAT P1 — Réglages orphelins : écrits par l'admin, lus par personne

Source : inventaire des 32 réglages (`inventaire-32-reglages.md`, même dossier). 28/32 opérants ; **4 portent des clés sans lecteur**.

| # | Clé | Écrite | Lue par | Conséquence |
|---|---|---|---|---|
| ① | `kiosk_admin_pin` | `KioskSetupRequest.php:22` → groupe `kiosk_setup` | **rien** — `SettingResource.php:116` renvoie littéralement `null` | L'exploitant croit protéger l'admin de la borne par un code à 4 chiffres. **Aucun écran ne le demande.** |
| ② | `order_setup_free_delivery_kilometer`, `..._basic_delivery_charge`, `..._charge_per_kilo` | `OrderSetupRequest.php:32-34` | **aucun calcul de prix** — le seul calculateur `DeliveryFeeService.php:26-56` lit les colonnes de `branches` | Régler « 1 €/km » dans l'admin ne change **aucun montant facturé**. |
| ③ | `site_email_verification`, `site_auto_update` | `SiteRequest.php:37,42` | **0 occurrence** | Le jumeau `site_phone_verification` est, lui, gardé jusqu'au preflight. Le verrou e-mail ne verrouille rien. |
| ④ | `company_city`, `company_state`, `company_zip_code`, `company_website` | `CompanyRequest.php:36-40` (**obligatoires**) | **0 occurrence** | Champs imposés à la saisie que personne ne lit ; l'adresse d'établissement n'est jamais composée en entier. |

**Réserve majeure — `PaymentGateway`** : l'écran écrit dans `gateway_options`, lu par Stripe/Paypal. Mais la passerelle carte **vivante** est Mollie, qui lit `config/payment.php:115-116` → `.env` (`Mollie.php:74-75`). Aucune ligne `mollie` en base. **Activer ou couper le paiement en ligne depuis l'admin n'a aucun effet.**

**Défaut miroir (lu mais non écrivable)** : `kiosk_languages_enabled`, `kiosk_default_language` (`SettingResource.php:111-112`) et `order_setup_wait_cap` (`WaitEstimateService.php:66`).

**Cause structurelle commune** : deux stockages coexistent sans arbitre — table `settings` via `Settings::group()`, et `.env` via `EnvEditor`. C'est la même racine que l'incident des codes promo (deux interrupteurs, deux endroits).

---

## 5. CONSTAT P2 — Un message anglais sur l'écran de connexion

`CONSTITUTION.md §3.4` (ADR-007, immuable) : « Locale FR. Pas de message anglais user-facing. »

Capture navigateur de `/login` avec un e-mail invalide : **« The email must be a valid email address. »**

C'est le tout premier écran du produit.

---

## 6. À VÉRIFIER (signalé, pas conclu)

1. **Trois comptes de commandes divergents** : tuile tableau de bord **3185** · API `dashboard/total-orders` **3186** · rapport des ventes « 1 à 10 sur **3191** entrées », sur le même écran que sa propre tuile à 3185. Peut être légitime (populations et fenêtres différentes) — **à prouver, pas à supposer**.
2. **Commandes à 0,00 € marquées « Payé »** — plusieurs lignes du rapport des ventes (`#MSN89BUJ`, `#MSN7ZEJN`, `#MSN7X8MU`, `#MSN7TZIC`, `#MSN7LQFN`, `#MSN7IH1E`), toutes du 10-08-2026. Peut être de la donnée de test. **À qualifier avant tout constat.**
3. **331 alertes SLA** avec des tickets « en attente depuis 62 j 18 h » — recoupe le retard historique déjà connu du chantier A (RQ-15 / `GLOB-OPS-20`). Pas un défaut neuf.
4. **23 entrées de menu masquées** par `resources/js/config/v1-hidden-modules.js:23-54`, routes toujours joignables par URL directe. Choix V1 assumé ou oubli ? **À trancher avec l'owner.**

---

## 7. Suite immédiate

1. Corriger `EXPORT-BLOB-MUET` en **un point commun** (§2), test fail-first d'abord, mutation prouvée.
2. Créer `config/report.php` et y porter le plafond (§3).
3. Trancher les 4 réglages orphelins (§4) : implémenter le lecteur, ou retirer le champ. **Pas de troisième voie.**
4. Franciser la validation de connexion (§5).
5. Qualifier les 4 points du §6 avant d'en faire quoi que ce soit.
