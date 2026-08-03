# Lentille ADVERSAIRE-RED — Sous-système CAISSE « Tiroir-caisse / cash / Z »

Round r1 · DB live `foodking_e2e` · READ-ONLY · 2026-06-26
Anchors: `CashDrawerService` openSession(:52)/closeSession(:126)/reconcileSession(:225)/recordMovement(:365),
`config/pos.php:37` simulation_hardware, `AppServiceProvider` boot-guard.

---

## VERDICT GLOBAL : le cœur tiroir/Z TIENT. 1 finding P1 (provisioning DB), 1 P3 (seuil by-design).

Toutes les défenses ancrées passent (tests + DB live) :
- `openSession` I1 (1 session OPEN / (branch,user)) : triple défense **vérifiée** — Cache::lock + DB lockForUpdate + **index UNIQUE partiel `uk_branch_user_open` + colonne générée `open_user_lock` présents en MySQL live** (SHOW INDEX confirmé). `CashDrawerConcurrentSessionTest` vert.
- `closeSession` I2 (refuse RECONCILED, idempotent sur CLOSED), close < 0 → 422 : OK.
- `reconcileSession` I3 + variance gate I6 : **prouvé sur données live** — session #30 `opening 70 + Σmvts 75,90 = expected 145,90`, `closing 10`, `variance -135,90` exact, `variance_reason` exigé + fourni + `reconciled_by_user_id=1`. Le gate a tenu sur un écart volontaire de -135,90 €.
- `recordMovement` I4/I5 : refuse session non-OPEN (lockForUpdate intra-txn), type/direction whitelisted, amount≥0. Mouvements CASH-only (`PaymentService:442 if mode===CASH`) — CARD/MOBILE n'enflent jamais le tiroir.
- Ownership (`CashDrawerSessionController::assertSessionVisibleToUser:317`) : owner-OR-manager, cross-branch 403. `CashDrawerSessionOwnershipTest` vert.
- `simulation_hardware` : boot-guard prod (`AppServiceProvider:172`) refuse `true` → `PosSimulationHardware4ScenariosTest` + `PosSimulationHardwareProductionGuardSentinelTest` verts (19/19).
- Z-close (`ZReportService::close:180`) **découplé du tiroir** + ne bloque PAS sur impayés mais `warnOnOrphanedPaidOrders:229`. Couple close/reconcile, idempotency middleware sur toutes les routes mutantes.

Vecteurs d'abuse RÉFUTÉS (tenure prouvée) ci-dessous en annexe.

---

## [P1] foodking_e2e (DB live) — Immutabilité NF525 cash/audit NON appliquée : 0 trigger DELETE alors que la migration est marquée run

