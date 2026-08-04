# Audit LOGIQUE synchro — 5 agents adversariaux « test-e2e logique » (2026-08-05)

**Mandat owner** : « go deeper and test-e2e logique agents ». 5 agents de raisonnement adversarial, chacun jouant une SÉQUENCE cross-surface réelle et raisonnant sur la cohérence d'ÉTAT (pas « le broadcast part-il »), preuves par tests DB-safe (`vendor/bin/phpunit`, sqlite :memory:) + traçage code + serveur :8000 live.

## Verdict global
**Cœur money/fiscal/statut/board/ordering : SAIN.** 0 P0. **1 P1 réel trouvé + healé** (prouvé par repro). Le reste = P2/P3 (observabilité de dégradation, latence polling, décisions owner). **Insight majeur** : plusieurs « divergences » sur-évaluées par le premier audit-cartographie sont en fait rattrapées par le polling OU fail-closed — les agents logique les ont RÉFUTÉES en traçant les consommateurs + en exécutant des tests.

## Healés cette passe (3 commits)

| # | Sévérité | Commit | Fix |
|---|----------|--------|-----|
| 1 | **P1** | `4de1f5713` | **Plancher zombie advance-order sur les 5 chemins du board.** Le plancher F-02 (48h, évince les précommandes zombies) était câblé dans KDS::list() SEULEMENT ; les 4 jumelles (OSS list/listForBranch, KDS orderItems, KdsSync sync) admettaient encore le zombie → un zombie de 9j QUITTAIT le board cuisinier mais RESTAIT sur le mur client OSS = **divergence PERMANENTE** (prouvé DB-safe : KDS=[] vs OSS=[zombie]). Plancher mirroré aux 4 jumelles. TDD : test all-paths RED→GREEN. |
| 2 | P3 (ma régression) | `84df49da8` | **Exclusion `contract_violation` restaurée dans le monitor crash-claimed.** Mon rekeying broadcast_at (SYNC-P2-1) avait laissé tomber l'exclusion CV que TOUS les siblings portent → un CV historique (que mon backfill laisse à broadcast_at NULL) paginait **chaque minute pour toujours** post-migrate = fatigue d'alerte. Repro L4 : `monitorExit=1 rescued=NO survivesPrune=YES`. |
| 3 | correction | `01499f907` | **Honnêteté : ma garde cross-surface (077883237) est DEAD CODE en prod.** Elle vit dans la branche legacy `use_ssot_service=false` (verrouillée OFF). La vraie garde extras/variations 86 en prod = `ChoiceAvailabilityResolver::assertSelectionsOrderable` (chemin SSOT). +1 test prouvant le VRAI chemin ; docblocks marqués legacy-only. Ferme le « test vert qui encode un no-op ». |

## Findings documentés (NON healés — owner-gate / décision métier / mitigé)

**[P2 · L1] Gateway refund (Mollie/Stripe) orpheline la commande sur la caisse.** `PersistOrderPaymentStatusChangedOnRefundCreated:102` pose `payment_status=REFUNDED` sans toucher `status`. `KitchenReleaseRule::applyBoardReleaseFilter:130-140` exclut REFUNDED → la carte QUITTE KDS+OSS, mais le tracker POS bucketise par `status` (PREPARING) → **la montre encore « en préparation », un-bumpable (changeStatus release-guard 422), orpheline**. Le refund COMPTOIR garde PAID (voulu) — c'est spécifiquement le déclencheur GATEWAY. **Fix reco (décision owner sur le statut cible)** : aligner le chemin gateway-refund sur le chemin manuel (changeStatus→RETURNED qui, LUI, transitionne le statut) OU appliquer le board-release au tracker POS. §10 : quel statut pour une commande PREPARING remboursée par la banque ?

**[P2 · L1] `KdsOrderRecalled` push-only + admin(branch 0) jamais abonné.** Le rappel (ré-injecter un ticket bumpé 60s) vit PUREMENT en mémoire client via le push WS ; le poll ne le reconstruit pas (`status` reste PREPARED). Une station qui rate le broadcast (WS drop, reload) ne ré-injecte jamais ; et `subscribeEcho()` sort tôt pour `branchId<=0` → un **KDS admin(branch 0) ne reçoit AUCUN push = ne peut JAMAIS afficher un rappel** (angle mort permanent, pas latence). TTL 60s borne le cas cross-station. Reco : marqueur de rappel lisible au poll + abonnement admin.

**[P2 · L3] Web (UI) aveugle au 86 extra/variation — parité borne/web.** Le web déployé (`lecayenne-web-deploy/api.js:803-839`) ne poll que l'item-level ; il montre un extra 86 comme sélectionnable → le client va au checkout puis prend un **422 « Le supplément n'est plus disponible »** (fail-closed AVANT paiement, donc pas de fuite money). Borne le grise, web non. **Owner-gate G3** (repo web séparé). Reco : lire la dispo imbriquée depuis `/details/{item}` (qui la porte déjà) ou switcher le poll vers la projection menu.

