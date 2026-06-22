# Orchestrator Plan V1 POS + Kiosk Finalization - 2026-04-26

PLAN_VERDICT: EXECUTE_NO_GATE_REWORK_NOW
GLOBAL_TARGET: V1 fonctionnelle POS + Kiosk + KDS + outbox avec pricing backend SSOT, branch isolation stricte, quote HMAC, idempotency, payment safe, et release gates explicites.
HUMAN_GATE_BOUNDARY: l'orchestrateur ne signe pas D-M13, Phase A, memory policy, active primary, ni decisions de purge/commit.

## 1. Breakdown Orchestrateur

Le massive audit donne deux realites simultanees :

1. Le code local POS/Kiosk est deja largement stabilise : Vitest, Kiosk Vitest, Playwright, quote, loyalty, idempotency, outbox et branch sentinels passent.
2. La release V1 reste impossible tant que les garanties DB/gouvernance ne sont pas signees : queue_number sans unique DB, Phase A non close, quote subsystem untracked, cycles actifs contradictoires.

Le plan separe donc execution technique et gates humains. C'est volontaire : melanger une migration schema ou une purge de centaines de fichiers sans signature donnerait une illusion de vitesse et casserait l'auditabilite.

## 2. Rail A - Correctifs Executables Maintenant

| Ordre | Task | Risque vise | Fichiers | Sortie |
| --- | --- | --- | --- | --- |
| A1 | CV1-FINAL-QUOTE-AUTH-ACTIVE-MACHINE | Quote kiosk moins stricte que store kiosk | `app/Services/Order/OrderQuoteService.php`, test quote kiosk | Kiosk quote sans `kiosk:order` ou machine inactive => 403 |
| A2 | CV1-FINAL-QUOTE-TX-BOUNDARY | `lockForUpdate()` quote sans transaction sur endpoint quote | `app/Services/Order/OrderQuoteService.php` | quote/replay/consume restent verts; verrous effectifs partout |
| A3 | CV1-FINAL-KIOSK-VARIATION-VALIDATION | kiosk commit bypass FormRequest variation validation | `app/Http/Requests/OrderRequest.php`, test variation existant | variation min/max/repeat valide aussi au commit kiosk |
| A4 | CV1-FINAL-PAYMENT-TXN-REFERENCE-GUARD | transaction_no gateway reutilisable cross-order via service generique | `app/Services/PaymentService.php`, `tests/Feature/PaymentNoopIdempotencyTest.php` | meme transaction_no sur autre order => 422/ValidationException |
| A5 | CV1-FINAL-REVALIDATE | Non-regression | PHPUnit cible, Vitest kiosk si besoin | tous les tests cibles verts; full PHP reste bloque uniquement M-13 |

## 3. Rail B - Gates Non Auto-Signables

| Gate | Pourquoi bloque | Decision requise |
| --- | --- | --- |
| B1 D-M13 `(branch_id, queue_number)` | Migration schema + backfill historique + rollback prod | option index partial/full + locking strategy |
| B2 Phase A persistence | 140 modified/staged + 447 untracked | commit/discard/gitignore par bucket |
| B3 Quote subsystem persistence | `OrderQuote`, service et migration untracked | versionner via gate A.6/M-13 ou rollback |
| B4 Active primary unique | W10 + Caisse V1 actifs | archiver un cycle |
| B5 Memory policy | 27 JSONL untracked observes | versionner ou ignorer officiellement |
| B6 Legacy kiosk JS release strict | warnings sur `public/js/kiosk*.js` | shim signe ou purge |

## 4. Critique Du Plan Contre Lui-Meme

### Objection 1 - "Pourquoi ne pas implementer M-13 maintenant ?"

Reponse : M-13 touche l'historique de donnees, le backfill et le rollback. Sans decision humaine sur index partial/full et locking, une migration automatique peut bloquer prod ou casser des doublons historiques. Le bon comportement est de laisser la sentinelle rouge comme preuve de gate.

### Objection 2 - "Pourquoi toucher quote alors que le subsystem est untracked ?"

Reponse : le risque quote auth/transaction est no-gate et localise. Mais chaque changement doit rester minimal et documente. La release reste HOLD tant que le subsystem n'est pas versionne.

### Objection 3 - "Le check `kiosk:order` peut casser des tests qui utilisent actingAs sans token reel."

Reponse : l'enforcement doit viser les vrais tokens Sanctum en production. Le code doit refuser un token reel sans ability, tout en gardant les transient tokens de test compatibles. Les nouveaux sentinels doivent utiliser `Sanctum::actingAs(..., [])` pour prouver le rejet.

### Objection 4 - "PaymentService transaction_no guard sans unique DB n'est pas parfait."

Reponse : exact. Sans migration unique, ce n'est pas une garantie concurrente absolue. Mais c'est un durcissement applicatif no-gate utile maintenant; une contrainte DB peut etre decidee plus tard.

### Objection 5 - "Les warnings JS ne sont pas attaques."

Reponse : ils sont non-failing et majoritairement harnais/test-environment. Ils ne doivent pas passer devant auth/payment/quote. Ils restent en Rail B/F.1 release strict.

## 5. Execution Autorisee Maintenant

EXECUTE_NOW:
- A1
- A2
- A3
- A4
- A5

DO_NOT_EXECUTE_WITHOUT_HUMAN_GATE:
- B1
- B2
- B3 final persistence decision
- B4
- B5
- B6 release shim/purge decision

PLAN_SELF_AUDIT_VERDICT: PASS_WITH_HUMAN_GATES_EXPLICIT