repro:
```
mysql -u root foodking_e2e -e "SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA='foodking_e2e';"
# -> 0
mysql -u root foodking_e2e -e "SELECT migration,batch FROM migrations WHERE migration LIKE '%secure_fiscal%';"
# -> 2026_05_10_010000_secure_fiscal_audit_trail_immutability | 1  (marquée APPLIQUÉE)
mysql -u root foodking_e2e -e "SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE EVENT_OBJECT_TABLE IN ('cash_movements','cash_drawer_sessions','order_payments','audit_logs','z_reports');"
# -> (vide)
```
evidence: La migration `database/migrations/2026_05_10_010000_secure_fiscal_audit_trail_immutability.php:101-137`
crée en MySQL les triggers `BEFORE DELETE ... SIGNAL SQLSTATE '45000'` sur `cash_movements`,
`cash_drawer_sessions`, `order_payments` ; les migrations sœurs `2026_05_09_160000` (z_reports) et
`2026_04_22_000002` (audit_logs) idem. La ligne `migrations` les déclare run (batch 1) MAIS
`information_schema.TRIGGERS` = **0 trigger** sur toute la base. Le CODE est correct ; c'est la base
`foodking_e2e` qui a été provisionnée sans triggers (signature typique d'un restore `mysqldump` sans
`--triggers`/`--routines` ou d'un `schema:dump` qui omet les triggers, puis migration marquée-run sans
re-création). Conséquence concrète sur la base où l'équipe teste tout le fiscal : `cash_movements`,
`cash_drawer_sessions`, `order_payments`, `audit_logs`, `z_reports` sont **physiquement supprimables** —
l'invariant NF525 « cash-trail / chaîne append-only ineffaçable » NE TIENT PAS ici, et le registre de
migration prétend faussement le contraire. Un caissier fraudeur (ou tout chemin raw-delete / accès DB)
peut effacer la preuve d'encaissement cash et la rupture de chaîne est indétectable sur cette base.
Le sentinel `CashMovementsDeleteForbiddenTest` + `SqliteMysqlParitySentinel` tournent sur la base de
TEST (RefreshDatabase recrée les triggers) → ils restent verts et NE PEUVENT PAS capter ce drift de
provisioning sur une base op différente.
lentille: commerçant (preuve fiscale effaçable) / technique
reco: NON-frozen, hors-code. (1) Re-jouer l'install des triggers sur `foodking_e2e` :
`php artisan migrate:rollback --step` puis `migrate` ciblé, OU `DROP/recreate` via un `php artisan`
de réparation dédié, OU `migrate:fresh` sur une base MySQL propre (les triggers se créent alors).
(2) Ajouter une **commande de vérif runtime** `php artisan fiscal:verify-triggers` (lue par /brain ship)
qui SELECT information_schema.TRIGGERS et échoue si un des 5 triggers manque — un sentinel PHPUnit ne
suffit pas car il teste une base recréée, pas la base op. (3) Documenter dans le runbook deploy :
mysqldump DOIT inclure `--routines --triggers`. Caveat sévérité : le défaut est environnemental
(provisioning), pas un bug de code ; une prod issue d'un `migrate:fresh` propre aurait les triggers.
Marqué P1 car l'équipe valide activement des flux NF525 contre une base où l'immutabilité est
silencieusement absente et le registre ment.
frozen_touch: non

---

## [P3] CashDrawerService.php:276 — Seuil variance strict `> 2,00 €` : un écart EXACTEMENT au seuil ne déclenche NI raison NI approbation manager

repro:
```
# Logique: reconcileSession ligne 276  if (abs($variance) > $threshold)  — strict '>'
# threshold = config('cash.variance_threshold_eur', 2.00)  (config/cash.php:31)
# => |variance| == 2.00 € passe SANS variance_reason et SANS permission override.
# Preuve indirecte live: sessions #17/#18 reconciliées variance=2.00 (avec raison fournie volontairement,
# mais le gate ne l'aurait pas exigée).
mysql -u root foodking_e2e -e "SELECT id,variance,variance_reason FROM cash_drawer_sessions WHERE variance=2.00;"
```
evidence: `app/Services/Cash/CashDrawerService.php:276` `if (abs($variance) > $threshold)`. Au seuil exact
(2,00 €) le bloc gate (raison + permission `cash.reconcile.variance.override`) est sauté : reconcile OK
silencieux. Un caissier pourrait écrémer pile 2,00 €/clôture sans raison ni approbation, de façon
systématique. ATTÉNUATION FORTE : la valeur `variance` reste **persistée et auditée** (audit_logs payload
inclut `variance` + `over_threshold`), donc visible au rapport — non invisible, juste non-gardé. Le commentaire
`config/cash.php:25` documente explicitement 2,00 € comme « normal coin-counting rounding noise » (tolérance
métier assumée, le `>` est volontaire pour ne pas bloquer le bruit de comptage de pièces). `CashVarianceGateTest` vert.
lentille: commerçant
reco: by-design / tolérance NF525 documentée. Reco mineure si l'owner veut durcir : passer le test au seuil
exact en `>=` (CashDrawerService:276) bloquerait pile-au-seuil — MAIS c'est un changement de politique métier
(le bruit de pièces re-déclencherait le gate), donc **décision owner**, pas un fix auto. Statut informatif.
frozen_touch: non