**[P2 · L4] soketi split-brain (SO_REUSEPORT) invisible aux dashboards.** 2 instances :6001 → le broadcast HTTP touche UNE instance, `broadcast_at`+heartbeat frais → tous les health VERTS, mais les clients de l'autre instance ne reçoivent RIEN. Aucune garde CODE (ops). Blast radius max ; rattrapé par le poll plein-état (POS 8s, KDS adaptatif) SAUF surfaces client/kiosk non confirmées. **Owner-gate G2** (config soketi single-instance + runbook).

**[P3 · L4] Monitor crash-claimed sans plafond attempts/24h floor.** Un orphelin poison genuine (attempts≥20, rescue abandonne à 20 — pas de spin, prouvé) pagine ~90j jusqu'au prune. L'exclusion CV (heal #2) couvre le cas fréquent ; le poison-pur reste. Advisory (pas de perte data). Reco : router les crash-claimed attempts≥20 vers la dimension dead-letter (action manuelle) au lieu de « worker-down ».

**[P3 · L4] `PosSystemHealthController::staleOutboxCount` aveugle aux crash-claimed** (clé `dispatched_at IS NULL` seul) → pastille verte alors qu'un event est non-livré jusqu'à 10 min. La commande atteint quand même le caissier via le poll plein-état du tracker ; seul le SIGNAL sous-rapporte.

**[P3 · L2/L4] `OrderPaymentStatusChanged` sans abonné client.** Diffusé (outbox) mais absent de `BROADCAST_MAP` → un flip paiement PUR (refund sans changement de statut, PENDING_COUNTER→REFUNDED) est stale jusqu'au poll (20-60s). Latence, PAS divergence (le refund via RETURNED, lui, diffuse OrderStatusChanged abonné → converge). Reco (T-1.1.1) : 1 ligne dans BROADCAST_MAP + handler re-fetch.

**[P3 · L3] B8 : MenuSnapshot ne bump pas au 86 extra/variation.** Mitigé (CatalogChanged + flush cache kiosk firent quand même) ; résiduel = surface qui DROP le WS pendant le 86 puis reconnecte et fait confiance au version-diff. Worst case fail-closed (422). Reco (T-1.3.4) : ajouter les 2 events au listener de bump.

**[P3 · L5] OSS `list()` sans garde in-flight** → deux `list()` racants peuvent laisser un snapshot ancien écraser le récent 1 round-trip (auto-guéri au poll 5s/60s). Reco : mirror le `_fetchInFlight` du tracker POS.

**[P3 · L5] `ProcessWebhookEventJob` DLQ re-drive sans claim lockForUpdate** → endpoint live + retry DLQ peuvent tous deux lire PENDING (double-apply NON prouvé : downstream idempotent sur `payment_status` + ancré sur la row WebhookEvent). Defense-en-profondeur. Reco : claim atomique PENDING→PROCESSING.

**[P3 · L3] StockService restock item-only** (early-return non-Item :266). Inert en V1 (extras/variations flag-managés → le toggle re-émet + supprime la row on_hand=0). Piège LATENT si un extra reçoit un jour un vrai comptage on_hand. Reco : garde/test avant tout comptage stock d'option.

## Risques RÉFUTÉS (vérifiés sains, preuve à l'appui)
- **Out-of-order delivery corrompt une surface** (question la + creusée, L5) → RÉFUTÉ. Les 6 consommateurs d'OrderStatusChanged traitent l'event comme un KICK de cache et re-fetchent l'état autoritaire ; le payload `new_status` ne mute jamais d'état durable. Backend monotone (OrderStateMachine + lockForUpdate). Le board ne peut PAS reculer.
- **Auto-prepare (mon P2 80ea49dee) double-transition / phantom** → RÉFUTÉ (L1). lockForUpdate + garde `status===ACCEPT` sérialisent contre le chef-tap ; émission unique, old_status correct.
- **Delivered row (broadcast_at set) re-diffusée / attempts<20 spin** → RÉFUTÉ (L4, prouvé). Lanes disjointes ; attempts monotone, stop à 20.
- **R1 / P1-6 contournables par route sœur** → RÉFUTÉ (L2, toutes routes tracées). Centralisés OrderService.
- **Zombie ACCEPT+UNPAID (3DS) réapparaît** → RÉFUTÉ (L2). payment_status monotone → l'accept est refusé pré-lock tant qu'UNPAID.
- **Vendre un extra/variation 86 (tout chemin : POS, kiosk, addon, bundle, champ non-lu)** → RÉFUTÉ (L3). SSOT → ChoiceAvailabilityResolver fail-close partout.
- **Channel auth cross-branche / cross-customer** → RÉFUTÉ (L5, 2 sentinels verts).
- **Prune supprime un non-livré** → RÉFUTÉ (L4, attempts<6 survit).

## Gates
Tous fichiers touchés verts (KdsAdvanceZombieFloor 2, MonitorCrashClaimed 6, AvailabilityService 12, SubmitRevalidates 2, MonitorDeadLetter 3, RescueStaleClaimed 7) + sweep board KDS/OSS vert. **Frozen 0.** Cœur SAIN confirmé par 5 lentilles indépendantes.
