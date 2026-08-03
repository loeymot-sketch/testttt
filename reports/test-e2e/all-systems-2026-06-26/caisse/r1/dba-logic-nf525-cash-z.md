# CAISSE r1 — Lentille LOGIQUE/DATA/NF525 · Tiroir-caisse / cash / Z

Rôle: DBA/logique/NF525 sur le sous-système CAISSE (CashDrawerService, Z, cash trail).
DB live interrogée: `foodking_e2e` (read-only). Tests baseline relancés (verts).
Méthode: Read ancres + SELECT preuves réelles + filtres PHPUnit existants.

## VERDICT GLOBAL
Le cœur cash/Z est SOLIDE. Invariants tenus prouvés par la donnée + tests. 2 findings
mineurs (P2/P3) sur l'observabilité fiscale ; AUCUN P0/P1 (aucune perte d'argent
reproductible via le code caisse, aucune corruption de Z signé).

---

## [P2] app/Models/Order.php:17 — Gap de séquence fiscale réel (2506-2508) + absence de garde hard-delete + aucun vérificateur de contiguïté order-level
  repro:
    mysql -u root foodking_e2e -e "SELECT n.seq FROM (SELECT @r:=@r+1 seq FROM orders,(SELECT @r:=0)x LIMIT 2573) n LEFT JOIN orders o ON o.fiscal_sequence_no=n.seq AND o.branch_id=1 WHERE o.id IS NULL AND n.seq BETWEEN 1 AND 2573;"
    → renvoie 2506, 2507, 2508 (3 numéros absents)
    mysql -u root foodking_e2e -e "SELECT id FROM orders WHERE fiscal_sequence_no IN (2506,2507,2508);"  → 0 ligne (ni actif ni soft-deleted)
    Voisins: seq 2505 (order 4974) puis 2509 (order 5019) — saut net de 3.
  evidence:
    - seq_cnt=2570, span=(MAX 2573 - MIN 1 +1)=2573 → 3 manquants sur branche 1.
    - FiscalSequenceService::next() (l.97-101) calcule MAX(fiscal_sequence_no)+1 sur
      withTrashed() → un soft-delete NE crée PAS de gap (la ligne reste, MAX la voit) ;
      un rollback NE crée PAS de gap (la ligne n'est jamais committée, le n° est réutilisé).
      Donc un gap = HARD-DELETE (SQL brut / forceDelete) d'ordres ayant alloué 2506-2508.
    - app/Models/Order.php utilise SoftDeletes (l.17) mais N'A AUCUN guard
      `static::forceDeleting`/`static::deleting` bloquant la suppression dure d'une
      ligne avec fiscal_sequence_no non-null. SHOW TRIGGERS LIKE 'orders' = vide
      (aucun BEFORE DELETE SIGNAL, contrairement à audit_logs/z_reports).
    - Aucun test/command ne vérifie la contiguïté order-level sur données réelles :
      OrderFiscalSequenceSchemaTest = UNIQUE seulement (l.33, "no-gap testé dans
      FiscalSequenceTest" = test du comportement next(), pas de la donnée réelle) ;
      fiscal:verify-chain walks audit_logs + z_reports HMAC (sequence_gap = n° de Z,
      pas n° d'ordre, cmd l.144) ; VerifyZMembershipCommand détecte les orders
      fiscalisés hors-Z mais PAS les n° MANQUANTS (un ordre supprimé ne laisse pas
      de ligne à détecter). → le gap 2506-2508 est INVISIBLE de toute la tooling NF525.
  lentille: technique / commerçant (NF525)
  reco:
    - NON-frozen: ajouter `static::forceDeleting(fn(Order $o)=> throw ...)` sur le
      modèle Order quand fiscal_sequence_no!=null (interdit la suppression dure d'une
      ligne fiscalement numérotée — NF525 6 ans rétention) + migration trigger
      `orders BEFORE DELETE SIGNAL SQLSTATE '45000'` sur fiscal_sequence_no IS NOT NULL
      (miroir audit_logs/z_reports), gated owner car touche la table orders.
    - NON-frozen: une commande `fiscal:verify-sequence-gaps --branch` (read-only) qui
      flag les trous de fiscal_sequence_no sur données réelles, branchée au deploy-gate.
  SÉVÉRITÉ = P2 (pas P1) car: (a) AUCUN chemin de code du sous-système caisse
  (Controllers/Admin/Pos, CashDrawerService, PaymentService, Services/Pos) ne fait
  forceDelete/->delete() d'un Order — `grep -rn "forceDelete|->delete()" app/Http/Controllers/Admin/Pos app/Services/Cash app/Services/PaymentService.php` = vide → le gap n'est PAS reproductible via la caisse (origine = pollution e2e/tinker du 2026-06-19/20) ;
  (b) le gap n'a PAS corrompu de Z signé : les z_reports de cette fenêtre (seq 21-23,
  closed 2026-06-19) ont order_count=0/total_ttc=0. Latent risk, pas d'impact fiscal actuel.

---

## [P3] app/Http/Controllers/Admin/Fiscal/ZReportController.php:53 — Clôture Z sans alerte sur commandes PENDING_COUNTER encore en file (argent non encaissé)
  repro:
    mysql -u root foodking_e2e -e "SELECT COUNT(*),ROUND(SUM(total),2) FROM orders WHERE payment_status=15 AND fiscal_sequence_no IS NULL AND deleted_at IS NULL;"
    → 91 commandes / 386,80 € en attente d'encaissement comptoir (PENDING_COUNTER, jamais collectées)
    POST /api/admin/fiscal/z-report/close → ZReportController::close() → ZReportService::close() ferme sans signaler ces 91 commandes.
  evidence:
    - ZReportController::close (l.53-61) appelle directement service->close() ; ne lit
      ni ne retourne aucun compteur PENDING_COUNTER.
    - ZReportService::warnOnOrphanedPaidOrders (l.611-641) ne couvre QUE
      payment_status=PAID && fiscal_sequence_no IS NULL (kiosk-paid orphelin), PAS
      PENDING_COUNTER (payment_status=15). Et c'est un Log::channel('fiscal')->warning
      best-effort, jamais surfacé dans la réponse HTTP au commerçant.
    - aggregate() filtre `payment_status != UNPAID` + `fiscal_sequence_no IS NOT NULL`
      (l.340-341) → les PENDING_COUNTER sont correctement EXCLUS du Z (aucun argent
      encaissé, aucun n° fiscal alloué — NF525-correct).
  lentille: commerçant
  reco: NON-frozen — faire renvoyer par close() (ou un pre-check /z-report/preview)
    un compteur `{pending_counter_count, pending_counter_total}` pour que l'UI affiche
    "91 commandes (386,80 €) en attente d'encaissement avant la clôture du jour ?"
    (nudge opérateur). Aucun impact fiscal — pure observabilité fin-de-journée.
  SÉVÉRITÉ = P3: fiscalement correct (les PENDING_COUNTER n'ont rien encaissé), V1
  mono-poste ; simple confort opérateur.

---

## INVARIANTS ABUSÉS — TENUE PROUVÉE (aucun finding)

- [HOLD] I1 double-ouverture/user — `CashDrawerService::openSession:52` triple défense
  (Cache::lock + lockForUpdate + UNIQUE partiel). Donnée réelle: aucun (branch,user)
  avec >1 session OPEN (GROUP BY ... HAVING open_cnt>1 = vide), malgré 8 sessions OPEN
  simultanées (utilisateurs DISTINCTS 1/11/69/76/92/96/97/105 — autorisé).
- [HOLD] reconcile écart masqué — `reconcileSession:225` calcule expected=opening+Σ(signed
  movements), variance=closing-expected ; gate |variance|>seuil exige reason+permission.
  Donnée réelle: session 30 reconciled opening 70+mvts 75,90=expected 145,90 vs closing 10
  → variance -135,90 AVEC reason capturée (le gate a tenu sur un écart abusif e2e).
  Aucune session reconciled avec |variance|>2€ ET reason vide (requête = vide).
- [HOLD] closing_amount forgé / re-close — `closeSession:177-179` : appel répété sur
  session CLOSED = no-op qui NE réécrit PAS closing_amount → impossible d'écraser le
  montant compté par un 2e close. reconcile d'une session OPEN refusé 422 (l.243).
- [HOLD] simulation_hardware=true en prod — boot-guard AppServiceProvider:168-178
  `throw RuntimeException` si config('pos.simulation_hardware')===true en production.
  PosSimulationHardware4ScenariosTest + CashDrawerSessionOwnershipTest = 9/9 verts.
- [HOLD] ownership session — `CashDrawerSessionController::assertSessionVisibleToUser:317`
  gate cross-branch 403 + same-branch owner-or-manager (POS-RED-04). 9/9 verts.
- [HOLD] drain tiroir via cashback — `recordCashBackMovement` (PaymentService:582) borné
  à order->total, gated par prior payment existant (cashBack l.132 return si pas de
  paiement préalable) + AuditLogService HMAC (l.155). Pas de drain arbitraire.
- [HOLD] cash-trail best-effort — counter-collect CASH appelle recordCashOrderMovement
  strict=false (l.443) : si pas de session OPEN, l'ordre reste PAID+seq mais flag
  `cash_movement_skipped=true` (OrderDetailsResource + PosCounterCollectModal.vue) ET
  détecteur read-side TRAP-3 (CashOverviewController:285) liste les cash sans movement.
  → divergence Z(total_by_method.cash, from orders.total) vs reconcile(from
  cash_movements) est OBSERVABLE par le commerçant, pas silencieuse. Donnée: 2001
  orders CASH (32966€) sans cash_movement = pollution e2e/tinker (source=15, créés
  hors flux HTTP cashier), pas un défaut de logique → non reporté comme P1.
- [HOLD] reconcile cross-check sur Z — ZReportCashEnrichmentService (décorateur) calcule
  cash_variance = Σ variance des sessions reconciled dont closed_at ∈ (from,to] → le Z
  expose bien l'écart tiroir.
- [HOLD] recordMovement sur session fermée — `recordMovement:365` exige STATUS_OPEN sous
  lockForUpdate (l.437) → impossible d'écrire un mouvement après close (corromprait le Z).

Tests relancés verts: PosSimulationHardware4ScenariosTest+CashDrawerSessionOwnershipTest
(9/9), PosCashTrailTest (6/6). Audit chain head intact (audit_logs 4635 rows, max_id 4639).
