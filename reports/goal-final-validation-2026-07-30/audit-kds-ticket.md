# Audit KDS + Tickets + OSS — read-only adversarial

HEAD `9fba7b8f6` · 2026-07-30 · scope : `kitchenDisplaySystem/**`, `kdsSymbolic.js` + twin PHP `KitchenTicketSymbolicFormatter`, `orderStatusScreen/**`, `tools/kitchen-bridge`, `KdsSyncService`, `KitchenReleaseRule`, `OrderReceiptEscPosRenderer`.

## Verdict
**P0 = 0 · P1 = 0 · P2 = 2.** Système exceptionnellement durci (dizaines de correctifs d'audits antérieurs visibles). Parité ticket↔écran PROUVÉE end-to-end.

## 1. Parité PHP (`KitchenTicketSymbolicFormatter`) ↔ JS (`kdsSymbolic`) — VERT
Preuve directe (tinker PHP vs trace JS de `symbolicMainLine`/`buildSymbolic`), cas adverse **Tacos L** (2 viandes, sauce incluse + extra, viande extra @2,50, crudités libres, Cheddar payant, formule frites+boisson, sauce frites) :
- PHP `mainLine` = `G | TAC | P K | SO̲ | MAY AND` ; supps = `["+ Cheddar","+ Viande supplémentaire : Nuggets"]` ; menu = `MENU` ; frites = `ALG`. **Identique** à la trace JS attendue.
- Wording **caisse** (1ʳᵉ sauce gratuite) : `SAN | P | MAY AND HAR`, `extraSauceNames` slice(1) OK, edge « Sauce : … » en tête OK (lookbehind PHP ≡ alternation JS).
- Couvre : viandes espacées « K P », taille **tacos droppée**, sauce incluse+extra **fusionnées ligne 1** (« MAY AND », plus de ligne « + Sauce suppl. »), crudité cuite `O̲`, extra viande **nommé** ligne 2.
- Suites parité **vertes** : `tests/Unit/Hardware/KitchenTicketSymbolicFormatterTest` 17/17 ; vitest `kdsSymbolic*.spec` 41/41.
- `item_name` écran (`KDSOrderItemsResource.php:20` = `orderItem->name`) == `name` ticket (`OrderReceiptEscPosRenderer.php:307`) → produit/taille identiques. Tables MEAT/SAUCE/CRUDITE + `produitCode`/`isTacos`/`isDrink`/`menuLine`/`drinkLines`/`extraViandeNames` = miroirs stricts.

## 2. États / transitions — VERT
- Release paiement SSOT `KitchenReleaseRule::applyBoardReleaseFilter` partagé par **5 chemins** (KDS list/orderItems/sync + OSS list/listForBranch + guard changeStatus) → « visible ⟹ bumpable ».
- Programmée **T-20** : `applyScheduledBoardFilter` (`now-grace ≤ scheduled_at ≤ now+lead`, lead 20 min, grace 2 h anti-squat no-show) ; complément exact `applyScheduledUpcomingFilter` alimente `KdsScheduledBanner`. Board et bandeau = ensembles disjoints.
- **Annulée en prépa** : sort via `deleted_ids` (`KdsSyncService.php:51,167-169` inactiveStatuses CANCELED/REJECTED/DELIVERED) → refresh → liste active la purge.
- Ordre **FIFO** `KdsV2Grid.vue:183-199` (created asc, id tiebreak, garde NaN→Infinity). ACCEPT+PREPARING au grid (3 max plein écran + « +N en attente »), PREPARED→bande « servies » (cap 8 h). Chime ID-diff (`:1637-1651`) → **seulement** commande vraiment neuve.

## 3. Ticket cuisine — VERT
- `renderKitchenTicket` (`OrderReceiptEscPosRenderer.php:248`) : **AUCUN prix** ; `cleanInstruction` strip « (+2,50 €) » (regex `:649`) + blob compo + écho nom produit. Lisibilité : produit **double-taille** (`:358`), n° d'appel doublé width-safe, suppléments gras étoilés. Réimpression V2 (emit `reprint`) + legacy (bouton `:523`) + bridge résilient (timeout 15 s, drop-oldest, anti-doublon client-abort, réponse 200 imprimé / 500 réel).

## 4. OSS (mur client) — VERT
- `OrderStatusScreenComponent` = 2 colonnes EN PRÉPARATION / PRÊT (pas de « appelé » séparé — PRÊT = appel). Requête `OrderStatusScreenOrderService` **byte-identique** à KDS (release + scheduled + fenêtre glissante 8 h + advance sans plancher), FIFO `queue_number`, garde zombie (queue_number|token requis), cadence mur public 5 s. Cohérent avec KDS.

## 5. Son / mode dégradé — VERT
- KDS : throttle burst, autoplay-unlock (geste + haptique + bandeau « touchez pour activer »), volume persistant.
- OSS : chime gate mur public (branchId≤0 skip car inaudible), wakeLock TV, `_primed` anti-carillon-de-masse au reload, per-order timers trackés.
- Dégradé : bandeau « Mode secours » (prod, gate `appEnv!=='local'`), sync **self-heal** sur erreur réseau (reschedule, jamais board figé), 401/403 bruyant.

## Findings

### P2-1 — Légende symboles incomplète (onboarding)
`resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue:1389-1396` : groupe « Formule » ne liste que `MENU` et `F`, mais le code émet aussi `FRITES` et `BOISSON` (`kdsSymbolic.js:410-418` ; `KitchenTicketSymbolicFormatter.php:459-464`) pour les formules **partielles**. **Repro** : commande « frites seule » → écran + ticket affichent « FRITES » ; la légende (bouton 🔑) n'a pas d'entrée. Impact = onboarding seul (mots FR auto-explicites, prix scellé intact). Fix trivial (ajouter 2 entrées), non-frozen.

### P2-2 — Layout legacy `?v2=0` non-symbolique (observation, rollback-only)
`KitchenDisplaySystemComponent.vue:234-235,488-489` : les colonnes legacy rendent `item_variations` / `extra.name` **bruts** (pas la ligne symbolique, pas de pliage du nom d'extra dans la ligne « Extras »). Le nom de la sauce/viande en plus reste toutefois visible via la note « Sauces en plus : X » (non droppée par `sanitizeKdsInstruction`) → **aucune perte d'info**. Chemin de rollback d'urgence uniquement (V2 = défaut prod depuis 2026-05-16). Divergence cosmétique ticket↔écran-legacy, pas un bug fonctionnel.

**Aucun P0/P1. Rien à healer en bloquant.** Parité prouvée, states/transitions SSOT, ticket sans prix, OSS↔KDS cohérents, son + dégradé robustes.
