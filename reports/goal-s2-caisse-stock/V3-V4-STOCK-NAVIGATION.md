# V3 — STOCK & BOM · V4 — NAVIGATION (2026-07-29)

## V3 — Cycle stock PROUVÉ EN DB (commande réelle #6037, créée puis annulée par le vrai chemin HTTP)

### Décrément à la vente — PROUVÉ, écart 0 sur 8 compteurs
| Compteur | Avant | Attendu | Après | ✓ |
|---|---|---|---|---|
| `stock_levels` id17 (Coca 33cl) | 20 | 19 | 19 | ✅ |
| rm1 Viande hachée | 0 | −150 g (75 recette + 75 extra) | −150,000 | ✅ |
| rm3 Cheddar | 103 | 101 (1 recette + 1 extra) | 101,000 | ✅ |
| rm5 Pain | 0 | −1 pièce | −1,000 | ✅ |
| rm7 Sauce maison | 0 | −25 g | −25,000 | ✅ |
| rm9/rm10/rm11 Salade/Tomate/Oignon | 0 | −30/−30/−15 g | idem | ✅ |
| `raw_material_movements` | 12 | +7 | 19 | ✅ |
| `stock_movements` | 221 | +1 | 222 | ✅ |

Latences : `stock_levels` synchrone (<1 s) ; matières ≈5 s (queue redis).

### Reverse à l'annulation — PROUVÉ, retour EXACT au niveau initial
Tous les compteurs reviennent à l'état de départ (20 / 0 / 103 / 0 / 0 / 0…), 7 mouvements
`consumption_reversal` tracés. Anti-double-release vérifié : un seul mouvement `+1`
(les clés d'idempotence incluent le `reason`, `StockService.php:381-398`).

### 3 archétypes
Simple sans BOM (Coca) ✅ · produit à BOM (Cheese Burger, 7 lignes) ✅ · composé wizard
(variation + 2 extras résolus par `extra_group`) ✅ pour les extras. Non couvert : l'extra
« Sauce supplémentaire » lui-même (la variation sauce incluse ne consomme rien — conforme au modèle).

### Propagation 86 — < 1 s, WebSocket via outbox
`domain_events` id 11444 : `occurred_at` = `dispatched_at` = 16:14:21 → **délai < 1 s**, cible
< 2 s ATTEINTE. Sticky manuel vérifié (`manual_unavailable_since` stampé, survit au restock).

### Défauts V3
| ID | Défaut | Suite |
|---|---|---|
| **D-3 P2** | `PurchaseService` écrivait `stock_levels.on_hand` sans repasser par le SSOT de disponibilité → **produit auto-86 restait INVENDABLE après réception** (vente perdue silencieuse ; le cron préventif qui rattraperait est désactivé) | **CORRIGÉ TDD** — `StockService::syncAvailabilityAfterExternalMutation`, 2 tests (auto-86 levé / 86 manuel préservé). Sûreté transactionnelle vérifiée : l'outbox commit avec la transaction, la diffusion part en `afterCommit` |
| D-1 P2 | Aucun filet de secours 86 sur **borne** ni **KDS** (seule la caisse a un poll 30 s) → worker queue down = la borne continue de vendre un produit en rupture | **HANDOFF S1 (borne) + S5 (KDS)** — hors voie S2 |
| D-2 P2 | Cron préventif d'auto-86 **désactivé** (`catalog_v15.auto_86_preventive_cron=false`, `Kernel.php:303-308`) → auto-86 purement réactif | Noté (Kernel = registre partagé) |
| D-4 P2 | **Aucune reprise BOM au remboursement PARTIEL** : `RefundCreated` recrédite `stock_levels` mais jamais les matières (reprise BOM seulement sur `OrderCanceled`) → dérive permanente invisible | **HANDOFF** (EventServiceProvider = registre partagé) |
| D-5 P3 | `raw_material_stocks.on_hand` peut devenir négatif sans plancher (constaté −150) — par conception, mais aucune pénurie matière ne déclenche de 86 | Documenté |

Doublons de logique stock : **RÉFUTÉS** (3 appels de `decrementForOrder` partagent la même clé
d'idempotence → un seul mouvement, vérifié en DB ; aucun décrément côté JS).

Suites : `Stock` 169/0 · `RawMaterial|Purchas|Bom|Recipe` 82/0 · après correctif D-3 : 245/0.

---

## V4 — Navigation depuis la caisse : 9 PASS / 4 FAIL → 12 PASS après heals

| Action | Avant | Après | État |
|---|---|---|---|
| File d'encaissement / encaisser | 1 | 1 | PASS |
| Suivi commandes | 1 | 1 | PASS |
| Historique | 1 | 1 | PASS |
| **Filtrer par date** | **5** | **1** (chip Aujourd'hui/Hier) | **CORRIGÉ** |
| **Commandes annulées** | **5** | **1** (chip Annulé) | **CORRIGÉ** |
| Rupture 86 | 2 | 2 | PASS |
| Stock / à racheter | 2 | 2 | PASS |
| Réimpression commande active | 1-2 | 1-2 | PASS |
| **Réimpression commande CLÔTURÉE** | **IMPOSSIBLE** | **2** | **CORRIGÉ** (bouton 🖨 dans l'historique, vérifié à l'écran : ticket complet + boutons Cuisine/Client) |
| Ouvrir tiroir · écran client | 1 | 1 | PASS |
| Temps de préparation | 4 | 4 | non traité (écran Réglages = voie CENTRAL) |

### Défauts V4 traités
- **Carte tracker illisible** : « À ENCAISSER : » se cassait en 3 lignes avec deux-points orphelin
  et chevauchait le bouton Encaisser → pied de carte `flex-wrap` + libellé insécable. **Re-capture LUE : 3 rangées propres.**
- **« VAT (10.00 %) » sur tous les tickets clients** → racine DATA (`taxes.name='VAT'`, 78 items),
  fiscal-adjacent et hors voie → **handoff CENTRAL** (pas d'UPDATE silencieux sur un document client).
- **« Article Description »** sur 5 composants de ticket → « **Désignation** ».

### Défauts V4 non traités (hors voie S2, documentés)
Écran client OSS (état vide muet `—`, bandeau magenta hors palette Cayenne) → voie KDS/OSS.
Fiche commande incohérente (bloc « Informations de livraison » sur une commande à emporter,
`Extras: ,` orphelin, avatar cassé) → `PosOrderShowComponent`, backlog S2.
Stocks théoriques négatifs affichés (Oignon −15 g…) = D-5. Format de date natif US du champ
« Programmer ». Cartes d'encaissement non alignées.
