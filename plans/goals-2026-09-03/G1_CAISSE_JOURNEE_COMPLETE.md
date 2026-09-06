# G1 — La caisse voit toute la journée de service

Défauts couverts : **V-01** (P1), **V-13** (P2)
Dépendances : aucune. Exécutable immédiatement.

---

## Le défaut, dit simplement

Le tiroir de contrôle de la caisse affiche « les commandes du service ». Il en demande **100**,
sur **une seule page**, triées de la plus récente à la plus ancienne. Puis il présente cette page
comme la journée entière.

Un soir de rush au-delà de 100 commandes, ce sont donc **les plus anciennes qui disparaissent de
l'écran** — précisément celles qui traînent, celles qu'il faut voir. Et rien ne le signale : ni
bandeau, ni compteur tronqué, ni indication de page.

Ce qui devient faux en silence :
- les quatre files du tiroir (à encaisser / cuisine / prêtes / livrées) ;
- les deux badges de la barre de caisse (`pos-control-badge-cash`, `pos-control-badge-ready`) ;
- `activeOrdersStats` et `readyOrders` ;
- le rang cuisine affiché sur le ticket en cours (« vous êtes le 4ᵉ ») — sous-estimé.

## Ancres vérifiées (2026-09-03)

| Fichier | Ligne | Ce qu'on y lit |
|---|---|---|
| `resources/js/components/admin/pos/PosComponent.vue` | 4777 | commentaire : « `paginate` fait HONORER `per_page` (sans lui le serveur renvoie TOUTE la journée) » |
| `resources/js/components/admin/pos/PosComponent.vue` | 4782 | `per_page: 100` |
| `resources/js/components/admin/pos/PosComponent.vue` | 4802, 5312 | ne lisent que `res.data.data` — `meta.total` et `last_page` ignorés |
| `resources/js/components/admin/pos/PosOrdersTrackerComponent.vue` | 1856 | même plafond de 100 |
| `app/Services/OrderService.php` | 137-140 | `->get('*')` sans `paginate` quand `per_page` est absent ; tri `id desc` par défaut |
| `app/Http/Requests/PaginateRequest.php` | 27 | `max:1000` — seule borne serveur existante |

**Point clé** : il n'existe **aucun plafond serveur**. Le 100 est un choix purement client.

## Décision d'architecture

Trois voies possibles. La retenue est la **B**.

- **A — enlever `per_page`** : le serveur renvoie tout. Rejeté : sans borne, une journée à 900
  commandes fait un payload non borné sur une caisse qui doit rester instantanée.
- **B — endpoint serveur borné aux quatre files** (retenu) : la caisse ne demande que ce que le
  tiroir affiche — les commandes du jour dans les quatre états utiles — sans plafond arbitraire,
  avec un `total` renvoyé et affiché.
- **C — pagination cliente complète** : boucler jusqu'à `last_page`. Rejeté : N requêtes en
  cascade sur le chemin le plus chaud de la caisse.

Si B se révèle trop large en périmètre à l'exécution, se rabattre sur **A + borne serveur du jour
de service** (`business_date = today`), qui donne le même résultat métier avec moins de code.

## Tâches

- **T1.1 — Prouver la troncature avant de la corriger.**
  Banc : `(À CRÉER) tests/js/posControlDrawerAuDelaDeCent.spec.js`
  Monter le tiroir avec 137 commandes du jour, dont la plus ancienne à encaisser.
  Sans correctif : la plus ancienne est absente et le badge affiche 100. **Le banc doit rougir ici.**
  Consigner la rougeur dans `reports/supervision/2026-09-03/G1-banc-mord.txt`.

- **T1.2 — Banc serveur du contrat.**
  Banc : `(À CRÉER) tests/Feature/Pos/PosControlDrawerJourneeCompleteTest.php`
  137 commandes en base sur `business_date` du jour ; l'endpoint doit renvoyer les quatre files
  complètes **et** un `total` exact. Vérifie aussi qu'une commande d'une autre branche n'y entre pas.

- **T1.3 — Implémenter la voie B.**
  Endpoint borné au jour de service et aux quatre états ; `PosComponent.vue` et
  `PosOrdersTrackerComponent.vue` le consomment ; `meta.total` lu et affiché.

- **T1.4 — Rendre la troncature impossible à cacher.**
  Si une borne subsiste pour une raison quelconque, l'écran doit l'annoncer (« 100 affichées sur
  137 »). Un compteur silencieusement faux est pire qu'une borne assumée.
  Banc : le même `posControlDrawerAuDelaDeCent.spec.js`, cas « borne assumée ».

- **T1.5 — V-13 : parité des enums JS/PHP.**
  Les 13 valeurs de `resources/js/support/filesControle.js:18-23` et
  `resources/js/support/fileCuisine.js:34-40` concordent **aujourd'hui** avec `app/Enums/`.
  Ce n'est donc pas un défaut actif, c'est une dette : rien n'empêche la divergence demain.
  Banc : `(À CRÉER) tests/Feature/Sentinels/EnumsJsPhpConvergenceSentinelTest.php` — lit les
  valeurs PHP de `OrderStatus`, `PaymentStatus`, `OrderType`, `PosPaymentMethod`, lit les
  littéraux JS, échoue si un seul diverge. Puis remplacer les copies par les enums JS canoniques
  de `resources/js/enums/modules/`.

## Acceptation

- `tests/js/posControlDrawerAuDelaDeCent.spec.js` — VERT, et prouvé rouge sans le correctif.
- `tests/Feature/Pos/PosControlDrawerJourneeCompleteTest.php` — VERT.
- `tests/Feature/Sentinels/EnumsJsPhpConvergenceSentinelTest.php` — VERT.
- Non-régression : `tests/js/posControlDrawer.spec.js`, `tests/js/posTrackerStaleness.spec.js`,
  `tests/Feature/Pos/PosOrderListLeanPaginationTest.php` — VERTS.
- E2E : `tests/e2e/goal-caisse-controle-2026-09-02.spec.js` — 11/11 VERTS (base 2026-09-03).

## Surface visuelle

`http://127.0.0.1:8766/admin/pos` — tiroir ouvert, quatre onglets, avec >100 commandes en base.
Capture lue et analysée : la plus ancienne commande à encaisser est visible, le badge dit vrai,
aucun libellé brut, aucune erreur console.

## Condition de sortie

Deux rondes consécutives identiques, P0+P1 = 0 sur ce périmètre, captures analysées, zone gelée
intacte.
