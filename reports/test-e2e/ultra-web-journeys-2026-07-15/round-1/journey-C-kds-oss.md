# Journey C — KDS + OSS (round 1, 2026-07-15)

Statut parcours : **DEFECTS** (parcours complet exécuté de bout en bout ; 5 défauts réels, 1 P1).

## Étapes réellement exécutées (curl, serveur live :8000)

| # | Étape | Preuve |
|---|-------|--------|
| 1 | POST `/api/admin/pos/quote` puis POST `/api/admin/pos` (cash) | Commande **5691** créée — `RJ-C-kds`, 2× Tarte Daim, total 7,00 €, `fiscal_sequence_no=2646`, status=7 PREPARING, payé (5), `queue_number=A0035` |
| 2 | GET `/api/admin/kds-order` | 5691 présent (25 commandes, dont **22 fantômes de juin** — voir F2) |
| 3 | GET `/api/admin/kds-order/sync?since=…` | 5691 présent dans `orders`, `deleted_ids=[]` |
| 4 | POST `/api/admin/kds-order/change-status/5691` `{status:8,expected_status:7}` | 1er appel → 202 vide, DB status=8 ; rejeu avec clé différente → **409** « updated elsewhere » (optimistic lock OK) |
| 5 | POST `/api/admin/kds-order/recall/5691` | 200 `{"status":true,"transition_id":6647,…,"queue_number":0}` — **queue_number=0 au lieu de A0035** (F4) ; statut DB reste 8 (invariant NF525 OK) |
| 6 | GET `/api/admin/oss-order` | Retourne UNIQUEMENT 5689 (kiosk) — **5691 (PREPARED, ticket client A0035) jamais affiché au mur client** (F3) |
| 7 | GET `/api/admin/pos/orders/5691/escpos?ticket=kitchen` en **Chef** | **200** + octets ESC/POS (heal précédent tient) |
| 8 | GET `/api/admin/kds-order/history-today` | `[5691]` — cohérent |
| 9 | 2e commande **5709** (Bol Frites + Mexicanos + Sauce spicy, 7,90 €, fiscal 2653, A0049) + décodage ticket cuisine | Ticket imprime `BOL | Mex | SPI` — **base Frites/Riz perdue** (F1) |

## Findings

