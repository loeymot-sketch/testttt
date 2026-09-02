# BRIEF — Barre de contrôle de la caisse (GOAL CAISSE CONTRÔLE 2026-09-02)

## Demande propriétaire (verbatim condensé)
« Toute l'interface de gestion de la caisse : être plus productif, contrôler toutes les commandes
livrées, voir ce qui est en cours, les commandes prêtes, celles pas encore encaissées, toutes celles
en cuisine — toujours voir ce qu'il y a dedans EN MODE TECHNIQUE : nom des produits, heure de
commande, numéro, et COMBIEN D'ATTENTE par rapport à la cuisine, directement depuis la caisse.
Je me perds toujours pour les commandes pas encaissées entre les clients qui viennent : je dois
voir LEUR commande. Pour les commandes en cours, je ne veux PAS que ça ouvre une nouvelle page :
directement en petite barre à droite, tout plus dynamique. »

## Faits mesurés (captures `captures-avant/`, semis `helpers/seed-caisse-controle.js`)
Service semé : 4 en cuisine (K1 borne 14 min à encaisser · K2 borne 9 min à encaisser · P1 comptoir
« Karim » 6 min payée · T1 téléphone « Mme Diallo » 3 min à encaisser), 2 prêtes (R1 borne 16 min ·
R2 comptoir « Sofiane » 11 min), 1 web à accepter (W1 « Julie B. »), 2 livrées (D1, D2).

| # | Constat (capture) | Preuve code |
|---|---|---|
| DEF-1 | « Prêt à livrer (1) » n'affiche que la borne A9041 ; **la commande comptoir prête R2 (Sofiane) est INVISIBLE**. Badge « Suivi commandes 3 » alors que le suivi dit « 7 actives ». | Le panneau et le badge sont nourris par `orderStatusScreenOrder/lists` = `admin/oss-order` → `OrderStatusScreenOrderService::list()` **allowlist KIOSK/TAKEAWAY** (`app/Services/OrderStatusScreenOrderService.php:59-62`). `PosComponent.vue:4601-4616` (stats) et `:5048-5073` (readyOrders, filtre POS inutile car le flux n'en contient jamais). |
| DEF-2 | « À encaisser — comptoir (5) » : N° + prix + 3 boutons. **Ni produit, ni heure, ni canal, ni nom.** Le caissier ne peut pas reconnaître le client devant lui. | `PosComponent.vue:530-620` (template shortcuts cash). |
| DEF-3 | Deux commandes PENDING_COUNTER **d'un autre jour** (A0010/A0011, 20:09) occupent les 2 premières lignes ; la commande téléphone du jour (T1) est reléguée derrière « Voir plus (1) ». | `routes/api.php:993-1071` counter-collect/pending = all-time, `orderBy('created_at')`, cap 200, `OrderDetailsResource` (**110 requêtes SQL / 194 modèles par tick**, debugbar capture 02). |
| DEF-4 | **Aucune vue « file cuisine »** : le suivi met K1/K2/T1 (en cuisine mais à encaisser) dans « À encaisser » et affiche « EN PRÉPARATION 1 » alors que 4 commandes cuisent. **Aucun rang / « combien devant »** nulle part. | `PosOrdersTrackerComponent.vue:1380-1440` (bucket accept prioritaire sur preparing). |
| DEF-5 | Consulter les commandes en cours = quitter la caisse : clic « Suivi » → **~4,1 s**, page entière ; « Retour caisse » → nouvelle page encore. Depuis `/admin/pos-v4` (bundle `pos-app.js`) c'est une navigation dure par construction. | `resources/js/pos-app.js:118-160` (stubs `window.location.assign`). |
| DEF-6 | L'heure de commande n'apparaît nulle part sur la caisse principale ; le panneau borne montre HH:MM sans « il y a X min ». | `PosComponent.vue:1975` (`formatKioskTime`). |
| DEF-7 | À 1280×720, la grille produits (le travail principal) est **sous la ligne de flottaison** : barre d'actions + 2 panneaux raccourcis occupent 650 px. | capture 01. |

