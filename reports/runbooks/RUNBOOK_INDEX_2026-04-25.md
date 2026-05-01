# RUNBOOKS CAISSE V1 — INDEX (2026-04-25)

## 0. Statut
- INDEX_STATUS: DRAFT_SKELETON
- Source: MASTERPLAY M-20 / PLAN-20 documentation and runbook skeleton
- Last reviewed: 2026-04-25 (initial skeleton)
- Scope: index des 8 runbooks ops Caisse V1 créés sous `reports/runbooks/`.
- Gates: aucune signature, aucune décision GO/NO-GO dans cet index.

## 1. Carte de décision
| Symptôme initial | Runbook | First responder | Severity ceiling |
| --- | --- | --- | --- |
| TPE CB/TR timeout, 401/403/422 payment confirm, preuve terminal sans commande cuisine | `RUNBOOK_TPE_FAILURE_2026-04-25.md` | BE+FE | P0 |
| Ticket ESC/POS absent, TCP printer failed, tiroir caisse bloqué | `RUNBOOK_PRINTER_FAILURE_2026-04-25.md` | Ops | P0 |
| Kiosk affiche `offline_`, polling suspendu, réseau perdu, replay en attente | `RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md` | BE+FE | P0 |
| Workers queue/backlog/failed jobs, KDS/POS ne reçoivent plus events | `RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md` | DevOps | P0 |
| DomainEvent stale/failed, outbox rescue/retry nécessaire | `RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md` | BE+DevOps | P0 |
| Séquence fiscale, Z/X report, archive NF525 ou audit chain suspecte | `RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md` | NF525-QA | P0 |
| Deux écrans KDS divergent, 409 optimistic lock, bump concurrent | `RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md` | BE+FE | P0 |
| Canary predicate franchi, preflight CRITICAL, besoin extinction flags | `RUNBOOK_ROLLBACK_CANARY_2026-04-25.md` | DevOps | P0 |

## 2. Liens transverses
| Runbook | Plans liés | Gates liés | Métriques clés |
| --- | --- | --- | --- |
| TPE failure | PLAN-06, PLAN-20, M-20 | GATE_PAYMENT_LEDGER_V1, GATE_VERIFY_P0_FROZEN_CONSOLIDATED_2026-04-20 | taux confirm backend, 401/403/422, timeout TPE |
| Printer failure | PLAN-16, PLAN-20, M-20 | (none) | print failed, TCP open failed, tiroir 422 |
| Kiosk network loss | PLAN-11, PLAN-20, M-20 | GATE_OFFLINE_SCOPE_V1 | `offline_`, polling failures, kiosk-event |
| Dispatch queue saturated | PLAN-14, PLAN-20, M-20 | (none) | process manager status, queue failed, dispatch latency p95 |
| Outbox blocked | PLAN-14, PLAN-20, M-20 | (none) | DomainEvent stale, attempts, recent_failures |
| Fiscal sequence break | PLAN-08, PLAN-13, PLAN-20, M-20 | GATE_FISCAL_KIOSK_V1, GATE_SCHEMA_MIGRATIONS_V1 | fiscal lock, Z chain, archive verify, preflight fiscal |
| KDS multi-screen desync | PLAN-07, PLAN-20, M-20 | GATE_KDS_BUMP_V1 | KDS 409, cap 50, transition count |
| Rollback canary | PLAN-15, PLAN-20, M-20 | GATE_GO_NO_GO_CAISSE_V1 + gates feature | payment_success_rate, fiscal_anomaly, kds_error_rate, preflight |

## 3. Procédure d'usage
1. Réception alerte: classer par symptôme initial, `branch_id`, heure UTC, surface touchée.
2. Choix runbook: ouvrir une seule fiche, ne pas mélanger migration M-13 et rollback canary M-15.
3. Diagnostic: exécuter les étapes dans l’ordre et ne citer que les file:line du runbook.
4. Action: appliquer P0/P1/P2 selon criticité, escalader immédiatement si gate/fiscal/frozen zone.
5. Post-mortem: remplir le template du runbook, joindre preuves, owner, deadline, liens incidents passés.
- Règle commune: aucun runbook ne signe un gate, ne modifie code produit, ou ne contourne `branch_id`.
- Règle commune: backend pricing SSOT; les montants frontend ne sont jamais preuve de règlement serveur.
- Règle commune: `OrderStatus enum` reste autoritaire; les statuts numériques existants ne deviennent pas nouveau contrat ops.
- Règle commune: dispatch after commit; relances outbox uniquement via commandes existantes citées.
- Règle commune: frozen zones non éditées depuis M-20.

