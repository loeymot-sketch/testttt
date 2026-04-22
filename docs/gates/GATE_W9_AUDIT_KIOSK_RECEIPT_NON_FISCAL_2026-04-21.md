# GATE W9-AUDIT — Statut fiscal du ticket borne (Kiosk)

**Date :** 2026-04-21
**Auteur :** Audit global W1–W9 (cycle W9-AUDIT)
**Statut :** **DÉCISION ACTÉE — Ticket Kiosk = preuve commerciale, NON document fiscal NF525**
**Périmètre :** France métropolitaine, FoodKing SaaS multi-tenant
**Référence amont :** `GATE_P_MEGA_22_NF525_READINESS_2026-04-20.md` (D8/D10) ; `reports/audit/AUDIT_W1_W9_GLOBAL_2026-04-21.md`

---

## 1. Contexte

L'audit transverse des 9 vagues a mis en évidence une **divergence intentionnelle non
documentée** entre le ticket POS (`resources/js/components/admin/pos/ReceiptComponent.vue`)
et le ticket Kiosk (`resources/js/components/frontend/kiosk/KioskConfirmationComponent.vue`) :

| Caractéristique NF525           | POS (caissier)             | Kiosk (borne)            |
| ------------------------------- | -------------------------- | ------------------------ |
| `receipt_print_count` colonne   | OUI (W8.C-P3 / W9.B)       | NON                      |
| Endpoint `POST .../print-receipt` | OUI (W9.B/W9.D)          | NON                      |
| Audit chaîné `pos.receipt.*`    | OUI (W9.B)                 | NON                      |
| Marqueur `<receipt-duplicata-marker>` | OUI                  | NON                      |
| Mentions légales (SIRET, VAT, register_id, operator) | OUI       | NON                      |

Sans décision écrite, cette asymétrie peut être interprétée comme un **gap de
conformité** lors d'un audit DGFiP, ou inversement comme un **scope-creep injustifié**
à implémenter.

## 2. Décision actée

> Le ticket imprimé par la borne Kiosk (`KioskConfirmationComponent.vue`,
> ESC/POS via `kioskPrinter.js`) est un **reçu commercial de courtoisie**, **PAS
> un ticket de caisse au sens NF525**.

### Justification

1. **Acte de vente fiscal** = encaissement validé en caisse (TPE / espèces / TR)
   par un opérateur identifié, finalisé côté serveur via `OrderService::pay` et
   signé dans `audit_logs` par la chaîne POS. La **trace fiscale primaire** est
   le **Z report** (clos quotidiennement, archivé J+1 via `fiscal:archive` à 02:00).

2. **Ticket POS = document fiscal officiel**, contient SIRET / VAT / register_id /
   operator / DUPLICATA si réimpression. C'est lui qui peut être réimprimé sur
   demande client / inspecteur.

3. **Reçu Kiosk = proof of order**, contenant numéro de file d'attente, items,
   total — sans valeur fiscale autonome. La commande Kiosk reste tracée au même
   titre dans `frontend_orders` + `Z reports` agrégés, donc **aucune perte
   d'évidence** au niveau preuve consolidée.

4. **Pas de ré-impression auto-déclenchée** côté borne (la borne n'imprime
   qu'au moment de la confirmation du paiement, jamais a posteriori). Le risque
   de DUPLICATA non tracé est donc **structurellement nul** côté Kiosk.

## 3. Conséquences techniques actées

| Élément                                   | Décision                                                    |
| ----------------------------------------- | ----------------------------------------------------------- |
| `receipt_print_count` sur `frontend_orders` | **NON ajouté** (pas de migration)                         |
| Endpoint `kiosk.receipt.print`            | **NON créé**                                                |
| Audit `kiosk.receipt.*` dans audit_logs   | **NON émis**                                                |
| Mentions SIRET/VAT/operator dans `KioskConfirmationComponent.vue` | **NON affichées** (laisser tel quel) |
| `<receipt-duplicata-marker>` dans Kiosk   | **NON ajouté**                                              |
| Trade-off accepté                          | Si un jour un client métier demande de pouvoir réimprimer un reçu Kiosk depuis l'interface admin, ce sera fait via le **flux POS** (admin POS rouvre la commande Kiosk et imprime via `ReceiptComponent.vue` → audit chaîné émis correctement). |

## 4. Conditions de réouverture (re-évaluation requise)

Cette décision DOIT être ré-examinée si **l'un** des éléments suivants survient :

- Évolution réglementaire DGFiP imposant un ticket fiscal complet pour chaque
  point de vente automatique (borne self-service).
- Client souhaitant utiliser la borne en mode **caisse autonome non assistée**
  (sans poste POS adossé).
- Demande métier de réimprimer manuellement un reçu Kiosk depuis l'interface
  client (compte web), ce qui imposerait un comptage NF525.

Dans tous ces cas, ouvrir un cycle dédié (W10+) avec :
- Ajout migration `frontend_orders.receipt_print_count`.
- Endpoint `POST /api/frontend/orders/{id}/print-receipt` côté Kiosk.
- Audit `kiosk.receipt.print` / `kiosk.receipt.reprint` (chaîne séparée OU
  mutualisée selon décision NF525).
- DUPLICATA marker dans `KioskConfirmationComponent.vue`.

## 5. Liens

- Audit global : `reports/audit/AUDIT_W1_W9_GLOBAL_2026-04-21.md`
- Décision parente : `docs/gates/GATE_P_MEGA_22_NF525_READINESS_2026-04-20.md` (D10)
- Implémentation POS : `app/Http/Controllers/Admin/Pos/PosReceiptPrintController.php`
- Composant Kiosk : `resources/js/components/frontend/kiosk/KioskConfirmationComponent.vue`
- ZReport pipeline : `app/Services/Fiscal/ZReportService.php`