Chaîne d'alimentation disponible, DÉJÀ optimisée pour ça : `admin/pos-order?paginate=1&per_page=100&lean=1&composition=1&from_date&to_date`
→ `SimpleOrderResource` (**7 SQL, 77 ms, ~1 Ko/commande**, composition compacte `options/extras/addons`
sans prix, `is_cash_pending`, `cash_pending_amount`, `customer_name`, `customer_phone`, `source_surface`,
`created_at`, `scheduled_at`, `queue_number`). C'est ce que consomme le suivi (`PosOrdersTrackerComponent.vue:1800-1825`).
Budget dur : `tests/e2e/pos-request-budget.spec.js` — ≤ 32 req à l'ouverture, ≤ 12 req/min au repos.
Cadence caisse : `_kioskPollingInterval()` = 60 s si WS connecté, sinon **5 s** (`PosComponent.vue:4155`) ;
en production il n'y a **aucun serveur de sockets** (commentaire `:4176-4179`) ⇒ 5 s.
Rafale Echo → `_schedulePanelsRefresh` debounce 400 ms (`:3325`).

Zones gelées (§7) : `admin-pos-v4.blade.php`, `pos-wizard.js/.css`, `PaymentComponent.vue`, `PosV5TrancheRow.vue`
— **aucune** ne doit bouger.

## Proposition (à DISPUTER)
**P1 — Un flux unique pour tous les panneaux de la caisse.** Remplacer l'appel OSS (`_fetchOssOrdersOnce`)
par `admin/pos-order` (composition compacte, jour de service, 100 dernières). En dériver
`activeOrdersStats`, `readyOrders` (corrige DEF-1 : les commandes comptoir prêtes apparaissent),
et les 4 files de la barre de contrôle. Delta requêtes : **0** (un GET remplace un GET).
Conserver `counter-collect/pending` (Encaisser a besoin de la file all-time) mais l'utiliser
seulement pour les actions et l'indicateur « N plus anciennes ».

**P2 — « Barre de contrôle » : un tiroir latéral droit DANS la caisse** (nouveau composant
`resources/js/components/admin/pos/PosControlDrawer.vue`, monté par `PosComponent`, données en props,
zéro requête propre). Ouvert par un bouton de la barre d'actions (badge « 4 🍳 · 2 🛎️ · 3 💶 »),
raccourci clavier, et par les liens « Voir plus » des raccourcis. Ne remplace PAS le panneau borne
existant (testids conservés), mais devient l'entrée principale.
Sections (onglets ou pile, à arbitrer) :
- 💶 **À encaisser** (n) — cash-pending du jour, tri plus ancien d'abord, + ligne « N plus anciennes →
  Encaissement ». Carte : N°, canal coloré (borne/comptoir/tél/web), **heure + « il y a X min »**,
  nom/téléphone si connus, **toutes les lignes avec composition compacte** (« Tacos M · Poulet mariné ·
  Algérienne · +Cheddar »), montant dû, **rang cuisine si en cuisine** (« 2ᵉ/4 en cuisine »),
  CTA Encaisser (→ `openCounterCollect`, modal SSOT existant), Annuler (→ dialogue existant).
- 🍳 **En cuisine** (n) — ACCEPT + PREPARING, tri chronologique = ORDRE CUISINE, **rang « 1ᵉʳ … nᵉ »**
  et « il y a X min », composition, 🔔 si aussi à encaisser. Aucune action (la cuisine bump au KDS).
