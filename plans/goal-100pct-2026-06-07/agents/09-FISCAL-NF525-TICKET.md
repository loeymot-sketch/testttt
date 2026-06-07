# AGENT 09 — FISCAL / NF525 / TICKET (le plus critique — vue CLIENT du ticket)
> Ton : inspecteur des impôts impitoyable. Un ticket non conforme = prison. Zéro approximation.

## Scope / Anchors (vérifiés)
- `Services/Receipt/ReceiptDataService.php` (header 6 champs), `OrderDetailsResource` (tax_lines)
- `ReceiptComponent.vue` (rendu ticket #print, 80mm, lignes 67-234), `posReceiptBuilder.js`, `kioskPrinter.js`
- `Services/Hardware/EscPosCommandBuilder.php` + `EscPosPrinterService.php` (primitives + transport + testPrint SEULEMENT)
- `Services/Fiscal/*` (FROZEN) : ZReportService, FiscalSequenceService, AuditLogService, X/Z report
- `branches` : siret/vat_intra/legal_footer

## Checklist abusif (cible §4 — fiscal cluster prioritaire)
- **TICKET CONTENU** (les 2 origines POS+Borne, capturé visuel via #print + emulateMedia print) : SIRET ✅, TVA intra ✅, opérateur=caissier (≠ Client passage) ✅, N° fiscal ✅, lignes HT+désignation+qté, **ventilation TVA par taux** (tax_lines), totaux HT/TVA/TTC, tendered breakdown, footer légal.
- **G3 LEGAL config** : ❌ SIRET/TVA/footer NULL sur DBs réelles + pas de cmd `set-branch-legal` → créer le moyen de set par device (seeder/cmd/admin) + documenter G4.
- **FOOTER** (G3 owner) : VAT-registered → footer NE DOIT PAS dire "TVA non applicable art.293B". Mettre la mention correcte.
- **6 items tax_id NULL** : corriger (assigner taux) — sinon ticket sans ligne TVA pour ces items.
- **🔥 AUTO-PRINT SERVEUR (P0, chemin owner)** : `EscPosCommandBuilder`+`EscPosPrinterService` = primitives+transport+testPrint seulement. Le renderer ticket-commande complet + outbox print_jobs + agent Node sont sur **`feat/pos-printer-saga-autoprint` (commit e446a2084) NON MERGÉ**. → **merger + re-valider** (G5 owner sign-off) : rendu ESC/POS d'une vraie commande, enqueue post-encaissement, claim atomique 1-impression, payload correct, drawer-kick. Valider en sim (NullPrinterTransport/bypass) → sur matériel, seule l'impression papier reste.
- **Z-REPORT / CLÔTURE** : ouvrir/clôturer Z du jour (`fiscal:*`), PDF clôture = doc fiscal imprimé, chaîne HMAC chaînée, X-report intermédiaire.
- **NON-CASH ticket** : CARD/TR/Mobile → ticket affiche le bon mode + ref ; refund → marqueur remboursement ; reprint → duplicata marqueur.
- **Séquence** : allocation kiosk-paid=création, POS-cash=clôture ; gap-free ; pas de crash si alloc fail (flag+retry).

## Méthode
Données via tinker `OrderDetailsResource` (APP_ENV=e2e DB=foodking_e2e) + rendu via `ReceiptComponent` (emulateMedia print). Frozen fiscal services = audit only ; print-saga merge = via lock-plan si touche frozen + owner gate.

## PASS bar
Ticket 100% conforme (les 2 origines, capturé+analysé) + auto-print serveur mergé+prouvé sim + Z-report + non-CASH ticket + 0 item sans TVA. Sinon ❌.

## Sortie `reports/test-e2e/goal-100pct-2026-06-07/<round>/09-fiscal-ticket.json` + captures ticket.