### F1 (P1) — Ticket cuisine + KDS : « Bol Frites » et « Bol Riz » rendent le MÊME symbole « BOL » → le cuisinier ne sait pas quelle base préparer
- `app/Services/Hardware/KitchenTicketSymbolicFormatter.php:506` (`produitCode` = 1er mot significatif ; 'bol' n'est pas générique → « Bol Frites »→BOL, « Bol Riz »→BOL) ; jumeau écran `resources/js/helpers/kdsSymbolic.js:172`.
- Repro EXÉCUTÉE : commande 5709 (item 41 Bol Frites) → `GET /orders/5709/escpos?ticket=kitchen` → octets décodés : `CUISINE / A0049 / *** SUR PLACE *** / BOL | Mex |\nSPI`. Les items live 41 « Bol Frites » et 45 « Bol Riz » n'ont AUCUNE variation « base » (attrs 1=viande, 8=sauce) — le nom est le seul porteur de la base, et il est tronqué. Tinker : `mainLine('Bol Frites',[])=='BOL'` ET `mainLine('Bol Riz',[])=='BOL'`.
- Impact : plat faux préparé (riz au lieu de frites), sur ticket imprimé ET écran KDS (parité stricte).
- Fix : dans `produitCode` (PHP + JS jumeau), si le 1er mot significatif est « bol », concaténer le 2e mot (→ `BOL FRI` / `BOL RIZ`).

### F2 (P2) — Le board KDS actif affiche à VIE les commandes « advance » PREPARED (22 fantômes du 14 juin – 2 juillet) et elles consomment le cap de 50
- `app/Services/KitchenDisplaySystemOrderService.php:146-150` : branche advance-overdue exclut seulement DELIVERED/CANCELED — or l'état terminal cuisine est **PREPARED (8)**, qui reste visible (`KitchenReleaseRule::visibleStatuses()`), sans borne basse de date. Cap 51/50 ligne 201-205, tri défaut id ASC → les fantômes occupent les premiers slots.
- Repro EXÉCUTÉE : `GET /api/admin/kds-order` → ids 4908…4995 (s=8, `is_advance_order=5(YES)`, `order_datetime` 2026-06-14/19) présents aujourd'hui. DB : 81 commandes advance overdue non DELIVERED/CANCELED.
- Impact : mur de tickets morts en cuisine ; en rush >28 vraies commandes, débordement du cap 50 (les plus récentes disparaissent, flag overflow ou pas).
- Fix : dans la branche advance (list + KdsSyncService + OSS, parité 4 chemins), exclure aussi PREPARED plus vieux que la fenêtre `oss.stale_window_hours`, ou borner `order_datetime >= today-Nj`.

### F3 (P2) — Incohérence cross-surface : le reçu client caisse imprime le n° d'appel « A0035 » en double taille, mais le mur OSS exclut TOUTES les commandes POS → le numéro n'apparaît jamais
- Reçu : `app/Services/Hardware/OrderReceiptEscPosRenderer.php:94` (`$ticketNo = queue_number` imprimé doubleSize) ; exclusion : `app/Services/OrderStatusScreenOrderService.php:59-62` (allowlist `[KIOSK, TAKEAWAY]`, sentinel-locked RED R-3) ; idem `publicIndex` l.227.
- Repro EXÉCUTÉE : 5691 (POS, A0035, PREPARED) → `GET /api/admin/oss-order` = `[5689]` seulement. Le client caisse qui attend « A0035 » ne le verra jamais ni en préparation ni prêt.
- Impact : file d'attente comptoir aveugle pour les clients caisse (V1 Le Cayenne : la caisse est la surface principale).
- Fix (gate owner car sentinel `OssCustomerScreenFilterTest`) : admettre au mur les commandes POS **avec queue_number non nul** (le risque token-leak d'origine visait `token`, pas `queue_number`), ou ne plus imprimer le n° d'appel sur le reçu POS.

### F4 (P3) — `recall` renvoie/broadcast `queue_number:0` (cast int d'un « A0035 »)
- `app/Services/KitchenDisplaySystemOrderService.php:397` : `(int) $locked->queue_number` — les queue numbers sont alphanumériques (« A0035 ») → 0. Propagé à l'event `KdsOrderRecalled` (l.408) et à la réponse HTTP.
- Repro EXÉCUTÉE : POST recall/5691 → `{"queue_number":0}` alors que la commande porte A0035.
- Impact : faible aujourd'hui (le handler Vue fait `payload?.queue_number || null`, KitchenDisplaySystemComponent.vue:2256), mais tout consommateur futur du broadcast reçoit un n° faux.
- Fix : renvoyer la string brute (`$locked->queue_number`), pas de cast int.

### F5 (P3) — Contrat store↔quote incohérent sur item soft-deleted : le store demande de « Sélectionner 1 Base bol » pour un article qui n'existe plus
- Validation store (`MultiVariationConstraint` via `PosOrderRequest`) lit les variations d'un item **soft-deleted** et répond 422 « Sélectionnez au moins 1 Base bol (actuel : 0) » ; le quote/pricing (`PricingService.php:130`, `Item::query()` sous SoftDeletingScope) répond « Article 30 introuvable ».
- Repro EXÉCUTÉE : POST `/api/admin/pos` item_id=30 (deleted_at=2026-05-28) → 422 « Base bol » ; POST `/api/admin/pos/quote` même payload → 422 « Article 30 introuvable ». Deux messages contradictoires pour le même payload.
- Impact : edge (nécessite un id supprimé, ex. app cliente stale) ; message trompeur au lieu du vrai motif.
- Fix : `MultiVariationConstraint` doit court-circuiter (introuvable) quand l'item est trashed.

## Vérifié sain
- Création → KDS list + sync : présence immédiate, payload complet (composition_snapshot scellé).
- Bump optimistic-lock (`expected_status`) : rejeu → 409 propre, pas de double transition.
- Recall : fenêtre 60 s, statut DB inchangé (PREPARED), transition append-only 6647, invariant testé.
- Chef → `escpos?ticket=kitchen` : 200 (heal RBAC précédent tient).
- `history-today` : contient exactement la commande bumpée aujourd'hui.
- Aucun 4xx silencieux rencontré sur le parcours nominal.
