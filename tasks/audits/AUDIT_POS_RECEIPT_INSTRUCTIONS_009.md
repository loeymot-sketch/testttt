# AUDIT_POS_RECEIPT_INSTRUCTIONS_009 — Ticket de caisse, impression, notes cuisine

## Meta
- **Priority** : P2
- **PRIMARY_MODEL** : Claude
- **TEST_STRATEGY** : `static-inspection`
- **DEPENDS_ON** : AUDIT_POS_ORDER_CREATION_001, AUDIT_POS_STATUS_TRANSITIONS_003
- **Estimation** : 0.5 j-h
- **Vague** : A9

## Contexte

Le ticket client + ticket cuisine (KDS doublon papier si prévu) doivent contenir l'information exhaustive : items, variations, addons, notes, TVA ventilée, moyen de paiement. Risques : notes cuisine perdues entre POS → KDS ; ticket sans TVA légale ; impression silencieusement échouée (pas de monitoring).

## Questions d'audit

1. Le template ticket (blade ou ESC/POS) inclut-il : numéro file, date, branche, items + options, sous-total, TVA par taux, total, paiement ?
2. Les notes cuisine par item sont-elles transmises au KDS (via DomainEvent ou via relation OrderItem) ?
3. L'impression passe-t-elle par `window.borne.print()` ou une API serveur ? Quel fallback si imprimante HS ?
4. Les échecs d'impression sont-ils loggés (PaymentLog / PrintLog) ?
5. Le ticket peut-il être réimprimé depuis l'historique POS ?
6. La TVA est-elle décomposée par taux (obligation légale FR) ?
7. Le numéro de file `queue_number` est-il imprimé clairement pour l'OSS (order status screen) ?
8. Les instructions globales ("sans oignon") vs par-item ("sauce à part") sont-elles séparées visuellement ?
9. L'impression KDS (si ticket papier en complément de l'écran) respecte-t-elle l'ordre de préparation ?
10. Le duplicata (ticket cuisine + ticket client) génère-t-il un seul event ou deux ?

## Scope

### SUBSYSTEMS_TOUCHED
- Templates tickets : `resources/views/*receipt*` ou `resources/js/components/admin/pos/*Receipt*`
- `resources/js/services/printService.js` ou equiv
- Hardware bridge Electron (partagé avec kiosk)
- `app/Models/Order.php` champs `notes`, `kitchen_notes`

### SUBSYSTEMS_OFF_LIMITS
- Drawer / TPE (audits dédiés ou C6 partiellement)

## Invariants at Risk
- [ ] Aucun invariant majeur (audit qualité/conformité)

## Fichiers à lire
1. Templates ticket (`grep -rn receipt`)
2. `resources/js/services/print*`
3. `app/Models/Order.php`, `OrderItem.php` (colonnes notes)
4. `docs/BUSINESS_RULES.md` section ticket / TVA

## Grep patterns

```
grep -rn "receipt\|ticket\|Receipt" resources/js/ resources/views/
grep -rn "print\|Print" resources/js/services/
grep -rn "kitchen_notes\|special_instructions\|notes" app/Models/Order*.php
grep -rn "window.borne" resources/js/
grep -rn "tva\|vat\|tax_breakdown" resources/
```

## Evidence required
- Extrait template ticket.
- Liste des champs imprimés vs obligations légales.
- Comportement en cas d'échec imprimante.

## Grille de verdict
- **PASS** : template complet, fallback existe, logs échec, TVA décomposée.
- **WARN** : TVA ventilée OK mais pas de log d'échec impression.
- **BLOCKED** : TVA non décomposée, notes cuisine perdues, aucun fallback.

## Livrable
`reports/review/AUDIT_POS_RECEIPT_INSTRUCTIONS_009_<DATE>.md`

## Status
- [x] Brief rédigé
- [ ] Plan approuvé
- [ ] Audit exécuté
- [ ] Rapport
- [ ] Tasks correctrices
- [ ] Closed
