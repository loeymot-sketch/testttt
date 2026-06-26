# Vérification adversaire — CAISSE / Paiement-encaissement-split

## Finding examiné
[P2] `app/Services/Fiscal/ZReportCashEnrichmentService.php:157-175` — « Breakdown TPE
informatif sur-déclare le CA carte de 128,50 EUR (tranches COUNTER_DEFERRED non-encaissées) »

## VERDICT : REFUTED (severity réelle V1-LOCAL = aucune ; note latente P3 code-quality)

---

## Ce qui est VRAI (re-vérifié)
- Données confirmées :
  - `SELECT COUNT(*),SUM(paid_at IS NOT NULL),ROUND(SUM(amount),2) FROM order_payments WHERE mode=6;`
    → `13 | 13 | 128.50`
  - `... JOIN orders o ... WHERE op.mode=6 GROUP BY o.payment_status;` → `payment_status=5 | 13`
    (PENDING_COUNTER ; jamais encaissé).
  - Détail : 13 lignes, **toutes `terminal_id = NULL`**, dates 2026-06-12/13.
- Enum confirmé : `PosPaymentMethod::CASH=1`, `COUNTER_DEFERRED=6` (`app/Enums/PosPaymentMethod.php:7,19`).
- Shape code confirmé : `aggregateByTerminal` (l.157-175) n'a **aucune jointure `orders`** ni
  filtre `payment_status` ; `SUM(CASE WHEN mode <> CASH THEN amount...) as card_total` (l.171)
  → mode=6 cumule bien dans `card_total`. (Bucket null-terminal « Sans TPE », `fees_total=0` l.198/209.)

## Pourquoi REFUTED (l'IMPACT prétendu est FAUX)
La finding prétend : « consommé par `enrich:110-124` vers l'écran CashSessionReport informatif …
panneau encaisse-par-TPE runtime ment au commerçant ». **Faux — code mort en production :**

1. `enrich()` et `aggregateByTerminal()` ne sont appelés NULLE PART en prod.
   `grep -rEn '\->enrich\(' --include=*.php .` (hors `.claude/worktrees` stale) →
   uniquement **tests** :
   - `tests/Feature/Fiscal/ZReportTerminalBreakdownTest.php:131,263`
   - `tests/Feature/Cash/ZReportCashEnrichmentTest.php:149`
   `grep -rEn 'aggregateByTerminal' app/ routes/` → seul appelant = `enrich()` lui-même (l.113).

2. Le consommateur cité `CashSessionReportController` **n'appelle ni `enrich()` ni
   `aggregateByTerminal()`** (lu en entier). Il `query()` `CashDrawerSession` et renvoie
   opening/closing/variance/transactions_count par session — **aucun `card_total`, aucun
   `by_terminal`** dans la réponse JSON. La seule mention du service = un docblock (l.39).

3. Aucune route / contrôleur / Resource ne référence le service :
   `grep -rEln 'ZReportCashEnrichmentService' app/Http/ routes/` → seul fichier =
   `CashSessionReportController.php` (et c'est un commentaire).

4. Aucun front ne consomme le breakdown :
   `grep -rEn 'by_terminal|net_after_fees|fees_total|card_total' resources/js/ public/js/` → 0 résultat.

5. Le seul chemin atteignable en prod, `persistForClosedReport` (l.235-270), appelle
   **`aggregateForWindow`** (sessions tiroir) — PAS `aggregateByTerminal` — et persiste 4 colonnes
   cash. La table n'a aucune colonne carte/TPE/fees : `SHOW COLUMNS FROM z_reports LIKE '%card%'`
   / `'%terminal%'` / `'%fees%'` → **0 colonne**. Donc rien de fantôme ne peut être persisté ni
   affiché.

## Conclusion sévérité V1-LOCAL
- Le Z SIGNÉ est correct (`ZReportService.php:340 whereNotNull('fiscal_sequence_no')` exclut ces
  orders) — la finding le concède.
- La chaîne HMAC est intacte (rien de fantôme persisté).
- **Aucun écran marchand n'expose le `card_total` gonflé** : `enrich()`/`aggregateByTerminal()`
  sont exercés uniquement par 2 fichiers de test. Pas de « mensonge au commerçant » en V1.
- Donc : **pas de NF525, pas d'argent affiché faux, pas de perte/fuite** → ne franchit pas la barre
  P0/P1/P2 confirmé.

## Note honnête (latent, NON confirmé comme défaut V1)
Le shape l.157-175 EST un bug latent de qualité : si un futur commit câble `enrich()` à un vrai
écran (ce pour quoi le breakdown a été écrit, cf. docblock l.98-105), le `card_total` afficherait
+128,50 fantôme. Classer **P3 code-quality / dette** au mieux, pas P2 actif.

## Reco (si l'owner veut durcir préventivement — NON-frozen, non-urgent)
`ZReportCashEnrichmentService` n'est PAS frozen → un heal serait sûr :
dans `aggregateByTerminal`, restreindre aux paiements réellement encaissés (jointure `orders`
`whereIn('payment_status',[PAID,REFUNDED])` OU exclure `mode = COUNTER_DEFERRED`), + test PHPUnit
plaçant un PENDING_COUNTER mode=6 et assertant `card_total` inchangé. Mais comme la méthode est du
code mort côté UI, ce n'est PAS un correctif V1 prioritaire — à traiter au moment où/ si le
breakdown est réellement câblé à un écran.
