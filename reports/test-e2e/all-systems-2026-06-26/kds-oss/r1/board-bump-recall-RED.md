# KDS Board + bump/recall + transitions — lentille ADVERSAIRE-RED (r1)

Sous-système : `KitchenDisplaySystemOrderService::list / changeStatus / recall / historyToday`
+ `KitchenReleaseRule` + controller + routes/throttle/idempotency.
DB live : `foodking_e2e` (READ-ONLY). Tous file:line vérifiés. Aucun fichier frozen touché.

## VERDICT
Cœur SOLIDE. Tests Board/bump/recall/transitions tous VERTS (12 fichiers).
Invariants release-guard + optimistic-lock + recall append-only confirmés en code ET en données live.
**1 P2** (pollution board zombie-advance, reproduite live) + **3 P3**. Plusieurs germes adversaires RÉFUTÉS par preuve.

---

## FINDINGS

[P2] app/Services/KitchenDisplaySystemOrderService.php:133-137 — Board pollué par advance-orders zombies (sans borne basse ni exclusion PREPARED)
  repro: `mysql -u root foodking_e2e` — predicat list() exact (status IN 4,7,8 + applyBoardReleaseFilter + clause advance) → **21 lignes** retournées pour un chef branche-1 AUJOURD'HUI, **toutes** des advance PREPARED(8) vieilles de 7-12 jours (oldest 2026-06-14 01:58), **0** commande placée aujourd'hui.
  evidence: requête SQL `SELECT COUNT(*) … total_board_rows=21, advance_rows=21, zombie_advance_older_than_today=21, oldest_on_board=2026-06-14`. Branche-1 only = 21/21.
  lentille: cuisinier
  reco: la clause advance (`is_advance_order=YES AND order_datetime < tomorrow AND status NOT IN [DELIVERED,CANCELED]`) n'a NI borne basse NI exclusion de PREPARED(8). Une commande advance déjà cuite (PREPARED) jamais marquée OUT/DELIVERED ré-apparaît indéfiniment. Fix scope-minimal NON-frozen : exclure PREPARED de la branche overdue (un plat fini ne doit pas re-remonter « à préparer »), ou ajouter une borne basse (overdue ≤ N jours). NB: les 21 lignes live sont de la test-pollution (serials `1406264908-29` burst seeder + `ZWINTEST-/ZWIN2-`), donc en prod-data-propre l'occurrence est moindre — mais le défaut structurel est réel et le germe « zombie advance » est confirmé.

[P3] database orders.is_advance_order DEFAULT=5 (Ask::YES) — piège : un INSERT omettant la colonne crée une advance permanente
  repro: `SHOW COLUMNS FROM orders LIKE 'is_advance_order'` → `tinyint NOT NULL DEFAULT 5`. Ask::YES=5, Ask::NO=10.
  evidence: distribution live `is_advance_order`: 10→2654, 5→158, 0→11, 2→4, 1→17. L'app FIXE bien la valeur (majorité NO), donc **pas un défaut live**.
  lentille: technique
  reco: aucun chemin actuel n'insère sans setter la colonne (vérifié par la distribution). Mais le default=YES est dangereux : tout futur code-path créant un Order sans `is_advance_order` explicite produirait un zombie-board (couplé au P2). Aligner le default DB sur Ask::NO (10) en V1.0.X. Verified-clean comme défaut live ; noté comme trap.

