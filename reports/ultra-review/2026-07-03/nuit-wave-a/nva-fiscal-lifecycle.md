# NVA — Fiscal Lifecycle Durability (NF525 long-term)

Cible: le fiscal tient-il 6 ans ? Rollover annuel, exhaustion, archivage, rétention.
HEAD cfc23966a. Live 127.0.0.1:8766 (foodking_e2e). READ-ONLY, 0 écriture.

## État live vérifié
- orders max fiscal_sequence_no (b1) = 2612 ; z_reports b1 = 24 (23 closed) ; audit_logs = 4781
- **z_reports.archived_at renseigné = 0** (sur 23 clôturés)

## RÉFUTÉ (preuves de robustesse — ne pas re-signaler)

1. **Exhaustion séquence = impossible.** `orders.fiscal_sequence_no` et `z_reports.sequence_no`
   sont `unsignedBigInteger` (migrations 000001:28, 000003:31) → max 1.8e19. À ~2600 tickets en
   plusieurs mois, l'exhaustion est astronomiquement hors d'atteinte. Non-borné pratiquement.

2. **Rollover 31/12→01/01 = correct, PAS un bug.** `FiscalSequenceService::next()` = MAX+1 par
   branche, **jamais de reset par année** (ligne 97-103). C'est conforme NF525 : la numérotation
   doit être *continue et sans rupture* ; un reset annuel casserait au contraire l'invariant
   gap-free. Le safety-net close 23:59 Paris (Kernel:401) + open 00:01 Paris (Kernel:446) traite
   la frontière d'année comme n'importe quelle frontière de jour. Les transitions DST (mars/oct)
   ne tombent jamais sur le 31/12. Fenêtre d'agrégat `(opened_at, closed_at]` half-open → 0
   double-compte à la frontière.

3. **Rétention 6 ans protégée du purge.** `StorageCleanupCommand` est scopé à
   `debugbar` / `laravel*.log` / `framework/cache` / `framework/views` UNIQUEMENT (lignes 57-61) ;
   il ne touche JAMAIS `storage/fiscal` ni `storage/backups` (docblock NF525 SAFETY ligne 25-31 +
   `PROTECTED_LOG_PREFIXES`). Backups DB = rétention quarterly 24q = 6 ans (RunDailyBackup).
   audit_logs / z_reports jamais purgés (aucune commande prune ne les cible — vérifié).

4. **Perf clôture à 5 ans = OK.** `verifyChain` recharge tous les Z clôturés à chaque open/close,
   mais ~2190 Z/branche sur 6 ans → recompute HMAC O(n) < 100 ms. La chaîne audit_logs utilise un
   **tail borné** (`FiscalChainValidator::verifyAuditChainTail`, fenêtre 500, DESC-limit puis
   ASC-walk) → pas de full-scan sous le lock, même à 10M lignes. Pas de mur de perf mono-box.

## FINDINGS

### F1 — P2 durabilité/NF525 : aucune clôture périodique (mensuelle/annuelle) ni Grand Total perpétuel
Le logiciel n'émet QUE des Z journaliers (open/close cycle). NF525 (BOI-TVA-DECLA-30-10-30) exige
des **données de clôture cumulatives par période** — journée, **mois**, **année/exercice** — plus
un **Grand Total perpétuel** (cumul depuis mise en service, jamais remis à zéro).
- Aucune commande month/annual/period/grand (`ls` + grep = néant).
- `ZReportService::aggregate` agrège seulement `(opened_at, closed_at]` d'un cycle.
- Aucune colonne « grand total perpétuel » sur z_reports ni ailleurs.
« Marche aujourd'hui » (le Z quotidien suffit à l'exploitation), mais **au 31/01 et au 31/12 il
n'existe aucun document de clôture mensuelle/annuelle** — bloquant pour une certif NF525 réelle.
Contexte owner = V1 LOCAL TPE simulé, cert différée → P2 (pas P0/P1), mais c'est la plus grosse
lacune du cycle de vie fiscal long-terme.
Fix proposé : commande `foodking:fiscal:close-period {branch} {--month|--year}` qui réutilise
`aggregate` sur la fenêtre calendaire + persiste un enregistrement de clôture périodique signé
(chaîné) + une valeur Grand Total cumulée ; planifier au 1er du mois / 1er janvier.

### F2 — P3 durabilité : la complétude de l'archivage est INOBSERVABLE (archived_at jamais écrit + pas de rattrapage + pas de verify)
Trois trous qui composent : sur le long terme on ne peut pas prouver que le fonds 6 ans est complet.
- `z_reports.archived_at` existe (migration 000003:58, modèle cast datetime) mais
  **`FiscalArchiveCommand` ne l'écrit jamais** (grep = NONE). Live : 23 Z clôturés, **0 archived_at**.
- La lane quotidienne (Kernel:339-369) archive **uniquement J-1** (`now()->subDay()`), **aucun
  rattrapage** : si la box est éteinte à 02:00, le zip de ce jour n'est jamais produit — le
  lendemain archive le nouveau J-1, pas le jour manqué.
- Aucune commande de **vérification de complétude** de l'archive (existe : verify-chain,
  assert-chain-clean, verify-z-membership — mais rien qui atteste « chaque jour a son zip »).
Conséquence : un zip manquant est **indétectable par requête** (il faut parser logs/FS). C'est
récupérable (DB retenue → re-run manuel `foodking:fiscal:archive branch --from=X --to=X`), mais
**silencieux** — exactement le mode d'échec qui pourrit un fonds d'archives sur 6 ans.
Fix proposé : (a) après `build()`, `UPDATE z_reports SET archived_at=now()` pour les Z de la
fenêtre ; (b) lane hebdo/mensuelle de rattrapage qui archive tout Z clôturé avec `archived_at IS
NULL` ; (c) alerte si un Z clôturé depuis > 48 h reste `archived_at IS NULL`.

## Verdict : IMPROVABLE
Le cœur (numérotation continue, chaîne HMAC, fenêtres half-open, rétention protégée du purge,
perf bornée) tient 6 ans. Les 2 lacunes concernent le **cycle de vie périodique** (clôtures
mensuelle/annuelle + Grand Total) et l'**observabilité de l'archivage**, pas la correctness
transactionnelle quotidienne.