- 🛎️ **Prêtes** (n) — PREPARED, tous types (corrige DEF-1), CTA « Livré » (→ `markDelivered`).
- ✅ **Livrées aujourd'hui** (n) — plus récente d'abord, sourdine, composition dépliable.
Recherche instantanée (N°, nom, produit) sur les données en mémoire. Échap ferme, focus piégé,
`aria-live` sobre. Palette Cayenne (#F4501E / #FFB800 / #1A1A1A), light only.

**P3 — Le suivi devient une route SPA de `pos-app.js`** (le composant est déjà dans le chunk
`pos-shell`) : « Suivi commandes » s'ouvre sans rechargement, « Retour caisse » aussi. Encaissement /
Historique restent des navigations dures (chunk `admin-shell`).

**P4 — Compteur cuisine sur la caisse même** (ticket en cours) : « 4 en cuisine · attente ≈ X min »
(X = âge de la plus ancienne en cuisine) — le caissier peut annoncer l'attente au client qui commande.

## Questions ouvertes pour les agents
1. P1 : remplacer le flux OSS est-il sûr (qui d'autre consomme `orderStatusScreenOrder/lists` dans la
   caisse ? tests Vitest à réécrire ? sémantique jour-de-service vs all-time) — ou faut-il AJOUTER un GET
   et accepter +12 req/min à 5 s ?
2. P2 : onglets vs sections empilées ? Que doit porter la carte pour identifier un client en < 2 s ?
   Le rang cuisine doit-il compter les commandes ACCEPT non encore en PREPARING ?
3. Encaisser depuis une ligne `SimpleOrderResource` : que lit `openCounterCollect` /
   `PosCounterCollectModal` ? Le montant dû `cash_pending_amount` suffit-il ?
4. P3 : risques du montage du tracker dans `pos-app.js` (layout `DefaultComponent`, permissions,
   `beforeEnter`, chunks) ?
5. Que casse-t-on ? Quels tests existants verrouillent le comportement actuel ?

## PREUVE ajoutée 2026-09-02 08:55 — DEF-1 confirmé par un SECOND moyen
Appel direct du service qui nourrit les panneaux de la caisse, sous l'identité `pos@lecayenne.fr` :
`app(OrderStatusScreenOrderService::class)->list()` renvoie **3 commandes** sur les 9 semées.

| commande | type | statut | présente dans le flux caisse ? |
|---|---|---|---|
| K1 borne (cuisine) | 10 TAKEAWAY | 7 | OUI |
| K2 borne (cuisine) | 25 KIOSK | 7 | OUI |
| **P1 comptoir « Karim » (cuisine)** | 15 POS | 7 | **NON** |
| **T1 téléphone « Mme Diallo » (cuisine)** | 15 POS | 7 | **NON** |
| R1 borne (prête) | 10 | 8 | OUI |
| **R2 comptoir « Sofiane » (PRÊTE)** | 15 POS | 8 | **NON** |
| W1 web à accepter | 10 | 1 | NON |
| D1 / D2 livrées | 10 / 15 | 13 | NON |

⇒ le badge « Suivi commandes **3** » de la capture 01 est exactement `activeOrdersStats` calculé sur
ces 3 lignes, pendant que le suivi comptait « **7** actives ». Le filtre `allowedTypes` de
`loadReadyOrders` (`PosComponent.vue:5054`) inclut `orderTypeEnum.POS` : **ce filtre est du code mort**,
la source ne lui envoie jamais de commande POS.
Conséquence terrain : **une commande prise AU COMPTOIR et prête ne s'affiche jamais dans « Prêt à livrer »**.

## Contrainte découverte — des sentinelles verrouillent la source fautive
`tests/js/sentinels/posOssFetchCoalesceSentinel.spec.js` verrouille TEXTUELLEMENT
`orderStatusScreenOrder/lists` dans `_fetchOssOrdersOnce`, et `tests/js/sentinels/posShortcutsSentinel.spec.js`
verrouille la forme des deux panneaux (testids, `slice(0,4)`, « Voir plus », `markDelivered`,
`openCounterCollect`, tri plus-ancien-d'abord, appel dans `_startKioskPolling`, rafale Echo).
Changer la source EXIGE de re-pointer la première sentinelle et de **prouver qu'elle mord encore**
(réintroduire le défaut ⇒ elle rougit). Cf. mémoire « sentinelle au mauvais périmètre ».