[P3] app/Services/KitchenDisplaySystemOrderService.php:315-322 — fenêtre d'ÉLIGIBILITÉ recall ré-armée par une écriture non-liée (updated_at)
  repro: lecture code — `$bumpedAt = $locked->updated_at`; recall refusé si `bumpedAt < now-60s`. Toute écriture (ex. confirmation paiement, edit admin) qui touche `updated_at` SANS changer le status ré-ouvre la fenêtre 60s longtemps après le vrai bump.
  evidence: cohérent avec le commentaire du test `KdsRecallCapNTest` (#13) qui a déjà corrigé le côté DEDUP (sliding-window now-60s) mais PAS le côté éligibilité.
  lentille: technique
  reco: bénin — recall reste append-only, status JAMAIS muté (prouvé live : 12 recalls, 0 violation from/to≠PREPARED ; 0 cap-N breach), OSS non-dégradé. Au pire un chef peut « annuler bump » tardivement après une écriture parasite. Si on veut serrer : ancrer l'éligibilité sur le dernier `kitchen_recall`/transition de bump réel plutôt que `updated_at`. Différable V1.0.X.

[P3] resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue:116,2190-2194 — bouton « Voir plus » (cap-50) ne fait que réinitialiser les filtres
  repro: `kdsOverflowSeeMore()` = clear `order_serial_no` + `status` puis `list()` (toujours cappé 50, pas de pagination au-delà).
  evidence: lecture méthode :2190.
  lentille: cuisinier
  reco: imprécision mineure d'affordance. Le bannière FR `kds_order_list_full_warning` porte déjà la vraie consigne (« filtrez par statut ou recherchez un numéro »), donc le modèle mental reste correct. Renommer le libellé ou retirer le bouton en V1.0.X. Pas bloquant.

---

## GERMES ADVERSAIRES RÉFUTÉS (verified-clean — NON reportés comme défauts)

- **51ᵉ commande tronquée invisible** : RÉFUTÉ. `list():172` limit(51)→take(50), `lastListOverflow` exposé en `meta.overflow` (Controller:33). Front consomme `meta.overflow` → `kdsOverflowDetected` (:2074/:2112) → bannière danger legacy (`v-if=kdsOrderListAtCap` :111, libellé FR résolu) ET prop V2 (`:list-at-cap` :39) + warning à 45 (:104). `KdsPaginationOverflowTest` VERT (assert 50 cards + meta.overflow=true + meta.limit=50).

- **Bump commande NON-PAYÉE → notif client avant paiement** : RÉFUTÉ. `changeStatus:447` `orderIsReleasedForBoard` (miroir SQL `applyBoardReleaseFilter`). Admet PAID(5) | PENDING_COUNTER(15) | POS+CASH. Live : 3 commandes UNPAID(10) en board-states (serials `QA-/WV2C/WVALKDS-DELUNP-` = fixtures négatives) → exclues du filtre. `KdsUnreleasedOrderBumpTest` (HTTP 422, status inchangé) + `…P1Test` (5 cas) VERTS.

- **2 stations bump simultané → 409** : RÉFUTÉ. `expected_status` requis (FormRequest Rule::in), lock optimiste `fromLocked!==expectedFrom → abort(409)` (:411-423). `KdsExpectedStatusConflictTest` (3 cas : 409 stale, expected_status requis, no-op ACCEPT→ACCEPT sans event) + `KdsChangeStatusConcurrencyTest` VERTS.

- **Recall spam > cap / fenêtre 60s expirée** : RÉFUTÉ. Cap N=1 dedup sliding-window (now-60s, :331-339 → 409), TTL 60s (:320 → 422), throttle:kds-bump (`config kds.rate_limit_bump`=120/min/user). Idempotency ne cache QUE 2xx (`IdempotencyKeyMiddleware:145 >=200 && <300`) → un 409/422 recall n'est PAS mis en cache (retry légitime possible). Live : 12 recalls, 0 doublon <60s. `KdsRecallCapNTest` + `KitchenRecallEndpointSentinelTest` (8 cas) VERTS.

- **Recall mute le status / casse l'append-only NF525** : RÉFUTÉ. `orders.status` JAMAIS muté (assertion in-txn :360-363). Live : 12 `kitchen_recall`, **0** avec from≠PREPARED ou to≠PREPARED. Les 6 orders recall+status=13(DELIVERED) = progression NORMALE post-fenêtre (re-bump légitime), PAS une mutation-pendant-recall.

- **Zombie advance d'hier disparaît silencieusement** : l'inverse est vrai (cf. P2) — ils NE disparaissent pas, ils s'accumulent.

## EVIDENCE TESTS (tous VERTS, phpunit individuel)
KdsUnreleasedOrderBumpTest 1/2 · …P1Test 5/10 · KdsExpectedStatusConflictTest 3/12 · KdsTransitionWhitelistTest 1/2 · KdsChangeStatusConcurrencyTest 1/2 · KdsPaginationOverflowTest 2/9 · KdsRecallCapNTest 1/4 · KitchenRecallEndpointSentinelTest 8/26 · KDSFlowTest 3/3 · KDSScopeRestrictionTest 1/1 · KdsBranchFilterExactTest 1/1.
