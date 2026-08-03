# Double Audit — Plan Phase 2 en Deux Trains

Rapport audite : `reports/audit/PHASE2_PLAN_TRAINS_REWORKED_2026-04-27.md`  
TASK_ID : `PHASE2_PLAN_REWORK_TRAINS_2026-04-27`  
Mode : audit contradictoire interne, avant audit Claude  
Verdict : `DOUBLE_AUDIT_TRAINS_VERDICT: PASS_WITH_ONE_ALLOWLIST_AMENDMENT`

## 1. Conformite a la Demande Claude

| Exigence | Etat | Preuve / note |
| --- | --- | --- |
| Deux trains exclusifs | PASS | Train A V1 Release, Train B Phase 2 Enhancement. |
| Train A 4 missions | PASS | A.1 sentinels, A.2 quote subsystem, A.3 cycle/memory, A.4 D-M13. |
| Ordre A.1 -> A.2 -> A.3 -> A.4 strict | PASS | Queue explicite, pas de reordonnancement. |
| Train B renomme en `CV2-PH2-*` | PASS | 10 missions renommees. |
| Train B bloque tant que Train A non closed | PASS | Statut global + chaque mission. |
| D-M13 non attaque maintenant | PASS | A.4 blocked gates/preconditions. |
| Aucun patch produit | PASS | Rapport uniquement. |
| Aucun gate self-approve | PASS | Gates listes comme humains obligatoires. |
| HMAC fallback integre | PASS | A.2 patch obligatoire. |
| Microtime fallback detaille | PASS | 4 sites cites et strategie. |
| Phase A persistence front-loaded | PASS | A.1/A.2 avant A.3/A.4. |

## 2. Contradictions Detectees

### C1 — A.2 demande un test APP_KEY vide mais l'allowlist ne contient pas de test

Constat : la demande Claude dit :

- Allowlist A.2 : `OrderQuoteService.php`, `OrderQuote.php`, migration `order_quotes`.
- Validation A.2 : "APP_KEY vide test".

Probleme : un test automatise nouveau ne peut pas etre ajoute sans fichier test dans l'allowlist.

Decision du plan : `BLOCKED_ALLOWLIST_AMENDMENT` avant execution A.2, avec deux options :

- Ajouter `tests/Feature/OrderQuoteHmacKeyRequiredTest.php` a l'allowlist.
- Ou accepter une validation manuelle, mais ne pas pretendre que le test est automatise.

Verdict sur C1 : le plan ne triche pas ; il expose l'incoherence et la bloque avant code.

### C2 — A.1 "tracker sentinels" peut devenir trop large

Constat : le scope observe couvre au moins 31 chemins tests/quote/payment/sentinels. D'autres tests untracked existent hors ce sous-ensemble.

Risque : transformer A.1 en bucket massif Phase A.

Mitigation : A.1 est limite aux sentinels et helpers necessaires a prouver les corrections POS/Kiosk/quote/payment. Les autres untracked restent hors scope.

Verdict sur C2 : acceptable, car A.1 est un bucket fonctionnel, pas `git add -A`.

### C3 — A.3 gates humains avant cleanup peuvent bloquer la release

Constat : A.3 depend de `HG-ACTIVE-PRIMARY-SELECTION` et `HG-MEMORY-EPISODES-POLICY`.

Risque : bloquer une V1 techniquement prete pour une decision documentaire.

Mitigation : c'est voulu. Sans cycle primaire unique ni politique memory, les preuves de close peuvent disparaitre ou diverger.

Verdict sur C3 : maintenu.

### C4 — D-M13 pourrait etre techniquement resolu avant Phase A

Constat : Codex pourrait patcher migration/queue rapidement.

Risque : ajouter une migration critique sur un worktree non persistable.

Mitigation : A.4 reste apres A.1/A.2/A.3 et gates. C'est plus lent mais auditable.

Verdict sur C4 : maintenu.

## 3. Verification Invariants FoodKing

| Invariant | Verification dans le plan |
| --- | --- |
| Backend pricing SSOT | Aucune mission ne deplace le calcul prix. |
| `OrderStatus` enum | Train A ne touche pas status ; Train B Dashboard devra passer par services. |
| `branch_id` isolation | A.1 tracke sentinels branch/OSS/KDS/payment ; A.4 queue branch-scoped. |
| Dispatch after commit | Pas de changement events dans Train A hors D-M13 ; Train B garde outbox. |
| Symetrie `OrderService` / `FrontendOrderService` | A.4 traite les deux services et les 4 fallback sites. |
| Frozen zones | Aucune zone frozen modifiee dans le rework ; A.2/A.4 exigent gates/preconditions. |

## 4. Risques Restants

| Risque | Niveau | Action |
| --- | --- | --- |
| A.1 peut reveler un autre fail que D-M13 | P0 | Stop REWORK ; ne pas passer A.2. |
| A.2 APP_KEY test non tranche | P1 | Gate allowlist amendment avant `codex:complex`. |
| A.3 depend d'une signature humaine | P0 process | Ne pas bypass. |
| A.4 migration peut necessiter backfill historique | P0 | Preflight duplicates avant migration ; gate D-M13. |
| Train B peut redevenir trop large | P1 | Garder `BLOCKED_UNTIL_TRAIN_A_CLOSED`, une mission a la fois. |

## 5. Tests / Validations Documentaires

Ce double audit ne lance pas de suite produit, car aucun patch produit n'est applique.

Validations attendues sur ce rework :

```bash
bash .cursor/hooks/safety-check.sh
git diff --check -- reports/audit/PHASE2_PLAN_TRAINS_REWORKED_2026-04-27.md reports/audit/PHASE2_PLAN_DOUBLE_AUDIT_TRAINS_2026-04-27.md reports/post_execute_latest.log
```

## 6. Verdict

Le rework corrige le defaut strategique du plan precedent : il separe la release V1 courte du programme Phase 2 long-terme. Le seul point a corriger avant execution est l'allowlist A.2 pour rendre le test APP_KEY vide automatisable.

`DOUBLE_AUDIT_TRAINS_VERDICT: PASS_WITH_ONE_ALLOWLIST_AMENDMENT`

`NEXT_ACTION: audit Claude du plan trains, puis decision humaine sur l'allowlist A.2 et gates A.3.`