## 4. Maintenance
- Owner maintenance (DRAFT): DevOps + BE + NF525-QA selon runbook.
- Cadence: revue à chaque changement de file:line cité, et avant toute signature GO/NO-GO Caisse V1.
- Déclencheur obligatoire: si un chemin ou une ligne citée change, mettre à jour le runbook concerné dans le même cycle que le changement.
- Déclencheur obligatoire: si M-13 publie runbooks migrations, vérifier `RUNBOOK_ROLLBACK_CANARY_2026-04-25.md` pour éviter duplication.
- Déclencheur obligatoire: si M-15 crée l’emplacement réel des flags, remplacer la mention “à créer” par file:line réel après audit.
- Déclencheur obligatoire: si un gate passe de `TO_DRAFT` à signé hors runbook, mettre à jour `Linked gates` sans cocher ici.
- Contrôle qualité: H1 unique, sections H2 exactes, date figée 2026-04-25, fin de fichier newline.
- Contrôle qualité: aucun code produit, aucune commande inventée, aucune écriture hors `reports/runbooks/`.
- Contrôle qualité: chaque diagnostic step garde au moins un file:line réel.
- Contrôle qualité: index reste 80-150 lignes; si plus, scinder en matrice dédiée après gate documentaire.
- Maintenance TPE: revoir `RUNBOOK_TPE_FAILURE_2026-04-25.md` si monitoring, gate, ou file:line associé évolue.
- Maintenance Printer: revoir `RUNBOOK_PRINTER_FAILURE_2026-04-25.md` si monitoring, gate, ou file:line associé évolue.
- Maintenance Kiosk network: revoir `RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md` si monitoring, gate, ou file:line associé évolue.
- Maintenance Dispatch queue: revoir `RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md` si monitoring, gate, ou file:line associé évolue.
- Maintenance Outbox: revoir `RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md` si monitoring, gate, ou file:line associé évolue.
- Maintenance Fiscal: revoir `RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md` si monitoring, gate, ou file:line associé évolue.
- Maintenance KDS: revoir `RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md` si monitoring, gate, ou file:line associé évolue.
- Maintenance Rollback: revoir `RUNBOOK_ROLLBACK_CANARY_2026-04-25.md` si monitoring, gate, ou file:line associé évolue.
- Maintenance guard: Aucune signature humaine ne doit être ajoutée dans ces squelettes.
- Maintenance guard: Les incidents P0 doivent rester `blocked > silently dangerous`.
- Maintenance guard: Les preuves doivent rester sans secrets, sans PII, et avec horodatage UTC.
- Maintenance guard: Les actions L3/L4 doivent être copiées dans le ticket incident, pas seulement dans chat.
- Maintenance guard: Les seuils canary ne sont pas redéfinis par M-20.
- Maintenance guard: Les commandes artisan citées doivent rester disponibles avant signature ops.
- Maintenance guard: Les références aux plans restent PLAN-20/M-20 sauf section transverse explicite.
- Maintenance guard: Les runbooks ne remplacent pas l’audit Claude terminal ni GPT final audit.
- Maintenance guard: Les modifications futures doivent conserver `DRAFT_SKELETON_*` tant que non signées.
- Maintenance guard: Les runbooks doivent rester dans `reports/runbooks/` pendant cette mission.
- Maintenance guard: Tout ajout de runbook hors ces 8 cas doit passer par un plan ou une mission dédiée.
- Maintenance guard: Un changement de gate référencé doit mettre à jour uniquement la ligne `Linked gates` du runbook concerné.
- Maintenance guard: Un changement de métrique doit garder le même principe de preuve machine-détectable.
- Maintenance guard: Un runbook incident ne doit jamais devenir une procédure de correction de données brute.
- Maintenance guard: Les procédures NF525 restent soumises à escalade humaine avant toute action irréversible.
- Maintenance guard: Les procédures rollback migrations restent réservées à M-13 et ne sont pas reprises ici.
- Maintenance guard: Toute nouvelle commande citée doit exister dans le dépôt avant d’être documentée.
- Maintenance guard: Toute référence `file:line` périmée bloque la signature du runbook concerné.