---

## ANNEXE — Vecteurs d'abuse RÉFUTÉS (tenure prouvée, non reportés)

- **Fermer Z avec commande impayée / PENDING_COUNTER en file** → TENURE. `ZReportService::aggregate:337-341`
  filtre `whereNotNull('fiscal_sequence_no')` + `payment_status != UNPAID`. Live : 100 ordres
  payment_status=10 (UNPAID) status=1 + 42 (UNPAID,status=13) + 45 PENDING_COUNTER (status=4) ont TOUS
  `fiscal_sequence_no IS NULL` → jamais dans aucun Z ; ils reçoivent leur séquence à l'encaissement
  différé (`PaymentService::confirmCounterPayment`). Aucun ordre/argent perdu. `warnOnOrphanedPaidOrders:229`
  alerte sur les kiosk-payés sans seq. Correct NF525 (gap-free).
- **2 sessions OPEN / user** → TENURE. Triple défense ; index UNIQUE MySQL `uk_branch_user_open` (colonne
  générée `open_user_lock` = opened_by_user_id si status='open' sinon NULL) PRÉSENT en live (SHOW INDEX).
  NB live : 9 sessions OPEN simultanées sur branch 1 mais pour des **users distincts** (1,105,97,92,96,76,69,11,3) —
  c'est le modèle multi-caissier par-user, by-design (chaque cash IN va dans la session du caissier
  authentifié via `findOpenSessionForUser`), pas une violation de I1.
- **reconcile écart masqué** → TENURE. expected = opening + Σ(signedAmount) recalculé serveur à chaque
  reconcile (CashMovement::signedAmount), variance = closing - expected. Recompute live session #30
  exact (Σ=75,90). Le caissier ne fournit que `closing_amount` ; il ne peut pas masquer l'écart (le gate
  > seuil exige raison + manager).
- **closing_amount / opening_amount forgé** → TENURE partielle. close/open valident `min:0` ; un closing
  faux fait juste grossir la variance → gate. (Limite connue : `opening_amount` est auto-déclaré sans
  contre-vérification d'un second œil — mitigé par config `cash.manager_gate_routine_close` opt-in qui
  exige la permission manager sur TOUTE clôture ; défaut false pour mono-caissier Le Cayenne = choix V1.)
- **simulation_hardware=true en prod** → TENURE. Boot-guard `AppServiceProvider:172` lève RuntimeException.
  Live `.env` APP_ENV=local + POS_SIMULATION_HARDWARE=true → c'est pourquoi 2209/2546 ordres cash payés
  n'ont pas de cash_movement (mouvement skip best-effort, `cash_movement_skipped`). **Artefact de
  simulation, PAS un bug** : en prod (simulation off) le chemin strict lève `CashDrawerSessionNotOpenException`
  et force l'ouverture du tiroir. Ne PAS reporter (verify-before-report).
- **recordMovement sur session CLOSED (corruption Z post-clôture)** → TENURE. `recordMovement:437`
  rejette toute session status != OPEN sous lockForUpdate intra-transaction (bloque aussi un close
  concurrent entre check et INSERT).
- **reconcile/close par un autre caissier (mis-attribution NF525)** → TENURE. `assertSessionVisibleToUser:317`
  exige owner OU manager (POS-RED-04), cross-branch 403. Tous les callers passent par le contrôleur.
- **double-clic close/reconcile** → TENURE. Routes `close`/`reconcile` ont `middleware('idempotency')`
  (api.php:933/937) + close idempotent (no-op si déjà CLOSED) + reconcile idempotent (no-op si RECONCILED).

Evidence tests (base de test, safe) :
`CashDrawerServiceTest|CashVarianceGateTest|CashDrawerConcurrentSessionTest` = 30/30 ;
`PosSimulationHardware4ScenariosTest|...ProductionGuardSentinel|CashDrawerSessionOwnershipTest|PosCashTrailTest` = 19/19.
